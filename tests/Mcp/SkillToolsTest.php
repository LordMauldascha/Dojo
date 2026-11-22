<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use App\Entity\Skill;
use App\Security\UserRole;
use App\Tests\Support\McpWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

final class SkillToolsTest extends McpWebTestCase
{
    public function testListShowsOnlyAccessibleSkillsFiltersAndSorts(): void
    {
        $user = $this->persistUser('member');
        $one = $this->persistProject('Project one', [$user]);
        $two = $this->persistProject('Project two', [$user]);
        $foreign = $this->persistProject('Foreign project');
        $this->persistSkill('Alpha', [$one]);
        $this->persistSkill('Beta', [$two]);
        $this->persistSkill('Hidden', [$foreign]);
        $this->persistSkill('Nobody', []);
        $token = $this->connect($user);

        $all = $this->callTool($token, 'list_skills');
        self::assertFalse($all['isError'], $all['text']);
        self::assertSame(['Alpha', 'Beta'], array_column($this->listAt($all['data'], 'items'), 'title'));
        self::assertSame(2, $all['data']['total']);

        $byProject = $this->callTool($token, 'list_skills', ['projectUuid' => $one->getUuid()->toRfc4122()]);
        self::assertSame(['Alpha'], array_column($this->listAt($byProject['data'], 'items'), 'title'));

        $foreignFilter = $this->callTool($token, 'list_skills', ['projectUuid' => $foreign->getUuid()->toRfc4122()]);
        self::assertSame([], $this->listAt($foreignFilter['data'], 'items'));

        $searched = $this->callTool($token, 'list_skills', ['query' => 'BET']);
        self::assertSame(['Beta'], array_column($this->listAt($searched['data'], 'items'), 'title'));

        $descending = $this->callTool($token, 'list_skills', ['sort' => 'title', 'dir' => 'desc']);
        self::assertSame(['Beta', 'Alpha'], array_column($this->listAt($descending['data'], 'items'), 'title'));
    }

    public function testAdminListsSkillsWithoutProject(): void
    {
        $this->persistSkill('Nobody', []);

        $result = $this->callTool($this->connect($this->persistUser('boss', UserRole::Admin)), 'list_skills');

        self::assertSame(['Nobody'], array_column($this->listAt($result['data'], 'items'), 'title'));
    }

    public function testListRejectsInvalidArguments(): void
    {
        $token = $this->connect($this->persistUser('member'));

        self::assertTrue($this->callTool($token, 'list_skills', ['perPage' => 7])['isError']);
        self::assertTrue($this->callTool($token, 'list_skills', ['dir' => 'sideways'])['isError']);
        self::assertTrue($this->callTool($token, 'list_skills', ['projectUuid' => 'nope'])['isError']);
    }

    public function testForeignSkillIsForbiddenForEveryTool(): void
    {
        $skill = $this->persistSkill('Hidden', [$this->persistProject('Foreign project')]);
        $uuid = $skill->getUuid()->toRfc4122();
        $token = $this->connect($this->persistUser('member'));

        $calls = [
            'get_skill' => [],
            'get_skill_version' => ['number' => 1],
            'save_skill' => ['asDraft' => true, 'content' => 'x'],
            'set_skill_projects' => ['projectUuids' => []],
            'restore_skill_version' => ['number' => 1],
            'discard_skill_draft' => [],
            'delete_skill' => [],
        ];
        foreach ($calls as $name => $arguments) {
            $result = $this->callTool($token, $name, ['skillUuid' => $uuid] + $arguments);
            self::assertTrue($result['isError'], $name);
            self::assertStringStartsWith('Forbidden', $result['text'], $name);
        }
    }

    public function testUnknownAndMalformedUuidsAreToldApart(): void
    {
        $token = $this->connect($this->persistUser('member'));

        self::assertSame('Skill not found.', $this->callTool($token, 'get_skill', ['skillUuid' => '019f0000-0000-7000-8000-000000000000'])['text']);
        self::assertStringContainsString('not a valid uuid', $this->callTool($token, 'get_skill', ['skillUuid' => 'nope'])['text']);
    }

