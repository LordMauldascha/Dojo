<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Ai\LlmConfiguration;
use App\Ai\Provider;
use App\Entity\Project;
use App\Entity\Run;
use App\Entity\Skill;
use App\Entity\Testcase;
use App\Entity\User;
use App\Security\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/** A run's skills/testcases must belong to the run's project and not be deleted; old versions stay untouched. */
final class RunPruningTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        foreach ([Run::class, Testcase::class, Skill::class, Project::class, User::class] as $class) {
            $this->entityManager->createQuery('DELETE FROM '.$class)->execute();
        }
    }

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

    public function testAdminRemovingASkillOnTheProjectDetailPrunesTheProjectsRuns(): void
    {
        $admin = $this->persistUser('root', UserRole::Admin);
        $projectA = $this->persistProject('Project A');
        $projectB = $this->persistProject('Project B');
        $skill = $this->persistSkill('Review', [$projectA, $projectB]);
        $this->persistRun($projectA, 'Run A', [$skill]);
        $this->persistRun($projectB, 'Run B', [$skill]);
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/projects/'.$projectA->getUuid());
        $this->client->submit($crawler->filter('form[action*="/skills/"]')->form());

        self::assertResponseRedirects('/projects/'.$projectA->getUuid());
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

        $crawler = $this->client->request('GET', '/skills/'.$skill->getUuid().'/delete');
        $this->client->submit($crawler->selectButton('Delete permanently')->form());

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
        $this->submit($crawler, 'Save project', 'testcase_project', ['project' => (string) $projectB->getId()]);

        self::assertResponseRedirects('/testcases/'.$testcase->getUuid());
        self::assertSame(['Kept'], $this->testcaseTitles('Run A'));
    }

    public function testAdminClearingATestcasesProjectRemovesItFromRuns(): void
    {
        $admin = $this->persistUser('root', UserRole::Admin);
        $project = $this->persistProject('Project A');
        $testcase = $this->persistTestcase('Greeting', $project);
        $this->persistRun($project, 'Run A', [], [$testcase]);
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/testcases/'.$testcase->getUuid());
        $this->submit($crawler, 'Save project', 'testcase_project', ['project' => '']);

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

        $crawler = $this->client->request('GET', '/testcases/'.$testcase->getUuid().'/delete');
        $this->client->submit($crawler->selectButton('Delete permanently')->form());

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

        $crawler = $this->client->request('GET', '/skills/'.$skill->getUuid().'/delete');
        $this->client->submit($crawler->selectButton('Delete permanently')->form());
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

    /** @param array<string, mixed> $overrides */
    private function submit(Crawler $crawler, string $button, string $formName, array $overrides): void
    {
        $form = $crawler->selectButton($button)->form();
        /** @var array<string, array<string, mixed>> $values */
        $values = $form->getPhpValues();
        $values[$formName] = array_replace($values[$formName], $overrides);
        $this->client->request($form->getMethod(), $form->getUri(), $values);
    }

    /**
     * @param list<Skill>    $skills
     * @param list<Testcase> $testcases
     */
    private function persistRun(Project $project, string $title, array $skills = [], array $testcases = [], bool $configured = false): Run
    {
        $run = new Run($project);
        $run->setTitle($title);
        $run->setSkills($skills);
        $run->setTestcases($testcases);
        if ($configured) {
            $run->addConfiguration(new LlmConfiguration(Provider::ClaudeOAuth, 'opus'));
        }
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        return $run;
    }

    /** @param list<Project> $projects */
    private function persistSkill(string $title, array $projects): Skill
    {
        $skill = new Skill($title, null, 'content');
        foreach ($projects as $project) {
            $skill->addProject($project);
        }
        $this->entityManager->persist($skill);
        $this->entityManager->flush();

        return $skill;
    }

    private function persistTestcase(string $title, ?Project $project): Testcase
    {
        $testcase = new Testcase($title, null, 'prompt', 'output', $project);
        $this->entityManager->persist($testcase);
        $this->entityManager->flush();

        return $testcase;
    }

    /** @param list<User> $users */
    private function persistProject(string $title, array $users = []): Project
    {
        $project = new Project();
        $project->setTitle($title);
        foreach ($users as $user) {
            $project->addUser($user);
        }
        $this->entityManager->persist($project);
        $this->entityManager->flush();

        return $project;
    }

    private function persistUser(string $username, UserRole $role = UserRole::User): User
    {
        $user = new User();
        $user->setUsername($username);
        $user->setRole($role);
        $user->setPassword('unused-in-these-tests');
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
