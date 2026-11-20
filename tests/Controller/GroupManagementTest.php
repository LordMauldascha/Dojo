<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Group;
use App\Entity\Project;
use App\Entity\User;
use App\Security\UserRole;
use App\Tests\Support\AppWebTestCase;

final class GroupManagementTest extends AppWebTestCase
{
    public function testAdminCreatesGroupAndLandsOnDetailPage(): void
    {
        $this->client->loginUser($this->persistUser('root', UserRole::Admin));

        $this->submitNewGroup('Entwicklung');

        $group = $this->findGroup('Entwicklung');
        self::assertResponseRedirects('/admin/groups/'.$group->getUuid());
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Entwicklung');
    }

    public function testBlankAndDuplicateTitlesAreRejected(): void
    {
        $this->client->loginUser($this->persistUser('root', UserRole::Admin));
        $this->persistGroup('Entwicklung');

        $this->submitNewGroup('   ');
        self::assertResponseStatusCodeSame(422);
        $this->submitNewGroup('Entwicklung');
        self::assertResponseStatusCodeSame(422);

        self::assertCount(1, $this->entityManager->getRepository(Group::class)->findAll());
    }

    public function testTitlesAreUniqueIgnoringCase(): void
    {
        $this->client->loginUser($this->persistUser('root', UserRole::Admin));
        $group = $this->persistGroup('Entwicklung');
        $other = $this->persistGroup('Betrieb');

        $this->submitNewGroup('ENTWICKLUNG');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'This group title is already taken.');
        self::assertCount(2, $this->entityManager->getRepository(Group::class)->findAll());

        $crawler = $this->client->request('GET', '/admin/groups/'.$other->getUuid());
        $this->client->submit($crawler->selectButton('Save')->form(['group[title]' => 'entwicklung']));
        self::assertResponseStatusCodeSame(422);