    public function testMemberCreatesWithAnAccessibleProjectOnly(): void
    {
        $member = $this->persistUser('member');
        $own = $this->persistProject('Own', [$member]);
        $foreign = $this->persistProject('Foreign');
        $token = $this->connect($member);
        $arguments = ['title' => 'Review', 'content' => 'Do it'];

        $none = $this->callTool($token, 'create_skill', $arguments);
        self::assertTrue($none['isError']);
        self::assertStringContainsString('at least one project', $none['text']);

        $inaccessible = $this->callTool($token, 'create_skill', $arguments + ['projectUuids' => [$foreign->getUuid()->toRfc4122()]]);
        self::assertSame('Project not found.', $inaccessible['text']);

        $created = $this->callTool($token, 'create_skill', $arguments + ['description' => '  Short  ', 'projectUuids' => [$own->getUuid()->toRfc4122()]]);
        self::assertFalse($created['isError'], $created['text']);
        self::assertSame('Review', $created['data']['title']);
        self::assertSame('Short', $created['data']['description']);
        self::assertSame(['Own'], array_column($this->listAt($created['data'], 'projects'), 'title'));
        self::assertSame(1, $this->listAt($created['data'], 'current')['number']);
        self::assertNull($created['data']['draft']);
    }

    public function testAdminMayCreateWithoutProject(): void
    {
        $result = $this->callTool($this->connect($this->persistUser('boss', UserRole::Admin)), 'create_skill', ['title' => 'Review', 'content' => 'Do it']);

        self::assertFalse($result['isError'], $result['text']);
        self::assertSame([], $this->listAt($result['data'], 'projects'));
    }

    public function testCreateValidatesTheInput(): void
    {
        $token = $this->connect($this->persistUser('boss', UserRole::Admin));

        $blank = $this->callTool($token, 'create_skill', ['title' => '   ', 'content' => 'Do it']);
        $name = $this->callTool($token, 'create_skill', ['title' => 'Bad_name!', 'content' => 'Do it']);
        $content = $this->callTool($token, 'create_skill', ['title' => 'Review', 'content' => '   ']);
        $nul = $this->callTool($token, 'create_skill', ['title' => 'Review', 'content' => "a\0b"]);

        self::assertTrue($blank['isError']);
        self::assertTrue($name['isError']);
        self::assertStringContainsString('letters, digits', $name['text']);
        self::assertTrue($content['isError']);
        self::assertStringContainsString('content', $content['text']);
        self::assertTrue($nul['isError']);
    }

    public function testSaveDraftThenFinalNumbersTheVersions(): void
    {
        $member = $this->persistUser('member');
        $skill = $this->persistSkill('Review', [$this->persistProject('Own', [$member])], $member, 'Do it');
        $uuid = $skill->getUuid()->toRfc4122();
        $token = $this->connect($member);

        $draft = $this->callTool($token, 'save_skill', ['skillUuid' => $uuid, 'asDraft' => true, 'content' => 'Draft text']);
        self::assertFalse($draft['isError'], $draft['text']);
        self::assertSame('2-draft', $draft['data']['saved']);
        self::assertSame(1, $this->listAt($draft['data'], 'current')['number']);
        self::assertSame('Do it', $this->listAt($draft['data'], 'current')['content']);
        self::assertSame(2, $this->listAt($draft['data'], 'draft')['number']);

        $again = $this->callTool($token, 'save_skill', ['skillUuid' => $uuid, 'asDraft' => true, 'description' => 'More']);
        self::assertSame('2-draft', $again['data']['saved']);
        self::assertSame('Draft text', $this->listAt($again['data'], 'draft')['content']);
        self::assertSame('More', $this->listAt($again['data'], 'draft')['description']);

        $final = $this->callTool($token, 'save_skill', ['skillUuid' => $uuid, 'asDraft' => false, 'content' => 'Final text']);
        self::assertSame('2', $final['data']['saved']);
        self::assertNull($final['data']['draft']);
        self::assertSame(2, $this->listAt($final['data'], 'current')['number']);
        self::assertSame('Final text', $this->listAt($final['data'], 'current')['content']);
        self::assertCount(2, $this->listAt($final['data'], 'versions'));

        $next = $this->callTool($token, 'save_skill', ['skillUuid' => $uuid, 'asDraft' => false, 'title' => 'Review two']);
        self::assertSame('3', $next['data']['saved']);

        $old = $this->callTool($token, 'get_skill_version', ['skillUuid' => $uuid, 'number' => 1]);
        self::assertSame('Do it', $old['data']['content']);
        self::assertFalse($old['data']['draft']);
        self::assertSame('Version not found.', $this->callTool($token, 'get_skill_version', ['skillUuid' => $uuid, 'number' => 9])['text']);
    }

