<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use App\Entity\Testcase;
use App\Security\UserRole;
use App\Tests\Support\McpWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

final class TestcaseToolsTest extends McpWebTestCase
{
    private const NEW_TESTCASE = ['title' => 'Greeting', 'prompt' => 'Say hello', 'outputCriteria' => 'Greets politely'];

    public function testListShowsOnlyAccessibleTestcasesFiltersAndSorts(): void
    {
        $user = $this->persistUser('member');
        $one = $this->persistProject('Project one', [$user]);
        $two = $this->persistProject('Project two', [$user]);
        $foreign = $this->persistProject('Foreign project');
        $this->persistTestcase('Alpha', $one);
        $this->persistTestcase('Beta', $two);
        $this->persistTestcase('Hidden', $foreign);
        $this->persistTestcase('Nobody');
        $token = $this->connect($user);

        $all = $this->callTool($token, 'list_testcases');
        self::assertFalse($all['isError'], $all['text']);
        self::assertSame(['Alpha', 'Beta'], array_column($this->listAt($all['data'], 'items'), 'title'));
        self::assertSame(2, $all['data']['total']);

        $byProject = $this->callTool($token, 'list_testcases', ['projectUuid' => $one->getUuid()->toRfc4122()]);
        self::assertSame(['Alpha'], array_column($this->listAt($byProject['data'], 'items'), 'title'));

        $foreignFilter = $this->callTool($token, 'list_testcases', ['projectUuid' => $foreign->getUuid()->toRfc4122()]);
        self::assertSame([], $this->listAt($foreignFilter['data'], 'items'));

        $searched = $this->callTool($token, 'list_testcases', ['query' => 'BET']);
        self::assertSame(['Beta'], array_column($this->listAt($searched['data'], 'items'), 'title'));

        $descending = $this->callTool($token, 'list_testcases', ['sort' => 'title', 'dir' => 'desc']);
        self::assertSame(['Beta', 'Alpha'], array_column($this->listAt($descending['data'], 'items'), 'title'));
    }

    public function testAdminListsTestcasesWithoutProject(): void
    {
        $this->persistTestcase('Nobody');

        $result = $this->callTool($this->connect($this->persistUser('boss', UserRole::Admin)), 'list_testcases');

        self::assertSame(['Nobody'], array_column($this->listAt($result['data'], 'items'), 'title'));
    }

    public function testListRejectsInvalidArguments(): void
    {
        $token = $this->connect($this->persistUser('member'));

        self::assertTrue($this->callTool($token, 'list_testcases', ['perPage' => 7])['isError']);
        self::assertTrue($this->callTool($token, 'list_testcases', ['dir' => 'sideways'])['isError']);
        self::assertTrue($this->callTool($token, 'list_testcases', ['projectUuid' => 'nope'])['isError']);
    }

    public function testForeignTestcaseIsForbiddenForEveryTool(): void
    {
        $testcase = $this->persistTestcase('Hidden', $this->persistProject('Foreign project'));
        $uuid = $testcase->getUuid()->toRfc4122();
        $token = $this->connect($this->persistUser('member'));

        $calls = [
            'get_testcase' => [],
            'get_testcase_version' => ['number' => 1],
            'save_testcase' => ['asDraft' => true, 'prompt' => 'x'],
            'set_testcase_projects' => ['projectUuids' => []],
            'restore_testcase_version' => ['number' => 1],
            'discard_testcase_draft' => [],
            'delete_testcase' => [],
        ];
        foreach ($calls as $name => $arguments) {
            $result = $this->callTool($token, $name, ['testcaseUuid' => $uuid] + $arguments);
            self::assertTrue($result['isError'], $name);
            self::assertStringStartsWith('Forbidden', $result['text'], $name);
        }
    }

    public function testUnknownAndMalformedUuidsAreToldApart(): void
    {
        $token = $this->connect($this->persistUser('member'));

        self::assertSame('Testcase not found.', $this->callTool($token, 'get_testcase', ['testcaseUuid' => '019f0000-0000-7000-8000-000000000000'])['text']);
        self::assertStringContainsString('not a valid uuid', $this->callTool($token, 'get_testcase', ['testcaseUuid' => 'nope'])['text']);
    }