        $crawler = $this->client->request('GET', '/admin/groups/'.$group->getUuid());
        $this->client->submit($crawler->selectButton('Save')->form(['group[title]' => 'ENTWICKLUNG']));
        self::assertResponseRedirects('/admin/groups/'.$group->getUuid());
        $this->entityManager->clear();
        self::assertSame('ENTWICKLUNG', $this->findGroup('ENTWICKLUNG')->getTitle());
    }

    public function testAdminRenamesGroupAndManagesMembers(): void
    {
        $this->client->loginUser($this->persistUser('root', UserRole::Admin));
        $member = $this->persistUser('alice');
        $group = $this->persistGroup('Entwicklung');
        $this->setUpdatedAtToThePast($group);
        $past = $this->findGroup('Entwicklung')->getUpdatedAt();

        $crawler = $this->client->request('GET', '/admin/groups/'.$group->getUuid());
        $this->client->submit($crawler->selectButton('Save')->form(['group[title]' => 'Betrieb']));
        self::assertResponseRedirects('/admin/groups/'.$group->getUuid());
        $this->entityManager->clear();
        self::assertSame('Betrieb', $this->findGroup('Betrieb')->getTitle());
        self::assertGreaterThan($past, $this->findGroup('Betrieb')->getUpdatedAt());

        $this->setUpdatedAtToThePast($this->findGroup('Betrieb'));
        $past = $this->findGroup('Betrieb')->getUpdatedAt();
        $crawler = $this->client->request('GET', '/admin/groups/'.$group->getUuid());
        $this->client->submit($crawler->selectButton('Add')->form(['add_user[user]' => (string) $member->getId()]));
        self::assertResponseRedirects('/admin/groups/'.$group->getUuid());
        $this->entityManager->clear();
        self::assertCount(1, $this->findGroup('Betrieb')->getUsers());
        self::assertGreaterThan($past, $this->findGroup('Betrieb')->getUpdatedAt());

        $crawler = $this->client->request('GET', '/admin/groups/'.$group->getUuid());
        self::assertSelectorTextContains('table', 'alice');
        $this->client->submit($crawler->filter('form[action$="/remove"]')->form());
        self::assertResponseRedirects('/admin/groups/'.$group->getUuid());
        $this->entityManager->clear();
        self::assertCount(0, $this->findGroup('Betrieb')->getUsers());
        self::assertNotNull($this->entityManager->getRepository(User::class)->findOneBy(['username' => 'alice']));
    }

    public function testAdminCanBeGroupMember(): void
    {
        $this->client->loginUser($this->persistUser('root', UserRole::Admin));
        $group = $this->persistGroup('Entwicklung');
        $group->addUser($this->persistUser('boss', UserRole::Admin));
        $this->entityManager->flush();

        $this->client->request('GET', '/admin/groups/'.$group->getUuid());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'boss');
    }

    public function testDeleteAsksForConfirmationAndNeedsCsrfToken(): void
    {
        $this->client->loginUser($this->persistUser('root', UserRole::Admin));
        $member = $this->persistUser('alice');
        $group = $this->persistGroup('Entwicklung');
        $group->addUser($member);
        $this->entityManager->flush();
        $path = '/admin/groups/'.$group->getUuid().'/delete';

        $crawler = $this->client->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'not assigned to any project');

        $this->client->request('POST', $path, ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->entityManager->getRepository(Group::class)->findOneBy(['title' => 'Entwicklung']));

        $this->client->submit($crawler->selectButton('Delete permanently')->form());
        self::assertResponseRedirects('/admin/groups');
        $this->entityManager->clear();
        self::assertNull($this->entityManager->getRepository(Group::class)->findOneBy(['title' => 'Entwicklung']));
        self::assertNotNull($this->entityManager->getRepository(User::class)->findOneBy(['username' => 'alice']));
        $assignments = $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM app_group_user');
        self::assertIsNumeric($assignments);
        self::assertSame(0, (int) $assignments);
    }

    public function testDeleteConfirmationListsAssignedProjects(): void
    {
        $this->client->loginUser($this->persistUser('root', UserRole::Admin));
        $group = $this->persistGroup('Entwicklung');
        foreach (['Security', 'Data protection'] as $title) {
            $project = new Project();
            $project->setTitle($title);
            $project->addGroup($group);
            $this->entityManager->persist($project);
        }
        $this->entityManager->flush();

        $this->client->request('GET', '/admin/groups/'.$group->getUuid().'/delete');

        self::assertSelectorTextContains('ul', 'Data protection');
        self::assertSelectorTextContains('ul', 'Security');
        self::assertSelectorTextNotContains('body', 'not assigned to any project');
    }

    public function testDeletingAGroupMemberUserKeepsTheGroup(): void
    {
        $member = $this->persistUser('alice');
        $group = $this->persistGroup('Entwicklung');
        $group->addUser($member);
        $this->entityManager->flush();

        $this->entityManager->remove($member);
        $this->entityManager->flush();
        $this->entityManager->clear();

        self::assertCount(0, $this->findGroup('Entwicklung')->getUsers());
    }

    public function testRegularUserHasNoAccessToAnyGroupRoute(): void
    {
        $group = $this->persistGroup('Entwicklung');
        $member = $this->persistUser('alice');
        $this->client->loginUser($member);
        $uuid = (string) $group->getUuid();

        foreach ([
            ['GET', '/admin/groups'],
            ['GET', '/admin/groups/new'],
            ['POST', '/admin/groups/new'],
            ['GET', '/admin/groups/'.$uuid],
            ['POST', '/admin/groups/'.$uuid],
            ['POST', '/admin/groups/'.$uuid.'/users'],
            ['POST', \sprintf('/admin/groups/%s/users/%d/remove', $uuid, $member->getId())],
            ['GET', '/admin/groups/'.$uuid.'/delete'],
            ['POST', '/admin/groups/'.$uuid.'/delete'],
        ] as [$method, $path]) {
            $this->client->request($method, $path);
            self::assertResponseStatusCodeSame(403, \sprintf('%s %s', $method, $path));
        }

        self::assertNotNull($this->entityManager->getRepository(Group::class)->findOneBy(['title' => 'Entwicklung']));
    }

    public function testAnonymousUserIsSentToLogin(): void
    {
        $this->client->request('GET', '/admin/groups');

        self::assertResponseRedirects('http://localhost/login');
    }

    public function testUnknownGroupReturns404(): void
    {
        $this->client->loginUser($this->persistUser('root', UserRole::Admin));

        $this->client->request('GET', '/admin/groups/018f3a52-0000-7000-8000-000000000000');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/admin/groups/not-a-uuid');
        self::assertResponseStatusCodeSame(404);
    }

    public function testOverviewSearchEscapesWildcardsAndIgnoresCase(): void
    {
        $this->client->loginUser($this->persistUser('root', UserRole::Admin));
        $this->persistGroup('Team_A');
        $this->persistGroup('TeamXA');
        $this->persistGroup('Betrieb');

        self::assertSame(['Team_A'], $this->titles($this->client->request('GET', '/admin/groups', ['q' => 'team_a'])));
        $matches = $this->titles($this->client->request('GET', '/admin/groups', ['q' => 'TEAM']));
        sort($matches);
        self::assertSame(['TeamXA', 'Team_A'], $matches);
        self::assertSame([], $this->titles($this->client->request('GET', '/admin/groups', ['q' => '%'])));
    }

    public function testOverviewSortsByUserCountAndFallsBackOnUnknownSortField(): void
    {
        $this->client->loginUser($this->persistUser('root', UserRole::Admin));
        $users = [$this->persistUser('alice'), $this->persistUser('bob')];
        $this->persistGroup('A-leer');
        $this->persistGroup('B-voll')->addUser($users[0]);
        $this->persistGroup('C-mittel');
        $this->findGroup('B-voll')->addUser($users[1]);
        $this->findGroup('C-mittel')->addUser($users[0]);
        $this->entityManager->flush();

        self::assertSame(['B-voll', 'C-mittel', 'A-leer'], $this->titles($this->client->request('GET', '/admin/groups', ['sort' => 'users', 'dir' => 'desc'])));
        self::assertSame(['A-leer', 'B-voll', 'C-mittel'], $this->titles($this->client->request('GET', '/admin/groups', ['sort' => 'nonsense'])));
        self::assertSame(['C-mittel', 'B-voll', 'A-leer'], $this->titles($this->client->request('GET', '/admin/groups', ['sort' => 'title', 'dir' => 'desc'])));
    }

    public function testOverviewPaginatesServerSide(): void
    {
        $this->client->loginUser($this->persistUser('root', UserRole::Admin));
        for ($i = 1; 25 >= $i; ++$i) {
            $this->persistGroup(\sprintf('Group %02d', $i));
        }

        self::assertCount(10, $this->titles($this->client->request('GET', '/admin/groups')));
        $page = $this->client->request('GET', '/admin/groups', ['perPage' => 20, 'page' => 2]);
        self::assertCount(5, $this->titles($page));
        self::assertSelectorTextContains('nav[aria-label="Pagination"]', 'Page 2 of 2 (25 entries)');

        $page = $this->client->request('GET', '/admin/groups', ['perPage' => 10, 'page' => 99]);
        self::assertSame(['Group 21', 'Group 22', 'Group 23', 'Group 24', 'Group 25'], $this->titles($page));
    }

    public function testPageSizeInUrlIsRememberedInSessionAndUrlWins(): void
    {
        $this->client->loginUser($this->persistUser('root', UserRole::Admin));
        for ($i = 1; 25 >= $i; ++$i) {
            $this->persistGroup(\sprintf('Group %02d', $i));
        }

        $this->client->request('GET', '/admin/groups', ['perPage' => 50]);
        $crawler = $this->client->request('GET', '/admin/groups');
        self::assertCount(25, $this->titles($crawler));
        self::assertSame('50', $crawler->filter('select[name="perPage"] option[selected]')->attr('value'));

        $crawler = $this->client->request('GET', '/admin/groups', ['perPage' => 20]);
        self::assertCount(20, $this->titles($crawler));
        self::assertCount(20, $this->titles($this->client->request('GET', '/admin/groups')));
    }

    public function testInvalidListParametersAreRejected(): void
    {
        $this->client->loginUser($this->persistUser('root', UserRole::Admin));

        foreach ([['perPage' => 7], ['page' => 0], ['dir' => 'sideways'], ['q' => str_repeat('x', 101)]] as $query) {
            $this->client->request('GET', '/admin/groups', $query);
            self::assertResponseStatusCodeSame(422, http_build_query($query));
        }
    }

    public function testSortLinkKeepsSearchAndPageSize(): void
    {
        $this->client->loginUser($this->persistUser('root', UserRole::Admin));
        $this->persistGroup('Entwicklung');

        $crawler = $this->client->request('GET', '/admin/groups', ['q' => 'ent', 'perPage' => 20, 'sort' => 'title', 'dir' => 'asc']);

        $href = $crawler->filter('thead a')->first()->attr('href');
        self::assertIsString($href);
        parse_str((string) parse_url($href, \PHP_URL_QUERY), $params);
        self::assertSame(['q' => 'ent', 'sort' => 'title', 'dir' => 'desc', 'perPage' => '20', 'page' => '1'], $params);
    }

    public function testTheFirstClickOnTheDefaultSortedTitleColumnReversesTheOrder(): void
    {
        $this->client->loginUser($this->persistUser('root', UserRole::Admin));
        $this->persistGroup('Entwicklung');

        $crawler = $this->client->request('GET', '/admin/groups');
        $header = $crawler->filter('thead th')->first();

        self::assertSame('ascending', $header->attr('aria-sort'));
        parse_str((string) parse_url((string) $header->filter('a')->attr('href'), \PHP_URL_QUERY), $params);
        self::assertSame('title', $params['sort']);
        self::assertSame('desc', $params['dir']);
    }

    public function testPaginationKeepsADescendingDirectionWithoutAnExplicitSortColumn(): void
    {
        $this->client->loginUser($this->persistUser('root', UserRole::Admin));
        for ($i = 1; 12 >= $i; ++$i) {
            $this->persistGroup(\sprintf('Group %02d', $i));
        }

        $crawler = $this->client->request('GET', '/admin/groups', ['dir' => 'desc']);
        self::assertStringContainsString('dir=desc', (string) $crawler->filter('nav[aria-label="Pagination"] a[rel="next"]')->attr('href'));

        self::assertSame(['Group 02', 'Group 01'], $this->titles($this->client->request('GET', '/admin/groups', ['dir' => 'desc', 'page' => 2])));
    }

    public function testSearchTermWithANullByteIsRejected(): void
    {
        $this->client->loginUser($this->persistUser('root', UserRole::Admin));

        $this->client->request('GET', '/admin/groups', ['q' => "a\0b"]);

        self::assertResponseStatusCodeSame(422);
    }

    private function submitNewGroup(string $title): void
    {
        $crawler = $this->client->request('GET', '/admin/groups/new');
        $this->client->submit($crawler->selectButton('Save')->form(['group[title]' => $title]));
    }

    private function setUpdatedAtToThePast(Group $group): void
    {
        $this->entityManager->createQuery('UPDATE '.Group::class.' g SET g.updatedAt = :past WHERE g.id = :id')
            ->setParameter('past', new \DateTimeImmutable('2020-01-01 12:00:00'))
            ->setParameter('id', $group->getId())
            ->execute();
        $this->entityManager->clear();
    }

    private function findGroup(string $title): Group
    {
        $group = $this->entityManager->getRepository(Group::class)->findOneBy(['title' => $title]);
        self::assertInstanceOf(Group::class, $group);

        return $group;
    }
}