    public function testSaveValidatesTheInput(): void
    {
        $member = $this->persistUser('member');
        $skill = $this->persistSkill('Review', [$this->persistProject('Own', [$member])], $member);

        $result = $this->callTool($this->connect($member), 'save_skill', ['skillUuid' => $skill->getUuid()->toRfc4122(), 'asDraft' => false, 'title' => 'Bad_name!']);

        self::assertTrue($result['isError']);
        self::assertStringContainsString('letters, digits', $result['text']);
    }

    public function testRestoreAndDiscardFollowTheWorkflow(): void
    {
        $member = $this->persistUser('member');
        $skill = $this->persistSkill('Review', [$this->persistProject('Own', [$member])], $member, 'v1');
        $uuid = $skill->getUuid()->toRfc4122();
        $token = $this->connect($member);
        $this->callTool($token, 'save_skill', ['skillUuid' => $uuid, 'asDraft' => false, 'content' => 'v2']);
        $this->callTool($token, 'save_skill', ['skillUuid' => $uuid, 'asDraft' => true, 'content' => 'draft']);

        $blocked = $this->callTool($token, 'restore_skill_version', ['skillUuid' => $uuid, 'number' => 1]);
        self::assertTrue($blocked['isError']);
        self::assertStringContainsString('A draft exists', $blocked['text']);

        $discarded = $this->callTool($token, 'discard_skill_draft', ['skillUuid' => $uuid]);
        self::assertFalse($discarded['isError'], $discarded['text']);
        self::assertNull($discarded['data']['draft']);
        self::assertSame('There is no draft to discard.', $this->callTool($token, 'discard_skill_draft', ['skillUuid' => $uuid])['text']);

        $restored = $this->callTool($token, 'restore_skill_version', ['skillUuid' => $uuid, 'number' => 1]);
        self::assertFalse($restored['isError'], $restored['text']);
        self::assertSame(3, $this->listAt($restored['data'], 'current')['number']);
        self::assertSame('v1', $this->listAt($restored['data'], 'current')['content']);
        self::assertSame('Version not found.', $this->callTool($token, 'restore_skill_version', ['skillUuid' => $uuid, 'number' => 99])['text']);
    }

    public function testSetProjectsPrunesRunsOfRemovedProjects(): void
    {
        $member = $this->persistUser('member');
        $a = $this->persistProject('Project A', [$member]);
        $b = $this->persistProject('Project B', [$member]);
        $skill = $this->persistSkill('Review', [$a, $b]);
        $this->persistRun($a, 'Run A', [$skill]);
        $this->persistRun($b, 'Run B', [$skill]);

        $result = $this->callTool($this->connect($member), 'set_skill_projects', ['skillUuid' => $skill->getUuid()->toRfc4122(), 'projectUuids' => [$b->getUuid()->toRfc4122()]]);

        self::assertFalse($result['isError'], $result['text']);
        self::assertSame(['Project B'], array_column($this->listAt($result['data'], 'projects'), 'title'));
        self::assertSame(['Run B'], $this->entityManager->getConnection()->fetchFirstColumn('SELECT r.title FROM run_skill rs JOIN run r ON r.id = rs.run_id'));
    }

    public function testMemberKeepsForeignProjectsAndTheLastOne(): void
    {
        $member = $this->persistUser('member');
        $own = $this->persistProject('Own', [$member]);
        $foreign = $this->persistProject('Foreign');
        $onlyOwn = $this->persistSkill('Only own', [$own]);
        $both = $this->persistSkill('Both', [$own, $foreign]);
        $token = $this->connect($member);

        $last = $this->callTool($token, 'set_skill_projects', ['skillUuid' => $onlyOwn->getUuid()->toRfc4122(), 'projectUuids' => []]);
        self::assertTrue($last['isError']);
        self::assertStringContainsString('at least one project', $last['text']);

        $inaccessible = $this->callTool($token, 'set_skill_projects', ['skillUuid' => $onlyOwn->getUuid()->toRfc4122(), 'projectUuids' => [$foreign->getUuid()->toRfc4122()]]);
        self::assertSame('Project not found.', $inaccessible['text']);

        $released = $this->callTool($token, 'set_skill_projects', ['skillUuid' => $both->getUuid()->toRfc4122(), 'projectUuids' => []]);
        self::assertFalse($released['isError'], $released['text']);
        self::assertTrue($released['data']['accessLost']);
        $this->entityManager->clear();
        self::assertSame(['Foreign'], $this->projectTitles($this->reload($both)));
    }