    public function testMemberCreatesWithAnAccessibleProjectOnly(): void
    {
        $member = $this->persistUser('member');
        $own = $this->persistProject('Own', [$member]);
        $foreign = $this->persistProject('Foreign');
        $token = $this->connect($member);

        $none = $this->callTool($token, 'create_testcase', self::NEW_TESTCASE);
        self::assertTrue($none['isError']);
        self::assertStringContainsString('at least one project', $none['text']);

        $inaccessible = $this->callTool($token, 'create_testcase', self::NEW_TESTCASE + ['projectUuids' => [$foreign->getUuid()->toRfc4122()]]);
        self::assertSame('Project not found.', $inaccessible['text']);

        $created = $this->callTool($token, 'create_testcase', self::NEW_TESTCASE + ['description' => '  Short  ', 'projectUuids' => [$own->getUuid()->toRfc4122()]]);
        self::assertFalse($created['isError'], $created['text']);
        self::assertSame('Greeting', $created['data']['title']);
        self::assertSame('Short', $created['data']['description']);
        self::assertSame(['Own'], array_column($this->listAt($created['data'], 'projects'), 'title'));
        self::assertSame('Say hello', $this->listAt($created['data'], 'current')['prompt']);
        self::assertSame('Greets politely', $this->listAt($created['data'], 'current')['outputCriteria']);
        self::assertNull($created['data']['draft']);
    }

    public function testAdminMayCreateWithoutProject(): void
    {
        $result = $this->callTool($this->connect($this->persistUser('boss', UserRole::Admin)), 'create_testcase', self::NEW_TESTCASE);

        self::assertFalse($result['isError'], $result['text']);
        self::assertSame([], $this->listAt($result['data'], 'projects'));
    }

    public function testCreateValidatesTheInput(): void
    {
        $token = $this->connect($this->persistUser('boss', UserRole::Admin));

        $blank = $this->callTool($token, 'create_testcase', ['title' => '   '] + self::NEW_TESTCASE);
        $prompt = $this->callTool($token, 'create_testcase', ['prompt' => '   '] + self::NEW_TESTCASE);
        $criteria = $this->callTool($token, 'create_testcase', ['outputCriteria' => ''] + self::NEW_TESTCASE);
        $nul = $this->callTool($token, 'create_testcase', ['prompt' => "a\0b"] + self::NEW_TESTCASE);

        self::assertTrue($blank['isError']);
        self::assertStringContainsString('title', $blank['text']);
        self::assertTrue($prompt['isError']);
        self::assertStringContainsString('prompt', $prompt['text']);
        self::assertTrue($criteria['isError']);
        self::assertStringContainsString('outputCriteria', $criteria['text']);
        self::assertTrue($nul['isError']);
    }

    public function testSaveDraftThenFinalNumbersTheVersions(): void
    {
        $member = $this->persistUser('member');
        $testcase = $this->persistTestcase('Greeting', $this->persistProject('Own', [$member]), $member, 'Say hello', 'Greets');
        $uuid = $testcase->getUuid()->toRfc4122();
        $token = $this->connect($member);

        $draft = $this->callTool($token, 'save_testcase', ['testcaseUuid' => $uuid, 'asDraft' => true, 'prompt' => 'Draft prompt']);
        self::assertFalse($draft['isError'], $draft['text']);
        self::assertSame('2-draft', $draft['data']['saved']);
        self::assertSame(1, $this->listAt($draft['data'], 'current')['number']);
        self::assertSame('Say hello', $this->listAt($draft['data'], 'current')['prompt']);
        self::assertSame(2, $this->listAt($draft['data'], 'draft')['number']);

        $again = $this->callTool($token, 'save_testcase', ['testcaseUuid' => $uuid, 'asDraft' => true, 'outputCriteria' => 'Draft criteria']);
        self::assertSame('2-draft', $again['data']['saved']);
        self::assertSame('Draft prompt', $this->listAt($again['data'], 'draft')['prompt']);
        self::assertSame('Draft criteria', $this->listAt($again['data'], 'draft')['outputCriteria']);

        $final = $this->callTool($token, 'save_testcase', ['testcaseUuid' => $uuid, 'asDraft' => false, 'prompt' => 'Final prompt']);
        self::assertSame('2', $final['data']['saved']);
        self::assertNull($final['data']['draft']);
        self::assertSame(2, $this->listAt($final['data'], 'current')['number']);
        self::assertSame('Final prompt', $this->listAt($final['data'], 'current')['prompt']);
        self::assertCount(2, $this->listAt($final['data'], 'versions'));

        $next = $this->callTool($token, 'save_testcase', ['testcaseUuid' => $uuid, 'asDraft' => false, 'title' => 'Greeting two']);
        self::assertSame('3', $next['data']['saved']);

        $old = $this->callTool($token, 'get_testcase_version', ['testcaseUuid' => $uuid, 'number' => 1]);
        self::assertSame('Say hello', $old['data']['prompt']);
        self::assertSame('Greets', $old['data']['outputCriteria']);
        self::assertFalse($old['data']['draft']);
        self::assertSame('Version not found.', $this->callTool($token, 'get_testcase_version', ['testcaseUuid' => $uuid, 'number' => 9])['text']);
    }

