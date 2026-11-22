<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use App\Security\UserRole;
use App\Tests\Support\McpWebTestCase;

final class OverviewToolsTest extends McpWebTestCase
{
    public function testShowsOnlyOwnProjectsRunsAndDrafts(): void
    {
        $user = $this->persistUser('member');
        $own = $this->persistProject('Own project', [$user]);
        $foreign = $this->persistProject('Foreign project');
        $skill = $this->persistSkill('own-skill', [$own], $user);
        $this->startRun($this->persistRun($own, 'Own run', [], [$this->persistTestcase('Case', $own)], true));
        $this->startRun($this->persistRun($foreign, 'Foreign run', [], [$this->persistTestcase('Foreign case', $foreign)], true));
        $token = $this->connect($user);
        $this->callTool($token, 'save_skill', ['skillUuid' => $skill->getUuid()->toRfc4122(), 'asDraft' => true, 'content' => 'changed']);

        $result = $this->callTool($token, 'get_overview');

        self::assertFalse($result['isError'], $result['text']);
        self::assertFalse($result['data']['isAdmin']);
        self::assertSame(['Own project'], array_column($this->listAt($result['data'], 'projects'), 'title'));
        self::assertSame(['Own run'], array_column($this->listAt($result['data'], 'recentRunVersions'), 'runTitle'));
        self::assertSame(['own-skill'], array_column($this->listAt($result['data'], 'skillDrafts'), 'title'));
        self::assertSame([], $result['data']['testcaseDrafts']);
    }

    public function testAdminSeesEverything(): void
    {
        $this->persistProject('First');
        $this->persistProject('Second');

        $result = $this->callTool($this->connect($this->persistUser('boss', UserRole::Admin)), 'get_overview');

        self::assertTrue($result['data']['isAdmin']);
        self::assertSame(['First', 'Second'], array_column($this->listAt($result['data'], 'projects'), 'title'));
    }
}
