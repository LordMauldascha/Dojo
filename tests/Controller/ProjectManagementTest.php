<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Group;
use App\Entity\Project;
use App\Entity\Skill;
use App\Entity\Testcase;
use App\Entity\User;
use App\Security\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class ProjectManagementTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->createQuery('DELETE FROM '.Testcase::class)->execute();
        $this->entityManager->createQuery('DELETE FROM '.Skill::class)->execute();
        $this->entityManager->createQuery('DELETE FROM '.Project::class)->execute();
        $this->entityManager->createQuery('DELETE FROM '.Group::class)->execute();
        $this->entityManager->createQuery('DELETE FROM '.User::class)->execute();
    }

    public function testAdminCreatesProjectAndLandsOnDetailPage(): void
    {
        $this->client->loginUser($this->persistUser('root', UserRole::Admin));

        $crawler = $this->client->request('GET', '/projects/new');
        $this->client->submit($crawler->selectButton('Save')->form(['project[title]' => 'Data protection', 'project[description]' => 'GDPR work']));

        $project = $this->findProject('Data protection');
        self::assertResponseRedirects('/projects/'.$project->getUuid());
        self::assertSame('GDPR work', $project->getDescription());
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Data protection');
    }

    public function testBlankAndDuplicateTitlesAreRejected(): void
    {
        $this->client->loginUser($this->persistUser('root', UserRole::Admin));
        $this->persistProject('Data protection');

        foreach (['   ', 'Data protection'] as $title) {
            $crawler = $this->client->request('GET', '/projects/new');
            $this->client->submit($crawler->selectButton('Save')->form(['project[title]' => $title]));
            self::assertResponseStatusCodeSame(422);
        }

        self::assertCount(1, $this->entityManager->getRepository(Project::class)->findAll());
    }

    public function testTitlesAreUniqueIgnoringCase(): void
    {
        $this->client->loginUser($this->persistUser('root', UserRole::Admin));
        $project = $this->persistProject('Security');
        $other = $this->persistProject('Data protection');

        $crawler = $this->client->request('GET', '/projects/new');
        $this->client->submit($crawler->selectButton('Save')->form(['project[title]' => 'SECURITY']));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'This project title is already taken.');
        self::assertCount(2, $this->entityManager->getRepository(Project::class)->findAll());

        $crawler = $this->client->request('GET', '/projects/'.$other->getUuid());
        $this->client->submit($crawler->selectButton('Save')->form(['project[title]' => 'security']));
        self::assertResponseStatusCodeSame(422);

        $crawler = $this->client->request('GET', '/projects/'.$project->getUuid());
        $this->client->submit($crawler->selectButton('Save')->form(['project[title]' => 'SECURITY']));
        self::assertResponseRedirects('/projects/'.$project->getUuid());
        $this->entityManager->clear();
        self::assertSame('SECURITY', $this->findProject('SECURITY')->getTitle());
    }

    public function testAdminEditsProjectAndManagesGroupsAndUsers(): void
    {
        $this->client->loginUser($this->persistUser('root', UserRole::Admin));
        $member = $this->persistUser('alice');
        $group = $this->persistGroup('Team one');
        $project = $this->persistProject('Security');
        $path = '/projects/'.$project->getUuid();

        $crawler = $this->client->request('GET', $path);
        $this->client->submit($crawler->selectButton('Save')->form(['project[title]' => 'IT security', 'project[description]' => 'Pentests']));
        self::assertResponseRedirects($path);
        $this->entityManager->clear();
        self::assertSame('Pentests', $this->findProject('IT security')->getDescription());

        $crawler = $this->client->request('GET', $path);
        $this->client->submit($crawler->filter('form[action$="/groups"]')->form(['add_group[group]' => (string) $group->getId()]));
        self::assertResponseRedirects($path);
        $crawler = $this->client->request('GET', $path);
        $this->client->submit($crawler->filter('form[action$="/users"]')->form(['add_user[user]' => (string) $member->getId()]));
        self::assertResponseRedirects($path);
        $this->entityManager->clear();
        self::assertCount(1, $this->findProject('IT security')->getGroups());
        self::assertCount(1, $this->findProject('IT security')->getUsers());

        $crawler = $this->client->request('GET', $path);
        self::assertSelectorTextContains('body', 'Team one');
        self::assertSelectorTextContains('body', 'alice');
        $this->client->submit($crawler->filter('form[action*="/groups/"]')->form());
        self::assertResponseRedirects($path);
        $this->client->submit($crawler->filter('form[action*="/users/"]')->form());
        self::assertResponseRedirects($path);
        $this->entityManager->clear();
        self::assertCount(0, $this->findProject('IT security')->getGroups());
        self::assertCount(0, $this->findProject('IT security')->getUsers());
        self::assertNotNull($this->entityManager->getRepository(Group::class)->findOneBy(['title' => 'Team one']));
        self::assertNotNull($this->entityManager->getRepository(User::class)->findOneBy(['username' => 'alice']));
    }

    public function testRemovalNeedsValidCsrfToken(): void
    {
        $this->client->loginUser($this->persistUser('root', UserRole::Admin));
        $member = $this->persistUser('alice');
        $project = $this->persistProject('Security');
        $project->addUser($member);
        $this->entityManager->flush();

        $this->client->request('POST', \sprintf('/projects/%s/users/%d/remove', $project->getUuid(), $member->getId()), ['_token' => 'invalid']);

        self::assertResponseStatusCodeSame(403);
        $this->entityManager->clear();
        self::assertCount(1, $this->findProject('Security')->getUsers());
    }

    public function testUserSeesProjectsOnlyViaDirectAssignmentOrGroup(): void
    {
        $direct = $this->persistUser('alice');
        $viaGroup = $this->persistUser('bob');
        $outsider = $this->persistUser('carol');
        $group = $this->persistGroup('Team one');
        $group->addUser($viaGroup);
        $project = $this->persistProject('Security');
        $project->addUser($direct);
        $project->addGroup($group);
        $this->persistProject('Secret');
        $this->entityManager->flush();
        $path = '/projects/'.$project->getUuid();

        foreach ([$direct, $viaGroup] as $user) {
            $this->client->loginUser($user);
            self::assertSame(['Security'], $this->titles($this->client->request('GET', '/projects')));
            $this->client->request('GET', $path);
            self::assertResponseIsSuccessful();
        }

        $this->client->loginUser($outsider);
        self::assertSame([], $this->titles($this->client->request('GET', '/projects')));
        $this->client->request('GET', $path);
        self::assertResponseStatusCodeSame(403);
    }

    public function testUserWithAccessViaGroupLosesItWhenRemovedFromGroup(): void
    {
        $member = $this->persistUser('bob');
        $group = $this->persistGroup('Team one');
        $group->addUser($member);
        $project = $this->persistProject('Security');
        $project->addGroup($group);
        $this->entityManager->flush();
        $this->client->loginUser($member);
        $this->client->request('GET', '/projects/'.$project->getUuid());
        self::assertResponseIsSuccessful();

        $group->removeUser($member);
        $this->entityManager->flush();

        $this->client->request('GET', '/projects/'.$project->getUuid());
        self::assertResponseStatusCodeSame(403);
    }

    public function testUnknownProjectReturns404(): void
    {
        $this->client->loginUser($this->persistUser('root', UserRole::Admin));

        $this->client->request('GET', '/projects/018f3a52-0000-7000-8000-000000000000');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/projects/not-a-uuid');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnonymousUserIsSentToLogin(): void
    {
        $this->client->request('GET', '/projects');

        self::assertResponseRedirects('http://localhost/login');
    }

    public function testNonAdminSeesOnlyCountsAndNoForms(): void
    {
        $user = $this->persistUser('alice');
        $group = $this->persistGroup('Team one');
        $group->addUser($this->persistUser('bob'));
        $project = $this->persistProject('Security', 'Pentests');
        $project->addUser($user);
        $project->addGroup($group);
        $this->entityManager->flush();
        $this->client->loginUser($user);

        $this->client->request('GET', '/projects/'.$project->getUuid());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Pentests');
        self::assertSelectorTextContains('body', 'Groups: 1');
        self::assertSelectorTextContains('body', 'Users: 1');
        self::assertSelectorTextNotContains('body', 'Team one');
        self::assertSelectorTextNotContains('body', 'alice');
        self::assertSelectorNotExists('form');
    }

    public function testNonAdminCannotManageEvenWithAccess(): void
    {
        $user = $this->persistUser('alice');
        $group = $this->persistGroup('Team one');
        $project = $this->persistProject('Security');
        $project->addUser($user);
        $this->entityManager->flush();
        $this->client->loginUser($user);
        $uuid = (string) $project->getUuid();

        foreach ([
            ['GET', '/projects/new'],
            ['POST', '/projects/new'],
            ['POST', '/projects/'.$uuid],
            ['POST', '/projects/'.$uuid.'/groups'],
            ['POST', \sprintf('/projects/%s/groups/%s/remove', $uuid, $group->getUuid())],
            ['POST', '/projects/'.$uuid.'/users'],
            ['POST', \sprintf('/projects/%s/users/%d/remove', $uuid, $user->getId())],
            ['GET', '/projects/'.$uuid.'/delete'],
            ['POST', '/projects/'.$uuid.'/delete'],
        ] as [$method, $path]) {
            $this->client->request($method, $path);
            self::assertResponseStatusCodeSame(403, \sprintf('%s %s', $method, $path));
        }

        $this->entityManager->clear();
        self::assertCount(1, $this->findProject('Security')->getUsers());
    }

    public function testNonAdminWithoutAccessGetsForbiddenOnEveryProjectRoute(): void
    {
        $outsider = $this->persistUser('carol');
        $project = $this->persistProject('Security');
        $this->client->loginUser($outsider);
        $uuid = (string) $project->getUuid();

        foreach ([['GET', '/projects/'.$uuid], ['GET', '/projects/'.$uuid.'/delete'], ['POST', '/projects/'.$uuid.'/delete']] as [$method, $path]) {
            $this->client->request($method, $path);
            self::assertResponseStatusCodeSame(403, \sprintf('%s %s', $method, $path));
        }
    }

    public function testDeleteAsksForConfirmationAndKeepsGroupsAndUsers(): void
    {
        $this->client->loginUser($this->persistUser('root', UserRole::Admin));
        $member = $this->persistUser('alice');
        $group = $this->persistGroup('Team one');
        $project = $this->persistProject('Security');
        $project->addUser($member);
        $project->addGroup($group);
        $this->entityManager->flush();
        $path = '/projects/'.$project->getUuid().'/delete';

        $crawler = $this->client->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', '1 group(s) and 1 user(s)');

        $this->client->request('POST', $path, ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->entityManager->getRepository(Project::class)->findOneBy(['title' => 'Security']));

        $this->client->submit($crawler->selectButton('Delete permanently')->form());
        self::assertResponseRedirects('/projects');
        $this->entityManager->clear();
        self::assertNull($this->entityManager->getRepository(Project::class)->findOneBy(['title' => 'Security']));
        self::assertNotNull($this->entityManager->getRepository(Group::class)->findOneBy(['title' => 'Team one']));
        self::assertNotNull($this->entityManager->getRepository(User::class)->findOneBy(['username' => 'alice']));
        foreach (['project_group', 'project_user'] as $table) {
            $rows = $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
            self::assertIsNumeric($rows);
            self::assertSame(0, (int) $rows, $table);
        }
    }

    public function testAdminSeesAllProjectsAndSearchHitsDescription(): void
    {
        $this->client->loginUser($this->persistUser('root', UserRole::Admin));
        $this->persistProject('Alpha', 'handles GDPR requests');
        $this->persistProject('Beta', null);
        $this->persistProject('Gamma_x', 'other');

        $matches = $this->titles($this->client->request('GET', '/projects'));
        sort($matches);
        self::assertSame(['Alpha', 'Beta', 'Gamma_x'], $matches);
        self::assertSame(['Alpha'], $this->titles($this->client->request('GET', '/projects', ['q' => 'gdpr'])));
        self::assertSame(['Gamma_x'], $this->titles($this->client->request('GET', '/projects', ['q' => 'a_x'])));
        self::assertSame([], $this->titles($this->client->request('GET', '/projects', ['q' => '%'])));
    }

    public function testListSortsByGroupCount(): void
    {
        $this->client->loginUser($this->persistUser('root', UserRole::Admin));
        $groups = [$this->persistGroup('G1'), $this->persistGroup('G2')];
        $this->persistProject('A-none');
        $this->persistProject('B-two');
        $this->persistProject('C-one');
        $this->findProject('B-two')->addGroup($groups[0]);
        $this->findProject('B-two')->addGroup($groups[1]);
        $this->findProject('C-one')->addGroup($groups[0]);
        $this->entityManager->flush();

        self::assertSame(['B-two', 'C-one', 'A-none'], $this->titles($this->client->request('GET', '/projects', ['sort' => 'groups', 'dir' => 'desc'])));
        self::assertSame(['A-none', 'B-two', 'C-one'], $this->titles($this->client->request('GET', '/projects', ['sort' => 'nonsense'])));
    }

    public function testPaginationCountsOnlyAccessibleProjects(): void
    {
        $user = $this->persistUser('alice');
        for ($i = 1; 25 >= $i; ++$i) {
            $project = $this->persistProject(\sprintf('Project %02d', $i));
            if (0 === $i % 2) {
                $project->addUser($user);
            }
        }
        $this->entityManager->flush();
        $this->client->loginUser($user);

        self::assertCount(10, $this->titles($this->client->request('GET', '/projects')));
        $page = $this->client->request('GET', '/projects', ['page' => 2]);
        self::assertSame(['Project 22', 'Project 24'], $this->titles($page));
        self::assertSelectorTextContains('nav[aria-label="Pagination"]', 'Page 2 of 2 (12 entries)');
        self::assertSame(['Project 12'], $this->titles($this->client->request('GET', '/projects', ['q' => '12'])));
    }

    public function testAdminAddsAndRemovesSkillsOnProjectDetail(): void
    {
        $this->client->loginUser($this->persistUser('root', UserRole::Admin));
        $project = $this->persistProject('Security');
        $skill = $this->persistSkill('Review');
        $path = '/projects/'.$project->getUuid();

        $crawler = $this->client->request('GET', $path);
        $this->client->submit($crawler->filter('form[action$="/skills"]')->form(['add_skill[skill]' => (string) $skill->getId()]));
        self::assertResponseRedirects($path);
        $crawler = $this->client->request('GET', $path);
        self::assertSelectorTextContains('body', 'Review');
        self::assertSelectorExists('a[href*="/skills?project="]');
        $this->entityManager->clear();
        self::assertCount(1, $this->entityManager->getRepository(Skill::class)->findAll()[0]->getProjects());

        $this->client->submit($crawler->filter('form[action*="/skills/"]')->form());
        self::assertResponseRedirects($path);
        $this->entityManager->clear();
        self::assertCount(0, $this->entityManager->getRepository(Skill::class)->findAll()[0]->getProjects());
        self::assertCount(1, $this->entityManager->getRepository(Skill::class)->findAll());
    }

    public function testNonAdminCannotManageSkillsOfAProjectButSeesTheCount(): void
    {
        $user = $this->persistUser('alice');
        $project = $this->persistProject('Security');
        $project->addUser($user);
        $this->entityManager->flush();
        $skill = $this->persistSkill('Review', [$project]);
        $deleted = $this->persistSkill('Gone', [$project]);
        $deleted->softDelete();
        $this->entityManager->flush();
        $this->client->loginUser($user);

        $this->client->request('GET', '/projects/'.$project->getUuid());
        self::assertSelectorTextContains('body', 'Skills: 1');
        self::assertSelectorNotExists('form');

        $this->client->request('POST', '/projects/'.$project->getUuid().'/skills');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', \sprintf('/projects/%s/skills/%s/remove', $project->getUuid(), $skill->getUuid()));
        self::assertResponseStatusCodeSame(403);
    }

    public function testProjectListCountsOnlyNonDeletedSkillsAndSortsBySkillCount(): void
    {
        $this->client->loginUser($this->persistUser('root', UserRole::Admin));
        $many = $this->persistProject('B-many');
        $one = $this->persistProject('C-one');
        $this->persistProject('A-none');
        $this->persistSkill('S1', [$many, $one]);
        $this->persistSkill('S2', [$many]);
        $deleted = $this->persistSkill('S3', [$many]);
        $deleted->softDelete();
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/projects', ['sort' => 'skills', 'dir' => 'desc']);

        self::assertSame(['B-many', 'C-one', 'A-none'], $this->titles($crawler));
        self::assertSame(['2', '1', '0'], $crawler->filter('tbody tr td:nth-child(5)')->each(static fn (Crawler $cell): string => trim($cell->text())));
    }

    public function testDeletingAProjectKeepsItsSkills(): void
    {
        $this->client->loginUser($this->persistUser('root', UserRole::Admin));
        $project = $this->persistProject('Security');
        $this->persistSkill('Review', [$project]);

        $crawler = $this->client->request('GET', '/projects/'.$project->getUuid().'/delete');
        self::assertSelectorTextContains('body', '1 skill(s) lose this project assignment');
        $this->client->submit($crawler->selectButton('Delete permanently')->form());

        self::assertResponseRedirects('/projects');
        $this->entityManager->clear();
        $skills = $this->entityManager->getRepository(Skill::class)->findAll();
        self::assertCount(1, $skills);
        self::assertCount(0, $skills[0]->getProjects());
        self::assertFalse($skills[0]->isDeleted());
    }

    public function testProjectListCountsOnlyNonDeletedTestcasesAndSortsByTestcaseCount(): void
    {
        $this->client->loginUser($this->persistUser('root', UserRole::Admin));
        $many = $this->persistProject('B-many');
        $one = $this->persistProject('C-one');
        $this->persistProject('A-none');
        $this->persistTestcase('T1', $many);
        $this->persistTestcase('T2', $many);
        $this->persistTestcase('T3', $one);
        $this->persistTestcase('T4', $many)->softDelete();
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/projects', ['sort' => 'testcases', 'dir' => 'desc']);

        self::assertSame(['B-many', 'C-one', 'A-none'], $this->titles($crawler));
        self::assertSame(['2', '1', '0'], $crawler->filter('tbody tr td:nth-child(6)')->each(static fn (Crawler $cell): string => trim($cell->text())));
        self::assertSelectorExists('a[href*="/testcases?project="]');
    }

    public function testProjectDetailShowsTheTestcaseCountToEveryoneWithAccess(): void
    {
        $user = $this->persistUser('alice');
        $project = $this->persistProject('Security');
        $project->addUser($user);
        $this->entityManager->flush();
        $this->persistTestcase('T1', $project);
        $this->persistTestcase('T2', $project)->softDelete();
        $this->entityManager->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/projects/'.$project->getUuid());
        self::assertSelectorTextContains('body', 'Testcases: 1');
        self::assertSelectorExists('a[href*="/testcases?project="]');

        $this->client->loginUser($this->persistUser('root', UserRole::Admin));
        $this->client->request('GET', '/projects/'.$project->getUuid());
        self::assertSelectorTextContains('body', 'Testcases: 1');
    }

    public function testDeletingAProjectKeepsTestcasesAsAdminOnly(): void
    {
        $admin = $this->persistUser('root', UserRole::Admin);
        $member = $this->persistUser('alice');
        $project = $this->persistProject('Security');
        $project->addUser($member);
        $this->entityManager->flush();
        $testcase = $this->persistTestcase('Greeting', $project);
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/projects/'.$project->getUuid().'/delete');
        self::assertSelectorTextContains('body', '1 testcase(s) lose their project and become admin-only');
        $this->client->submit($crawler->selectButton('Delete permanently')->form());
        self::assertResponseRedirects('/projects');

        $this->entityManager->clear();
        $stored = $this->entityManager->getRepository(Testcase::class)->findAll();
        self::assertCount(1, $stored);
        self::assertNull($stored[0]->getProject());
        self::assertFalse($stored[0]->isDeleted());

        $this->client->request('GET', '/testcases/'.$testcase->getUuid());
        self::assertResponseIsSuccessful();
        $this->client->loginUser($member);
        $this->client->request('GET', '/testcases/'.$testcase->getUuid());
        self::assertResponseStatusCodeSame(403);
    }

    /** @return list<string> */
    private function titles(Crawler $crawler): array
    {
        self::assertResponseIsSuccessful();

        return $crawler->filter('tbody tr td:first-child')->each(static fn (Crawler $cell): string => trim($cell->text()));
    }

    private function findProject(string $title): Project
    {
        $project = $this->entityManager->getRepository(Project::class)->findOneBy(['title' => $title]);
        self::assertInstanceOf(Project::class, $project);

        return $project;
    }

    private function persistProject(string $title, ?string $description = null): Project
    {
        $project = new Project();
        $project->setTitle($title);
        $project->setDescription($description);
        $this->entityManager->persist($project);
        $this->entityManager->flush();

        return $project;
    }

    /** @param list<Project> $projects */
    private function persistSkill(string $title, array $projects = []): Skill
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
