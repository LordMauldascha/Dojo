<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Project;
use App\Entity\Run;
use App\Entity\Skill;
use App\Entity\Testcase;
use App\Security\UserRole;
use App\Tests\Support\AppWebTestCase;

/** A run's skills/testcases must belong to the run's project and not be deleted; old versions stay untouched. */
final class RunPruningTest extends AppWebTestCase
{
    public function testUserRemovingASkillFromOneProjectOnlyPrunesThatProjectsRuns(): void
    {
        $user = $this->persistUser('alice');
        $projectA = $this->persistProject('Project A', [$user]);
        $projectB = $this->persistProject('Project B', [$user]);
        $skill = $this->persistSkill('Review', [$projectA, $projectB]);
        $this->persistRun($projectA, 'Run A', [$skill]);
        $this->persistRun($projectB, 'Run B', [$skill]);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/skills/'.$skill->getUuid());
        $this->submit($crawler, 'Save projects', 'skill_projects', ['projects' => [(string) $projectB->getId()]]);

        self::assertResponseRedirects('/skills/'.$skill->getUuid());
        self::assertSame([], $this->skillTitles('Run A'));
        self::assertSame(['Review'], $this->skillTitles('Run B'));
    }

    public function testDeletingASkillRemovesItFromAllRuns(): void
    {
        $admin = $this->persistUser('root', UserRole::Admin);
        $projectA = $this->persistProject('Project A');
        $projectB = $this->persistProject('Project B');
        $skill = $this->persistSkill('Review', [$projectA, $projectB]);
        $other = $this->persistSkill('Other', [$projectA]);
        $this->persistRun($projectA, 'Run A', [$skill, $other]);
        $this->persistRun($projectB, 'Run B', [$skill]);
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/skills/'.$skill->getUuid());
        $this->client->submit($crawler->filter('form[action$="/delete"]')->form());

        self::assertResponseRedirects('/skills');
        self::assertSame(['Other'], $this->skillTitles('Run A'));
        self::assertSame([], $this->skillTitles('Run B'));
    }

    public function testMovingATestcaseToAnotherProjectRemovesItFromTheOldProjectsRuns(): void
    {
        $user = $this->persistUser('alice');
        $projectA = $this->persistProject('Project A', [$user]);
        $projectB = $this->persistProject('Project B', [$user]);
        $testcase = $this->persistTestcase('Greeting', $projectA);
        $kept = $this->persistTestcase('Kept', $projectA);
        $this->persistRun($projectA, 'Run A', [], [$testcase, $kept]);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/testcases/'.$testcase->getUuid());
        $this->submit($crawler, 'Save projects', 'testcase_projects', ['projects' => [(string) $projectB->getId()]]);

        self::assertResponseRedirects('/testcases/'.$testcase->getUuid());
        self::assertSame(['Kept'], $this->testcaseTitles('Run A'));
    }

    public function testRemovingOneOfSeveralProjectsOnlyPrunesThatProjectsRuns(): void
    {
        $user = $this->persistUser('alice');
        $projectA = $this->persistProject('Project A', [$user]);
        $projectB = $this->persistProject('Project B', [$user]);
        $testcase = $this->persistTestcase('Greeting', [$projectA, $projectB]);
        $this->persistRun($projectA, 'Run A', [], [$testcase]);
        $this->persistRun($projectB, 'Run B', [], [$testcase]);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/testcases/'.$testcase->getUuid());
        $this->submit($crawler, 'Save projects', 'testcase_projects', ['projects' => [(string) $projectB->getId()]]);

        self::assertResponseRedirects('/testcases/'.$testcase->getUuid());
        self::assertSame([], $this->testcaseTitles('Run A'));
        self::assertSame(['Greeting'], $this->testcaseTitles('Run B'));
    }

    public function testAdminClearingATestcasesProjectRemovesItFromRuns(): void
    {
        $admin = $this->persistUser('root', UserRole::Admin);
        $project = $this->persistProject('Project A');
        $testcase = $this->persistTestcase('Greeting', $project);
        $this->persistRun($project, 'Run A', [], [$testcase]);
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/testcases/'.$testcase->getUuid());
        $this->submit($crawler, 'Save projects', 'testcase_projects', ['projects' => []]);

        self::assertResponseRedirects('/testcases/'.$testcase->getUuid());
        self::assertSame([], $this->testcaseTitles('Run A'));
    }

    public function testDeletingATestcaseRemovesItFromRuns(): void
    {
        $admin = $this->persistUser('root', UserRole::Admin);
        $project = $this->persistProject('Project A');
        $testcase = $this->persistTestcase('Greeting', $project);
        $kept = $this->persistTestcase('Kept', $project);
        $this->persistRun($project, 'Run A', [], [$testcase, $kept]);
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/testcases/'.$testcase->getUuid());
        $this->client->submit($crawler->filter('form[action$="/delete"]')->form());

        self::assertResponseRedirects('/testcases');
        self::assertSame(['Kept'], $this->testcaseTitles('Run A'));
    }

    public function testOldVersionsKeepTheSnapshotAndCompareMarksDeletedSkills(): void
    {
        $admin = $this->persistUser('root', UserRole::Admin);
        $project = $this->persistProject('Project A');
        $skill = $this->persistSkill('Review', [$project]);
        $testcase = $this->persistTestcase('Greeting', $project);
        $run = $this->persistRun($project, 'Run A', [$skill], [$testcase], true);
        $run->start();
        $this->entityManager->flush();
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/skills/'.$skill->getUuid());
        $this->client->submit($crawler->filter('form[action$="/delete"]')->form());
        self::assertSame([], $this->skillTitles('Run A'));

        $crawler = $this->client->request('GET', '/runs/'.$run->getUuid());
        $this->client->submit($crawler->filter('form[action$="/start"]')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Started as version 2.');

        $this->client->request('GET', '/runs/'.$run->getUuid().'/versions/1');
        self::assertSelectorTextContains('body', 'Review (version 1) (deleted)');
        $this->client->request('GET', '/runs/'.$run->getUuid().'/versions/2');
        self::assertSelectorTextNotContains('body', 'Review');

        $this->client->request('GET', '/runs/'.$run->getUuid().'/compare');
        self::assertSelectorTextContains('body', 'Removed: Review (version 1) (deleted)');
    }

    /** @return list<string> */
    private function skillTitles(string $runTitle): array
    {
        $this->entityManager->clear();
        $run = $this->entityManager->getRepository(Run::class)->findOneBy(['title' => $runTitle]);
        self::assertInstanceOf(Run::class, $run);

        return array_values($run->getSkills()->map(static fn (Skill $skill): string => $skill->getActiveVersion()->getTitle())->toArray());
    }

    /** @return list<string> */
    private function testcaseTitles(string $runTitle): array
    {
        $this->entityManager->clear();
        $run = $this->entityManager->getRepository(Run::class)->findOneBy(['title' => $runTitle]);
        self::assertInstanceOf(Run::class, $run);

        return array_values($run->getTestcases()->map(static fn (Testcase $testcase): string => $testcase->getActiveVersion()->getTitle())->toArray());
    }
}