    public function testAdminMayRemoveAllProjects(): void
    {
        $skill = $this->persistSkill('Review', [$this->persistProject('Own')]);

        $result = $this->callTool($this->connect($this->persistUser('boss', UserRole::Admin)), 'set_skill_projects', ['skillUuid' => $skill->getUuid()->toRfc4122(), 'projectUuids' => []]);

        self::assertFalse($result['isError'], $result['text']);
        self::assertSame([], $this->listAt($result['data'], 'projects'));
    }

    public function testOnlyCreatorAndAdminDeleteAndDeletedSkillsAreGone(): void
    {
        $creator = $this->persistUser('creator');
        $colleague = $this->persistUser('colleague');
        $project = $this->persistProject('Shared', [$creator, $colleague]);
        $mine = $this->persistSkill('Mine', [$project], $creator);
        $other = $this->persistSkill('Other', [$project], $creator);
        $this->persistRun($project, 'Run', [$mine]);
        $colleagueToken = $this->connect($colleague);

        $denied = $this->callTool($colleagueToken, 'delete_skill', ['skillUuid' => $mine->getUuid()->toRfc4122()]);
        self::assertStringStartsWith('Forbidden', $denied['text']);

        $creatorToken = $this->connect($creator);
        $deleted = $this->callTool($creatorToken, 'delete_skill', ['skillUuid' => $mine->getUuid()->toRfc4122()]);
        self::assertFalse($deleted['isError'], $deleted['text']);
        self::assertTrue($deleted['data']['deleted']);
        self::assertSame('Skill not found.', $this->callTool($creatorToken, 'get_skill', ['skillUuid' => $mine->getUuid()->toRfc4122()])['text']);
        self::assertSame(['Other'], array_column($this->listAt($this->callTool($creatorToken, 'list_skills')['data'], 'items'), 'title'));
        self::assertSame(0, $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM run_skill'));

        $adminDeleted = $this->callTool($this->connect($this->persistUser('boss', UserRole::Admin)), 'delete_skill', ['skillUuid' => $other->getUuid()->toRfc4122()]);
        self::assertFalse($adminDeleted['isError'], $adminDeleted['text']);
    }

    public function testStaleSaveIsRefusedAndKeepsTheVersions(): void
    {
        $member = $this->persistUser('member');
        $skill = $this->persistSkill('Review', [$this->persistProject('Own', [$member])], $member, 'content 1');
        $token = $this->connect($member);

        $this->client->disableReboot();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $connection = $entityManager->getConnection();
        $entityManager->getEventManager()->addEventListener(Events::onFlush, new class($connection) {
            public function __construct(private readonly Connection $connection)
            {
            }

            public function onFlush(OnFlushEventArgs $args): void
            {
                foreach ($args->getObjectManager()->getUnitOfWork()->getScheduledEntityUpdates() as $entity) {
                    if ($entity instanceof Skill) {
                        $this->connection->executeStatement('UPDATE skill SET lock_version = lock_version + 1');
                    }
                }
            }
        });

        $result = $this->callTool($token, 'save_skill', ['skillUuid' => $skill->getUuid()->toRfc4122(), 'asDraft' => false, 'content' => 'content 2']);

        self::assertTrue($result['isError']);
        self::assertStringContainsString('changed by someone else', $result['text']);
        self::assertSame(['content 1'], $connection->fetchFirstColumn('SELECT content FROM skill_version'));
    }

    /** @return list<string> */
    private function projectTitles(Skill $skill): array
    {
        return array_values($skill->getProjects()->map(static fn ($project): string => $project->getTitle())->toArray());
    }
}
