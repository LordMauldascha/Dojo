<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use App\Security\UserRole;
use App\Tests\Support\McpWebTestCase;

final class ProjectToolsTest extends McpWebTestCase
{
    public function testListShowsOnlyProjectsOfTheUser(): void
    {
        $user = $this->persistUser('member');
        $this->persistProject('Own project', [$user]);
        $this->persistProject('Foreign project');

        $result = $this->callTool($this->connect($user), 'list_projects');

        self::assertFalse($result['isError'], $result['text']);
        self::assertSame(['Own project'], array_column($this->listAt($result['data'], 'items'), 'title'));
        self::assertSame(1, $result['data']['total']);
    }

    public function testAdminListsAllProjects(): void
    {
        $this->persistProject('First');
        $this->persistProject('Second');

        $result = $this->callTool($this->connect($this->persistUser('boss', UserRole::Admin)), 'list_projects');

        self::assertSame(['First', 'Second'], array_column($this->listAt($result['data'], 'items'), 'title'));
    }

    public function testListRejectsAnInvalidPageSize(): void
    {
        $result = $this->callTool($this->connect($this->persistUser('member')), 'list_projects', ['perPage' => 7]);

        self::assertTrue($result['isError']);
    }

    public function testUserCannotViewAForeignProject(): void
    {
        $foreign = $this->persistProject('Foreign project');

        $result = $this->callTool($this->connect($this->persistUser('member')), 'get_project', ['projectUuid' => $foreign->getUuid()->toRfc4122()]);

        self::assertTrue($result['isError']);
        self::assertStringStartsWith('Forbidden', $result['text']);
    }

    public function testUnknownAndMalformedUuidsAreToldApart(): void
    {
        $token = $this->connect($this->persistUser('member'));

        $unknown = $this->callTool($token, 'get_project', ['projectUuid' => '019f0000-0000-7000-8000-000000000000']);
        $malformed = $this->callTool($token, 'get_project', ['projectUuid' => 'not-a-uuid']);

        self::assertSame('Project not found.', $unknown['text']);
        self::assertStringContainsString('not a valid uuid', $malformed['text']);
    }

    public function testMemberSeesCountsButNoMembers(): void
    {
        $user = $this->persistUser('member');
        $project = $this->persistProject('Own project', [$user]);

        $result = $this->callTool($this->connect($user), 'get_project', ['projectUuid' => $project->getUuid()->toRfc4122()]);

        self::assertSame(0, $result['data']['skillCount']);
        self::assertArrayNotHasKey('users', $result['data']);
        self::assertArrayNotHasKey('assignableUsers', $result['data']);
    }

    public function testUserCannotCreateOrManageProjects(): void
    {
        $user = $this->persistUser('member');
        $project = $this->persistProject('Own project', [$user]);
        $token = $this->connect($user);

        self::assertTrue($this->callTool($token, 'create_project', ['title' => 'New'])['isError']);
        self::assertTrue($this->callTool($token, 'update_project', ['projectUuid' => $project->getUuid()->toRfc4122(), 'title' => 'Renamed'])['isError']);
        self::assertTrue($this->callTool($token, 'delete_project', ['projectUuid' => $project->getUuid()->toRfc4122()])['isError']);
        self::assertTrue($this->callTool($token, 'add_project_user', ['projectUuid' => $project->getUuid()->toRfc4122(), 'username' => 'member'])['isError']);
    }

    public function testAdminCreatesUpdatesAndDeletesAProject(): void
    {
        $token = $this->connect($this->persistUser('boss', UserRole::Admin));

        $created = $this->callTool($token, 'create_project', ['title' => 'Karate', 'description' => 'Katas']);
        self::assertFalse($created['isError'], $created['text']);
        $uuid = $created['data']['uuid'];
        self::assertSame('Karate', $created['data']['title']);
        self::assertSame([], $created['data']['users']);

        $updated = $this->callTool($token, 'update_project', ['projectUuid' => $uuid, 'description' => '']);
        self::assertNull($updated['data']['description']);

        self::assertFalse($this->callTool($token, 'delete_project', ['projectUuid' => $uuid])['isError']);
        self::assertSame('Project not found.', $this->callTool($token, 'get_project', ['projectUuid' => $uuid])['text']);
    }

    public function testAdminCannotCreateADuplicateOrBlankTitle(): void
    {
        $this->persistProject('Karate');
        $token = $this->connect($this->persistUser('boss', UserRole::Admin));

        $duplicate = $this->callTool($token, 'create_project', ['title' => 'KARATE']);
        $blank = $this->callTool($token, 'create_project', ['title' => '   ']);

        self::assertTrue($duplicate['isError']);
        self::assertStringContainsString('already taken', $duplicate['text']);
        self::assertTrue($blank['isError']);
    }

    public function testAdminManagesGroupsAndUsersOfAProject(): void
    {
        $member = $this->persistUser('member');
        $group = $this->persistGroup('Sensei');
        $project = $this->persistProject('Karate');
        $uuid = $project->getUuid()->toRfc4122();
        $token = $this->connect($this->persistUser('boss', UserRole::Admin));

        $before = $this->callTool($token, 'get_project', ['projectUuid' => $uuid]);
        self::assertContains('member', $this->listAt($before['data'], 'assignableUsers'));

        $this->callTool($token, 'add_project_user', ['projectUuid' => $uuid, 'username' => 'member']);
        $this->callTool($token, 'add_project_group', ['projectUuid' => $uuid, 'groupUuid' => $group->getUuid()->toRfc4122()]);
        $added = $this->callTool($token, 'get_project', ['projectUuid' => $uuid]);
        self::assertSame(['member'], $added['data']['users']);
        self::assertSame(['Sensei'], array_column($this->listAt($added['data'], 'groups'), 'title'));

        $memberResult = $this->callTool($this->connect($member), 'list_projects');
        self::assertSame(['Karate'], array_column($this->listAt($memberResult['data'], 'items'), 'title'));

        $this->callTool($token, 'remove_project_user', ['projectUuid' => $uuid, 'username' => 'member']);
        $this->callTool($token, 'remove_project_group', ['projectUuid' => $uuid, 'groupUuid' => $group->getUuid()->toRfc4122()]);
        $removed = $this->callTool($token, 'get_project', ['projectUuid' => $uuid]);
        self::assertSame([], $removed['data']['users']);
        self::assertSame([], $removed['data']['groups']);
    }

    public function testUnknownUsernameIsReported(): void
    {
        $project = $this->persistProject('Karate');
        $token = $this->connect($this->persistUser('boss', UserRole::Admin));

        $result = $this->callTool($token, 'add_project_user', ['projectUuid' => $project->getUuid()->toRfc4122(), 'username' => 'nobody']);

        self::assertSame('User not found.', $result['text']);
    }
}