    public function testSaveValidatesTheInput(): void
    {
        $member = $this->persistUser('member');
        $testcase = $this->persistTestcase('Greeting', $this->persistProject('Own', [$member]), $member);

        $result = $this->callTool($this->connect($member), 'save_testcase', ['testcaseUuid' => $testcase->getUuid()->toRfc4122(), 'asDraft' => false, 'prompt' => '  ']);

        self::assertTrue($result['isError']);
        self::assertStringContainsString('prompt', $result['text']);
    }

    public function testRestoreAndDiscardFollowTheWorkflow(): void
    {
        $member = $this->persistUser('member');
        $testcase = $this->persistTestcase('Greeting', $this->persistProject('Own', [$member]), $member, 'v1');
        $uuid = $testcase->getUuid()->toRfc4122();
        $token = $this->connect($member);
        $this->callTool($token, 'save_testcase', ['testcaseUuid' => $uuid, 'asDraft' => false, 'prompt' => 'v2']);
        $this->callTool($token, 'save_testcase', ['testcaseUuid' => $uuid, 'asDraft' => true, 'prompt' => 'draft']);

        $blocked = $this->callTool($token, 'restore_testcase_version', ['testcaseUuid' => $uuid, 'number' => 1]);
        self::assertTrue($blocked['isError']);
        self::assertStringContainsString('A draft exists', $blocked['text']);

        $discarded = $this->callTool($token, 'discard_testcase_draft', ['testcaseUuid' => $uuid]);
        self::assertFalse($discarded['isError'], $discarded['text']);
        self::assertNull($discarded['data']['draft']);
        self::assertSame('There is no draft to discard.', $this->callTool($token, 'discard_testcase_draft', ['testcaseUuid' => $uuid])['text']);

        $restored = $this->callTool($token, 'restore_testcase_version', ['testcaseUuid' => $uuid, 'number' => 1]);
        self::assertFalse($restored['isError'], $restored['text']);
        self::assertSame(3, $this->listAt($restored['data'], 'current')['number']);
        self::assertSame('v1', $this->listAt($restored['data'], 'current')['prompt']);
        self::assertSame('Version not found.', $this->callTool($token, 'restore_testcase_version', ['testcaseUuid' => $uuid, 'number' => 99])['text']);
    }

    public function testSetProjectsPrunesRunsOfRemovedProjects(): void
    {
        $member = $this->persistUser('member');
        $a = $this->persistProject('Project A', [$member]);
        $b = $this->persistProject('Project B', [$member]);
        $testcase = $this->persistTestcase('Greeting', [$a, $b]);
        $this->persistRun($a, 'Run A', [], [$testcase]);
        $this->persistRun($b, 'Run B', [], [$testcase]);

        $result = $this->callTool($this->connect($member), 'set_testcase_projects', ['testcaseUuid' => $testcase->getUuid()->toRfc4122(), 'projectUuids' => [$b->getUuid()->toRfc4122()]]);

        self::assertFalse($result['isError'], $result['text']);
        self::assertSame(['Project B'], array_column($this->listAt($result['data'], 'projects'), 'title'));
        self::assertSame(['Run B'], $this->entityManager->getConnection()->fetchFirstColumn('SELECT r.title FROM run_testcase rt JOIN run r ON r.id = rt.run_id'));
    }

