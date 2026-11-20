<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Ai\Effort;
use App\Ai\LlmConfiguration;
use App\Ai\Provider;
use App\Entity\Group;
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

final class RunManagementTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        foreach ([Run::class, Testcase::class, Skill::class, Project::class, Group::class, User::class] as $class) {
            $this->entityManager->createQuery('DELETE FROM '.$class)->execute();
        }
    }

    public function testUserCreatesAndEditsARunInTheirProject(): void
    {
        $user = $this->persistUser('alice');
        $project = $this->persistProject('Security', [$user]);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/projects/'.$project->getUuid().'/runs/new');
        $this->submit($crawler, 'Save', 'run', ['title' => 'Nightly', 'description' => 'All reviews']);

        $run = $this->findRun('Nightly');
        self::assertResponseRedirects('/runs/'.$run->getUuid());
        self::assertSame('alice', $run->getCreatedBy()?->getUsername());
        self::assertSame('Security', $run->getProject()->getTitle());

        $crawler = $this->client->request('GET', '/runs/'.$run->getUuid());
        $this->submit($crawler, 'Save', 'run', ['title' => 'Weekly']);
        self::assertResponseRedirects('/runs/'.$run->getUuid());
        $this->entityManager->clear();
        self::assertSame('Weekly', $this->findRun('Weekly')->getTitle());
    }

    public function testBlankTitleIsRejected(): void
    {
        $user = $this->persistUser('alice');
        $project = $this->persistProject('Security', [$user]);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/projects/'.$project->getUuid().'/runs/new');
        $this->submit($crawler, 'Save', 'run', ['title' => '   ']);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->entityManager->getRepository(Run::class)->findAll());
    }

    public function testUsersWithAccessViaGroupOrDirectlySeeAndEditRunsButOutsidersDoNot(): void
    {
        $direct = $this->persistUser('alice');
        $viaGroup = $this->persistUser('bob');
        $outsider = $this->persistUser('carol');
        $group = $this->persistGroup('Team one');
        $group->addUser($viaGroup);
        $project = $this->persistProject('Security', [$direct]);
        $project->addGroup($group);
        $this->entityManager->flush();
        $run = $this->persistRun($project, null, 'Nightly', configured: true);
        $skill = $this->persistSkill('Review', [$project]);
        $uuid = (string) $run->getUuid();

        foreach ([$direct, $viaGroup] as $user) {
            $this->client->loginUser($user);
            self::assertSame(['Nightly'], $this->titles($this->client->request('GET', '/projects/'.$project->getUuid().'/runs')));
            $crawler = $this->client->request('GET', '/runs/'.$uuid);
            self::assertResponseIsSuccessful();
            $this->submit($crawler, 'Save', 'run', ['description' => 'edited by '.$user->getUsername()]);
            self::assertResponseRedirects('/runs/'.$uuid);
        }

        $this->client->loginUser($outsider);
        foreach ([
            ['GET', '/projects/'.$project->getUuid().'/runs'],
            ['GET', '/projects/'.$project->getUuid().'/runs/new'],
            ['POST', '/projects/'.$project->getUuid().'/runs/new'],
            ['GET', '/runs/'.$uuid],
            ['POST', '/runs/'.$uuid],
            ['POST', '/runs/'.$uuid.'/configurations'],
            ['POST', '/runs/'.$uuid.'/configurations/0/remove'],
            ['POST', '/runs/'.$uuid.'/skills'],
            ['POST', '/runs/'.$uuid.'/testcases'],
            ['POST', '/runs/'.$uuid.'/start'],
            ['GET', '/runs/'.$uuid.'/versions/1'],
            ['GET', '/runs/'.$uuid.'/compare'],
            ['GET', '/runs/'.$uuid.'/delete'],
            ['POST', '/runs/'.$uuid.'/delete'],
        ] as [$method, $url]) {
            $this->client->request($method, $url);
            self::assertResponseStatusCodeSame(403, \sprintf('%s %s', $method, $url));
        }
        $this->entityManager->clear();
        self::assertSame('edited by bob', $this->findRun('Nightly')->getDescription());
        self::assertNotNull($skill->getId());
    }

    public function testAnonymousUserIsSentToLoginAndUnknownRunsReturn404(): void
    {
        $admin = $this->persistUser('root', UserRole::Admin);
        $project = $this->persistProject('Security');
        $this->client->request('GET', '/runs/018f3a52-0000-7000-8000-000000000000');
        self::assertResponseRedirects('http://localhost/login');

        $this->client->loginUser($admin);
        foreach (['/runs/018f3a52-0000-7000-8000-000000000000', '/runs/not-a-uuid', '/projects/018f3a52-0000-7000-8000-000000000000/runs', '/projects/'.$project->getUuid().'/runs/new-x'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(404, $url);
        }
    }

    public function testCreatorAndAdminMayDeleteOthersMayNot(): void
    {
        $creator = $this->persistUser('alice');
        $colleague = $this->persistUser('bob');
        $project = $this->persistProject('Security', [$creator, $colleague]);
        $mine = $this->persistRun($project, $creator, 'Mine');
        $adminDeletes = $this->persistRun($project, $creator, 'Admin deletes');

        $this->client->loginUser($colleague);
        $this->client->request('GET', '/runs/'.$mine->getUuid().'/delete');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/runs/'.$mine->getUuid().'/delete');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/runs/'.$mine->getUuid());
        self::assertSelectorNotExists('a[href$="/delete"]');

        $this->client->loginUser($creator);
        $crawler = $this->client->request('GET', '/runs/'.$mine->getUuid().'/delete');
        self::assertResponseIsSuccessful();
        $this->client->request('POST', '/runs/'.$mine->getUuid().'/delete', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        $this->client->submit($crawler->selectButton('Delete permanently')->form());
        self::assertResponseRedirects('/projects/'.$project->getUuid().'/runs');
        $this->entityManager->clear();
        self::assertNull($this->entityManager->getRepository(Run::class)->findOneBy(['title' => 'Mine']));

        $this->client->loginUser($this->persistUser('root', UserRole::Admin));
        $crawler = $this->client->request('GET', '/runs/'.$adminDeletes->getUuid().'/delete');
        $this->client->submit($crawler->selectButton('Delete permanently')->form());
        self::assertResponseRedirects('/projects/'.$project->getUuid().'/runs');
        self::assertSame([], $this->titles($this->client->request('GET', '/projects/'.$project->getUuid().'/runs')));
    }

    public function testConfigurationsAreAddedDeduplicatedAndRemoved(): void
    {
        $user = $this->persistUser('alice');
        $project = $this->persistProject('Security', [$user]);
        $run = $this->persistRun($project, $user);
        $path = '/runs/'.$run->getUuid();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', $path);
        self::assertSelectorTextContains('body', 'No configurations yet.');
        $this->submit($crawler, 'Add configuration', 'run_configuration', ['model' => 'openai:gpt-5.4', 'effort' => 'high']);
        self::assertResponseRedirects($path);
        $crawler = $this->client->request('GET', $path);
        self::assertSelectorTextContains('body', 'OpenAI / gpt-5.4 / effort high');

        $this->submit($crawler, 'Add configuration', 'run_configuration', ['model' => 'openai:gpt-5.4', 'effort' => 'high']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'This configuration already exists.');

        $crawler = $this->client->request('GET', $path);
        $this->submit($crawler, 'Add configuration', 'run_configuration', ['model' => 'openai:gpt-5.4', 'effort' => '']);
        $this->submit($crawler, 'Add configuration', 'run_configuration', ['model' => 'openai:opus', 'effort' => '']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'The configuration could not be added.');

        $this->entityManager->clear();
        self::assertSame(['openai:gpt-5.4:high', 'openai:gpt-5.4:-'], array_map(static fn (LlmConfiguration $c): string => $c->key(), $this->findRun('Nightly')->getConfigurations()));

        $crawler = $this->client->request('GET', $path);
        $this->client->submit($crawler->filter('form[action$="/configurations/0/remove"]')->form());
        self::assertResponseRedirects($path);
        $this->entityManager->clear();
        self::assertSame(['openai:gpt-5.4:-'], array_map(static fn (LlmConfiguration $c): string => $c->key(), $this->findRun('Nightly')->getConfigurations()));

        $this->client->request('POST', $path.'/configurations/0/remove', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testOnlyNonDeletedSkillsAndTestcasesOfTheRunsProjectAreSelectable(): void
    {
        $user = $this->persistUser('alice');
        $project = $this->persistProject('Security', [$user]);
        $other = $this->persistProject('Other', [$user]);
        $own = $this->persistSkill('Own skill', [$project]);
        $foreign = $this->persistSkill('Foreign skill', [$other]);
        $deleted = $this->persistSkill('Deleted skill', [$project]);
        $deleted->softDelete();
        $ownTestcase = $this->persistTestcase('Own testcase', $project);
        $foreignTestcase = $this->persistTestcase('Foreign testcase', $other);
        $this->entityManager->flush();
        $run = $this->persistRun($project, $user);
        $path = '/runs/'.$run->getUuid();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', $path);
        $body = $crawler->filter('body')->text();
        self::assertStringContainsString('Own skill', $body);
        self::assertStringContainsString('Own testcase', $body);
        self::assertStringNotContainsString('Foreign skill', $body);
        self::assertStringNotContainsString('Deleted skill', $body);
        self::assertStringNotContainsString('Foreign testcase', $body);

        $this->submit($crawler, 'Save skills', 'run_skills', ['skills' => [(string) $own->getId()]]);
        $this->submit($crawler, 'Save testcases', 'run_testcases', ['testcases' => [(string) $ownTestcase->getId()]]);
        $this->entityManager->clear();
        $stored = $this->findRun('Nightly');
        self::assertSame(['Own skill'], $stored->getSkills()->map(static fn (Skill $s): string => $s->getActiveVersion()->getTitle())->toArray());
        self::assertSame(['Own testcase'], $stored->getTestcases()->map(static fn (Testcase $t): string => $t->getActiveVersion()->getTitle())->toArray());

        $crawler = $this->client->request('GET', $path);
        foreach ([[(string) $foreign->getId(), 'Save skills', 'run_skills', 'skills'], [(string) $deleted->getId(), 'Save skills', 'run_skills', 'skills']] as [$id, $button, $form, $field]) {
            $this->submit($crawler, $button, $form, [$field => [$id]]);
            $this->client->followRedirect();
            self::assertSelectorTextContains('body', 'The skills could not be saved.');
        }
        $this->submit($crawler, 'Save testcases', 'run_testcases', ['testcases' => [(string) $foreignTestcase->getId()]]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'The testcases could not be saved.');
        $this->entityManager->clear();
        self::assertCount(1, $this->findRun('Nightly')->getSkills());
        self::assertCount(1, $this->findRun('Nightly')->getTestcases());
    }

    public function testStartCreatesNumberedVersionsAndIsRefusedWhenIncomplete(): void
    {
        $user = $this->persistUser('alice');
        $project = $this->persistProject('Security', [$user]);
        $skill = $this->persistSkill('Review', [$project]);
        $testcase = $this->persistTestcase('Greeting', $project);
        $run = $this->persistRun($project, $user);
        $path = '/runs/'.$run->getUuid();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', $path);
        $this->client->submit($crawler->filter('form[action$="/start"]')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'A run needs at least one testcase to start.');

        $run = $this->findRun('Nightly');
        $run->setTestcases([$this->reload($testcase)]);
        $this->entityManager->flush();
        $crawler = $this->client->request('GET', $path);
        $this->client->submit($crawler->filter('form[action$="/start"]')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'A run needs at least one LLM configuration to start.');
        $this->entityManager->clear();
        self::assertCount(0, $this->findRun('Nightly')->getVersions());

        $run = $this->findRun('Nightly');
        $run->addConfiguration(new LlmConfiguration(Provider::ClaudeOAuth, 'opus', Effort::Low));
        $run->setSkills([$this->reload($skill)]);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', $path);
        $this->client->submit($crawler->filter('form[action$="/start"]')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Started as version 1.');
        $crawler = $this->client->request('GET', $path);
        $this->client->submit($crawler->filter('form[action$="/start"]')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Started as version 2.');

        $this->client->request('POST', $path.'/start', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        $this->entityManager->clear();
        self::assertCount(2, $this->findRun('Nightly')->getVersions());
    }

    public function testVersionPageShowsTheSnapshotAndCompareHasDefaults(): void
    {
        $user = $this->persistUser('alice');
        $project = $this->persistProject('Security', [$user]);
        $skill = $this->persistSkill('Review', [$project]);
        $testcase = $this->persistTestcase('Greeting', $project);
        $run = $this->persistRun($project, $user, 'Nightly', [$skill], [$testcase], true);
        $run->start();
        $run->setTitle('Weekly');
        $skill->saveFinal('Review', null, 'content 2');
        $run->start();
        $this->entityManager->flush();
        $this->client->loginUser($user);

        $this->client->request('GET', '/runs/'.$run->getUuid().'/versions/1');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Nightly – version 1');
        self::assertSelectorTextContains('body', 'Review (version 1)');
        self::assertSelectorTextContains('body', 'Greeting (version 1)');
        self::assertSelectorTextContains('body', 'Claude (OAuth) / opus / no effort');

        $this->client->request('GET', '/runs/'.$run->getUuid().'/compare');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Differences from version 1 to version 2');
        self::assertSelectorTextContains('body', 'Nightly');
        self::assertSelectorTextContains('body', 'Weekly');
        self::assertSelectorTextContains('body', 'Changed: Review from version 1 to version 2');

        $this->client->request('GET', '/runs/'.$run->getUuid().'/compare', ['from' => 2, 'to' => 2]);
        self::assertSelectorTextContains('body', 'No differences between version 2 and version 2.');

        $this->client->request('GET', '/runs/'.$run->getUuid().'/versions/9');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/runs/'.$run->getUuid().'/compare', ['from' => 1, 'to' => 9]);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/runs/'.$run->getUuid().'/compare', ['from' => 0]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testCompareWithoutTwoVersionsExplainsIt(): void
    {
        $user = $this->persistUser('alice');
        $project = $this->persistProject('Security', [$user]);
        $run = $this->persistRun($project, $user);
        $this->client->loginUser($user);

        $this->client->request('GET', '/runs/'.$run->getUuid().'/compare');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'At least two versions are needed to compare.');
    }

    public function testListIsScopedToTheProjectAndSearchesSortsAndPaginates(): void
    {
        $user = $this->persistUser('alice');
        $project = $this->persistProject('Security', [$user]);
        $other = $this->persistProject('Other', [$user]);
        $skill = $this->persistSkill('Review', [$project]);
        $this->persistRun($other, $user, 'Elsewhere');
        $this->persistRun($project, $user, 'Alpha');
        $this->persistRun($project, $user, 'Beta', [$skill]);
        $gamma = $this->persistRun($project, $user, 'Gamma_x');
        $gamma->setDescription('handles GDPR requests');
        $big = $this->persistProject('Big', [$user]);
        for ($i = 1; 28 >= $i; ++$i) {
            $this->persistRun($big, $user, \sprintf('Run %02d', $i));
        }
        $this->entityManager->flush();
        $this->client->loginUser($user);
        $url = '/projects/'.$project->getUuid().'/runs';

        self::assertSame(['Alpha', 'Beta', 'Gamma_x'], $this->titles($this->client->request('GET', $url)));
        self::assertSame(['Gamma_x'], $this->titles($this->client->request('GET', $url, ['q' => 'gdpr'])));
        self::assertSame(['Gamma_x'], $this->titles($this->client->request('GET', $url, ['q' => 'a_x'])));
        self::assertSame([], $this->titles($this->client->request('GET', $url, ['q' => '%'])));

        $crawler = $this->client->request('GET', $url, ['sort' => 'skills', 'dir' => 'desc']);
        self::assertSame('Beta', $this->titles($crawler)[0]);
        self::assertSame('1', trim($crawler->filter('tbody tr:first-child td:nth-child(3)')->text()));

        $bigUrl = '/projects/'.$big->getUuid().'/runs';
        self::assertCount(10, $this->titles($this->client->request('GET', $bigUrl)));
        $crawler = $this->client->request('GET', $bigUrl, ['page' => 3, 'perPage' => 10]);
        self::assertSelectorTextContains('nav[aria-label="Pagination"]', 'Page 3 of 3 (28 entries)');
        self::assertCount(8, $this->titles($crawler));
    }

    public function testProjectDetailShowsTheRunCountAndProjectDeleteRemovesRunsButKeepsSkillsAndTestcases(): void
    {
        $admin = $this->persistUser('root', UserRole::Admin);
        $project = $this->persistProject('Security', [$admin]);
        $skill = $this->persistSkill('Review', [$project]);
        $testcase = $this->persistTestcase('Greeting', $project);
        $run = $this->persistRun($project, $admin, 'Nightly', [$skill], [$testcase], true);
        $run->start();
        $run->start();
        $this->entityManager->flush();
        $this->client->loginUser($admin);

        $this->client->request('GET', '/projects/'.$project->getUuid());
        self::assertSelectorTextContains('body', 'Runs: 1');
        self::assertSelectorExists('a[href$="/runs"]');

        $crawler = $this->client->request('GET', '/projects/'.$project->getUuid().'/delete');
        self::assertSelectorTextContains('body', '1 run(s) and their versions are deleted.');
        $this->client->submit($crawler->selectButton('Delete permanently')->form());
        self::assertResponseRedirects('/projects');

        $this->entityManager->clear();
        self::assertCount(0, $this->entityManager->getRepository(Run::class)->findAll());
        foreach (['run', 'run_version', 'run_skill', 'run_testcase', 'run_version_skill', 'run_version_testcase'] as $table) {
            $rows = $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
            self::assertIsNumeric($rows);
            self::assertSame(0, (int) $rows, $table);
        }
        self::assertCount(1, $this->entityManager->getRepository(Skill::class)->findAll());
        self::assertCount(1, $this->entityManager->getRepository(Testcase::class)->findAll());
    }

    public function testSkillOverviewCountsOnlyRunsInAccessibleProjects(): void
    {
        $user = $this->persistUser('alice');
        $own = $this->persistProject('Own', [$user]);
        $hidden = $this->persistProject('Hidden');
        $skill = $this->persistSkill('Review', [$own, $hidden]);
        $this->persistRun($own, $user, 'Visible one', [$skill]);
        $this->persistRun($own, $user, 'Visible two', [$skill]);
        $this->persistRun($hidden, null, 'Hidden run', [$skill]);

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/skills');
        self::assertSame('2', trim($crawler->filter('tbody tr:first-child td:nth-child(4)')->text()));

        $this->client->loginUser($this->persistUser('root', UserRole::Admin));
        $crawler = $this->client->request('GET', '/skills');
        self::assertSame('3', trim($crawler->filter('tbody tr:first-child td:nth-child(4)')->text()));
    }

    /** @return list<string> */
    private function titles(Crawler $crawler): array
    {
        self::assertResponseIsSuccessful();

        return $crawler->filter('tbody tr td:first-child')->each(static fn (Crawler $cell): string => trim($cell->text()));
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
     * @template T of Skill|Testcase
     *
     * @param T $entity
     *
     * @return T
     */
    private function reload(Skill|Testcase $entity): Skill|Testcase
    {
        $id = $entity->getId();
        self::assertIsInt($id);
        $reloaded = $this->entityManager->find($entity::class, $id);
        self::assertInstanceOf($entity::class, $reloaded);

        return $reloaded;
    }

    private function findRun(string $title): Run
    {
        $run = $this->entityManager->getRepository(Run::class)->findOneBy(['title' => $title]);
        self::assertInstanceOf(Run::class, $run);

        return $run;
    }

    /**
     * @param list<Skill>    $skills
     * @param list<Testcase> $testcases
     */
    private function persistRun(Project $project, ?User $creator, string $title = 'Nightly', array $skills = [], array $testcases = [], bool $configured = false): Run
    {
        $run = new Run($project, $creator);
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

    private function persistGroup(string $title): Group
    {
        $group = new Group();
        $group->setTitle($title);
        $this->entityManager->persist($group);
        $this->entityManager->flush();

        return $group;
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