    public function testMemberKeepsForeignProjectsAndTheLastOne(): void
    {
        $member = $this->persistUser('member');
        $own = $this->persistProject('Own', [$member]);
        $foreign = $this->persistProject('Foreign');
        $onlyOwn = $this->persistTestcase('Only own', $own);
        $both = $this->persistTestcase('Both', [$own, $foreign]);
        $token = $this->connect($member);

        $last = $this->callTool($token, 'set_testcase_projects', ['testcaseUuid' => $onlyOwn->getUuid()->toRfc4122(), 'projectUuids' => []]);
        self::assertTrue($last['isError']);
        self::assertStringContainsString('at least one project', $last['text']);

        $inaccessible = $this->callTool($token, 'set_testcase_projects', ['testcaseUuid' => $onlyOwn->getUuid()->toRfc4122(), 'projectUuids' => [$foreign->getUuid()->toRfc4122()]]);
        self::assertSame('Project not found.', $inaccessible['text']);

        $released = $this->callTool($token, 'set_testcase_projects', ['testcaseUuid' => $both->getUuid()->toRfc4122(), 'projectUuids' => []]);
        self::assertFalse($released['isError'], $released['text']);
        self::assertTrue($released['data']['accessLost']);
        $this->entityManager->clear();
        self::assertSame(['Foreign'], $this->projectTitles($this->reload($both)));
    }

    public function testAdminMayRemoveAllProjects(): void
    {
        $testcase = $this->persistTestcase('Greeting', $this->persistProject('Own'));

        $result = $this->callTool($this->connect($this->persistUser('boss', UserRole::Admin)), 'set_testcase_projects', ['testcaseUuid' => $testcase->getUuid()->toRfc4122(), 'projectUuids' => []]);

        self::assertFalse($result['isError'], $result['text']);
        self::assertSame([], $this->listAt($result['data'], 'projects'));
    }

    public function testOnlyCreatorAndAdminDeleteAndDeletedTestcasesAreGone(): void
    {
        $creator = $this->persistUser('creator');
        $colleague = $this->persistUser('colleague');
        $project = $this->persistProject('Shared', [$creator, $colleague]);
        $mine = $this->persistTestcase('Mine', $project, $creator);
        $other = $this->persistTestcase('Other', $project, $creator);
        $this->persistRun($project, 'Run', [], [$mine]);
        $colleagueToken = $this->connect($colleague);

        $denied = $this->callTool($colleagueToken, 'delete_testcase', ['testcaseUuid' => $mine->getUuid()->toRfc4122()]);
        self::assertStringStartsWith('Forbidden', $denied['text']);

        $creatorToken = $this->connect($creator);
        $deleted = $this->callTool($creatorToken, 'delete_testcase', ['testcaseUuid' => $mine->getUuid()->toRfc4122()]);
        self::assertFalse($deleted['isError'], $deleted['text']);
        self::assertTrue($deleted['data']['deleted']);
        self::assertSame('Testcase not found.', $this->callTool($creatorToken, 'get_testcase', ['testcaseUuid' => $mine->getUuid()->toRfc4122()])['text']);
        self::assertSame(['Other'], array_column($this->listAt($this->callTool($creatorToken, 'list_testcases')['data'], 'items'), 'title'));
        self::assertSame(0, $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM run_testcase'));

        $adminDeleted = $this->callTool($this->connect($this->persistUser('boss', UserRole::Admin)), 'delete_testcase', ['testcaseUuid' => $other->getUuid()->toRfc4122()]);
        self::assertFalse($adminDeleted['isError'], $adminDeleted['text']);
    }

    public function testStaleSaveIsRefusedAndKeepsTheVersions(): void
    {
        $member = $this->persistUser('member');
        $testcase = $this->persistTestcase('Greeting', $this->persistProject('Own', [$member]), $member, 'prompt 1');
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
                    if ($entity instanceof Testcase) {
                        $this->connection->executeStatement('UPDATE testcase SET lock_version = lock_version + 1');
                    }
                }
            }
        });

        $result = $this->callTool($token, 'save_testcase', ['testcaseUuid' => $testcase->getUuid()->toRfc4122(), 'asDraft' => false, 'prompt' => 'prompt 2']);

        self::assertTrue($result['isError']);
        self::assertStringContainsString('changed by someone else', $result['text']);
        self::assertSame(['prompt 1'], $connection->fetchFirstColumn('SELECT prompt FROM testcase_version'));
    }

    /** @return list<string> */
    private function projectTitles(Testcase $testcase): array
    {
        return array_values($testcase->getProjects()->map(static fn ($project): string => $project->getTitle())->toArray());
    }
}
