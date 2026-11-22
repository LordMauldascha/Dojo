<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use App\Ai\Credential;
use App\Entity\Project;
use App\Entity\Run;
use App\Entity\RunResult;
use App\Entity\RunVersion;
use App\Entity\User;
use App\Message\ExecuteRunResult;
use App\Run\RunStarter;
use App\Security\UserRole;
use App\Tests\Support\McpWebTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class RunToolsTest extends McpWebTestCase
{
    public function testListShowsOnlyRunsOfAccessibleProjectsAndRefusesAForeignProject(): void
    {
        $user = $this->persistUser('member');
        $own = $this->persistProject('Own project', [$user]);
        $foreign = $this->persistProject('Foreign project');
        $this->persistRun($own, 'Own run', testcases: [$this->persistTestcase('Case', $own)]);
        $this->persistRun($own, 'Another run');
        $this->persistRun($foreign, 'Foreign run');
        $token = $this->connect($user);

        $result = $this->callTool($token, 'list_runs', ['projectUuid' => $own->getUuid()->toRfc4122()]);
        self::assertFalse($result['isError'], $result['text']);
        $items = $this->listAt($result['data'], 'items');
        self::assertSame(['Another run', 'Own run'], array_column($items, 'title'));
        self::assertSame([0, 1], array_column($items, 'testcaseCount'));
        self::assertSame(2, $result['data']['total']);

        $searched = $this->callTool($token, 'list_runs', ['projectUuid' => $own->getUuid()->toRfc4122(), 'query' => 'own', 'sort' => 'title', 'dir' => 'desc']);
        self::assertSame(['Own run'], array_column($this->listAt($searched['data'], 'items'), 'title'));

        $refused = $this->callTool($token, 'list_runs', ['projectUuid' => $foreign->getUuid()->toRfc4122()]);
        self::assertTrue($refused['isError']);
        self::assertStringStartsWith('Forbidden', $refused['text']);
    }

    public function testListRejectsAnInvalidPageSize(): void
    {
        $user = $this->persistUser('member');
        $project = $this->persistProject('Own project', [$user]);

        $result = $this->callTool($this->connect($user), 'list_runs', ['projectUuid' => $project->getUuid()->toRfc4122(), 'perPage' => 7]);

        self::assertTrue($result['isError']);
    }

    public function testGetRunShowsStartProblemsOfAnEmptyRun(): void
    {
        [$user, $project] = $this->member();
        $run = $this->persistRun($project, 'Empty');

        $result = $this->callTool($this->connect($user), 'get_run', ['runUuid' => $run->getUuid()->toRfc4122()]);

        self::assertFalse($result['isError'], $result['text']);
        self::assertFalse($result['data']['canStart']);
        self::assertSame([RunStarter::NO_TESTCASE, RunStarter::NO_CONFIGURATION, RunStarter::NO_JUDGE], $result['data']['problems']);
        self::assertSame(0, $result['data']['llmCalls']);
        self::assertSame('Project', $this->at($result['data'], 'project')['title']);
        self::assertSame([], $result['data']['versions']);
    }

    public function testGetRunNamesAConfigurationWithoutKey(): void
    {
        [$user, $project] = $this->member();
        $run = $this->persistRun($project, 'Configured', testcases: [$this->persistTestcase('Case', $project)], configured: true);
        $this->storeKey(Credential::OpenAi);

        $result = $this->callTool($this->connect($user), 'get_run', ['runUuid' => $run->getUuid()->toRfc4122()]);

        self::assertContains(RunStarter::NO_USABLE_CONFIGURATION, $this->listAt($result['data'], 'problems'));
        self::assertFalse($this->rows($result['data'], 'configurations')[0]['hasApiKey']);
    }

    public function testGetRunHasACleanStartCheckOnceEverythingIsSetUp(): void
    {
        [$user, $project] = $this->member();
        $skill = $this->persistSkill('Polite', [$project]);
        $run = $this->persistRun($project, 'Ready', [$skill], [$this->persistTestcase('One', $project), $this->persistTestcase('Two', $project)], true, $user);
        $this->storeJudge();

        $result = $this->callTool($this->connect($user), 'get_run', ['runUuid' => $run->getUuid()->toRfc4122()]);

        self::assertTrue($result['data']['canStart']);
        self::assertSame([], $result['data']['problems']);
        self::assertSame(2, $result['data']['llmCalls']);
        self::assertSame(2, $result['data']['judgeCalls']);
        self::assertTrue($result['data']['canDelete']);
        self::assertSame(['Polite'], array_column($this->listAt($result['data'], 'skills'), 'title'));
        $titles = array_column($this->listAt($result['data'], 'testcases'), 'title');
        sort($titles);
        self::assertSame(['One', 'Two'], $titles);
        $configuration = $this->rows($result['data'], 'configurations')[0];
        self::assertSame(['claude_code', 'opus', null, true], [$configuration['provider'], $configuration['model'], $configuration['effort'], $configuration['hasApiKey']]);
    }

    public function testGetRunListsVersionsWithProgress(): void
    {
        [$user, $project] = $this->member();
        $run = $this->persistRun($project, 'Started', testcases: [$this->persistTestcase('One', $project)], configured: true);
        $this->startRun($run);
        $this->entityManager->flush();

        $result = $this->callTool($this->connect($user), 'get_run', ['runUuid' => $run->getUuid()->toRfc4122()]);

        $version = $this->rows($result['data'], 'versions')[0];
        self::assertSame([1, 'running', 0, 1], [$version['number'], $version['status'], $version['done'], $version['total']]);
        self::assertContains(RunStarter::STILL_RUNNING, $this->listAt($result['data'], 'problems'));
    }

    public function testCreateAndUpdateRun(): void
    {
        [$user, $project] = $this->member();
        $token = $this->connect($user);

        $created = $this->callTool($token, 'create_run', ['projectUuid' => $project->getUuid()->toRfc4122(), 'title' => '  Nightly ', 'description' => 'Katas']);
        self::assertFalse($created['isError'], $created['text']);
        self::assertSame(['Nightly', 'Katas', true], [$created['data']['title'], $created['data']['description'], $created['data']['canDelete']]);
        $uuid = $created['data']['uuid'];

        $updated = $this->callTool($token, 'update_run', ['runUuid' => $uuid, 'title' => 'Weekly']);
        self::assertSame(['Weekly', 'Katas'], [$updated['data']['title'], $updated['data']['description']]);

        $cleared = $this->callTool($token, 'update_run', ['runUuid' => $uuid, 'description' => '']);
        self::assertSame(['Weekly', null], [$cleared['data']['title'], $cleared['data']['description']]);
    }

    public function testCreateAndUpdateValidate(): void
    {
        [$user, $project] = $this->member();
        $run = $this->persistRun($project, 'Existing');
        $projectUuid = $project->getUuid()->toRfc4122();
        $runUuid = $run->getUuid()->toRfc4122();
        $token = $this->connect($user);

        self::assertTrue($this->callTool($token, 'create_run', ['projectUuid' => $projectUuid, 'title' => '   '])['isError']);
        self::assertTrue($this->callTool($token, 'create_run', ['projectUuid' => $projectUuid, 'title' => str_repeat('a', Run::TITLE_MAX + 1)])['isError']);
        self::assertTrue($this->callTool($token, 'create_run', ['projectUuid' => $projectUuid, 'title' => 'Ok', 'description' => str_repeat('a', Run::DESCRIPTION_MAX + 1)])['isError']);
        self::assertTrue($this->callTool($token, 'update_run', ['runUuid' => $runUuid, 'title' => ' '])['isError']);
        self::assertTrue($this->callTool($token, 'update_run', ['runUuid' => $runUuid, 'title' => str_repeat('a', Run::TITLE_MAX + 1)])['isError']);

        self::assertSame('Existing', $this->callTool($token, 'get_run', ['runUuid' => $runUuid])['data']['title']);
        self::assertSame(1, $this->callTool($token, 'list_runs', ['projectUuid' => $projectUuid])['data']['total']);
    }

    public function testCreateInAForeignProjectIsForbidden(): void
    {
        $foreign = $this->persistProject('Foreign project');

        $result = $this->callTool($this->connect($this->persistUser('member')), 'create_run', ['projectUuid' => $foreign->getUuid()->toRfc4122(), 'title' => 'Sneaky']);

        self::assertTrue($result['isError']);
        self::assertStringStartsWith('Forbidden', $result['text']);
    }

    public function testListModelsOffersOnlyProvidersWithAKeyAndNeverKeyMaterial(): void
    {
        $user = $this->persistUser('member');
        $this->storeKey(Credential::Anthropic);
        $token = $this->connect($user);

        $result = $this->callTool($token, 'list_models');

        self::assertFalse($result['isError'], $result['text']);
        $providers = $this->rows($result['data'], 'providers');
        self::assertSame(['claude_code'], array_column($providers, 'provider'));
        self::assertSame(['opus', 'sonnet', 'haiku'], array_column($this->rows($providers[0], 'models'), 'model'));
        self::assertSame(['low', 'medium', 'high', 'xhigh', 'max'], $result['data']['efforts']);
        self::assertStringNotContainsString('synthetic-key', $result['text']);
        self::assertStringNotContainsString('0000', $result['text']);
    }

    public function testListModelsWithoutAnyKeyIsEmpty(): void
    {
        $result = $this->callTool($this->connect($this->persistUser('member')), 'list_models');

        self::assertSame([], $result['data']['providers']);
    }

    public function testListModelsShowsWhichModelsSupportEffort(): void
    {
        $this->storeKey(Credential::OpenAi);

        $result = $this->callTool($this->connect($this->persistUser('member')), 'list_models');

        $supported = [];
        foreach ($this->rows($this->rows($result['data'], 'providers')[0], 'models') as $model) {
            self::assertIsString($model['model']);
            $supported[$model['model']] = $model['effortSupported'];
        }
        self::assertTrue($supported['gpt-5.5']);
        self::assertFalse($supported['gpt-4.1']);
    }

    public function testAddDuplicateAndRemoveConfiguration(): void
    {
        [$user, $project] = $this->member();
        $run = $this->persistRun($project, 'Setup');
        $uuid = $run->getUuid()->toRfc4122();
        $this->storeKey(Credential::Anthropic);
        $token = $this->connect($user);

        $added = $this->callTool($token, 'add_run_configuration', ['runUuid' => $uuid, 'provider' => 'claude_code', 'model' => 'sonnet', 'effort' => 'high']);
        self::assertFalse($added['isError'], $added['text']);
        self::assertSame([['claude_code', 'sonnet', 'high']], $this->configurations($added['data']));

        $duplicate = $this->callTool($token, 'add_run_configuration', ['runUuid' => $uuid, 'provider' => 'claude_code', 'model' => 'sonnet', 'effort' => 'high']);
        self::assertTrue($duplicate['isError']);
        self::assertSame('This configuration already exists.', $duplicate['text']);

        $withoutEffort = $this->callTool($token, 'add_run_configuration', ['runUuid' => $uuid, 'provider' => 'claude_code', 'model' => 'sonnet']);
        self::assertSame([['claude_code', 'sonnet', 'high'], ['claude_code', 'sonnet', null]], $this->configurations($withoutEffort['data']));

        $removed = $this->callTool($token, 'remove_run_configuration', ['runUuid' => $uuid, 'provider' => 'claude_code', 'model' => 'sonnet', 'effort' => 'high']);
        self::assertSame([['claude_code', 'sonnet', null]], $this->configurations($removed['data']));

        $missing = $this->callTool($token, 'remove_run_configuration', ['runUuid' => $uuid, 'provider' => 'claude_code', 'model' => 'sonnet', 'effort' => 'high']);
        self::assertTrue($missing['isError']);
        self::assertSame('There is no such configuration.', $missing['text']);
    }

    public function testAddConfigurationRejectsUnknownProviderModelEffortAndProviderWithoutKey(): void
    {
        [$user, $project] = $this->member();
        $uuid = $this->persistRun($project, 'Setup')->getUuid()->toRfc4122();
        $this->storeKey(Credential::Anthropic);
        $token = $this->connect($user);

        foreach ([
            ['provider' => 'nobody', 'model' => 'opus'],
            ['provider' => 'claude_code', 'model' => 'gpt-5.5'],
            ['provider' => 'openai', 'model' => 'gpt-5.5'],
            ['provider' => 'claude_code', 'model' => 'opus', 'effort' => 'ultra'],
        ] as $arguments) {
            self::assertTrue($this->callTool($token, 'add_run_configuration', ['runUuid' => $uuid] + $arguments)['isError'], json_encode($arguments, \JSON_THROW_ON_ERROR));
        }
        self::assertSame([], $this->configurations($this->callTool($token, 'get_run', ['runUuid' => $uuid])['data']));
    }

    public function testRemoveConfigurationWorksWithoutKey(): void
    {
        [$user, $project] = $this->member();
        $run = $this->persistRun($project, 'Setup', configured: true);

        $result = $this->callTool($this->connect($user), 'remove_run_configuration', ['runUuid' => $run->getUuid()->toRfc4122(), 'provider' => 'claude_code', 'model' => 'opus']);

        self::assertFalse($result['isError'], $result['text']);
        self::assertSame([], $this->configurations($result['data']));
    }

    public function testSetSkillsAndTestcasesAcceptsItemsOfTheProject(): void
    {
        [$user, $project] = $this->member();
        $skillA = $this->persistSkill('Alpha', [$project]);
        $skillB = $this->persistSkill('Beta', [$project]);
        $testcase = $this->persistTestcase('Case', $project);
        $run = $this->persistRun($project, 'Setup', [$skillA]);
        $uuid = $run->getUuid()->toRfc4122();
        $token = $this->connect($user);

        $skills = $this->callTool($token, 'set_run_skills', ['runUuid' => $uuid, 'skillUuids' => [$skillB->getUuid()->toRfc4122(), $skillB->getUuid()->toRfc4122()]]);
        self::assertFalse($skills['isError'], $skills['text']);
        self::assertSame(['Beta'], array_column($this->listAt($skills['data'], 'skills'), 'title'));

        $testcases = $this->callTool($token, 'set_run_testcases', ['runUuid' => $uuid, 'testcaseUuids' => [$testcase->getUuid()->toRfc4122()]]);
        self::assertSame(['Case'], array_column($this->listAt($testcases['data'], 'testcases'), 'title'));

        $cleared = $this->callTool($token, 'set_run_skills', ['runUuid' => $uuid, 'skillUuids' => []]);
        self::assertSame([], $cleared['data']['skills']);
        self::assertSame(['Case'], array_column($this->listAt($cleared['data'], 'testcases'), 'title'));
    }

    public function testSetSkillsAndTestcasesRejectForeignDeletedAndInvalidItemsWithoutPartialChange(): void
    {
        [$user, $project] = $this->member();
        $other = $this->persistProject('Other project', [$user]);
        $ownSkill = $this->persistSkill('Own', [$project]);
        $newSkill = $this->persistSkill('New', [$project]);
        $otherSkill = $this->persistSkill('Elsewhere', [$other]);
        $deletedSkill = $this->persistSkill('Gone', [$project]);
        $deletedSkill->softDelete();
        $ownCase = $this->persistTestcase('Own case', $project);
        $newCase = $this->persistTestcase('New case', $project);
        $otherCase = $this->persistTestcase('Other case', $other);
        $this->entityManager->flush();
        $run = $this->persistRun($project, 'Setup', [$ownSkill], [$ownCase]);
        $uuid = $run->getUuid()->toRfc4122();
        $token = $this->connect($user);

        foreach ([$otherSkill, $deletedSkill] as $bad) {
            $result = $this->callTool($token, 'set_run_skills', ['runUuid' => $uuid, 'skillUuids' => [$newSkill->getUuid()->toRfc4122(), $bad->getUuid()->toRfc4122()]]);
            self::assertTrue($result['isError']);
            self::assertSame('Skill not found.', $result['text']);
        }
        $malformed = $this->callTool($token, 'set_run_skills', ['runUuid' => $uuid, 'skillUuids' => ['nope']]);
        self::assertStringContainsString('not a valid uuid', $malformed['text']);
        $foreignCase = $this->callTool($token, 'set_run_testcases', ['runUuid' => $uuid, 'testcaseUuids' => [$newCase->getUuid()->toRfc4122(), $otherCase->getUuid()->toRfc4122()]]);
        self::assertSame('Testcase not found.', $foreignCase['text']);

        $unchanged = $this->callTool($token, 'get_run', ['runUuid' => $uuid]);
        self::assertSame(['Own'], array_column($this->listAt($unchanged['data'], 'skills'), 'title'));
        self::assertSame(['Own case'], array_column($this->listAt($unchanged['data'], 'testcases'), 'title'));
    }

    public function testStartIsForbiddenWithoutProjectAccess(): void
    {
        $project = $this->persistProject('Foreign project');
        $run = $this->persistRun($project, 'Foreign', testcases: [$this->persistTestcase('Case', $project)], configured: true);
        $this->storeJudge();

        $result = $this->callTool($this->connect($this->persistUser('mallory')), 'start_run', ['runUuid' => $run->getUuid()->toRfc4122()]);

        self::assertTrue($result['isError']);
        self::assertStringStartsWith('Forbidden', $result['text']);
        self::assertSame([], $this->sent());
    }

    public function testStartIsBlockedWithTheProblemText(): void
    {
        [$user, $project] = $this->member();
        $run = $this->persistRun($project, 'Empty');

        $result = $this->callTool($this->connect($user), 'start_run', ['runUuid' => $run->getUuid()->toRfc4122()]);

        self::assertTrue($result['isError']);
        self::assertSame(RunStarter::NO_TESTCASE, $result['text']);
        self::assertSame([], $this->sent());
    }

    public function testStartCreatesVersionOneDispatchesOneMessagePerCellAndBlocksASecondStart(): void
    {
        [$user, $project] = $this->member();
        $run = $this->persistRun($project, 'Ready', testcases: [$this->persistTestcase('One', $project), $this->persistTestcase('Two', $project)], configured: true);
        $this->storeJudge();
        $uuid = $run->getUuid()->toRfc4122();
        $token = $this->connect($user);

        $started = $this->callTool($token, 'start_run', ['runUuid' => $uuid]);

        self::assertFalse($started['isError'], $started['text']);
        self::assertSame([true, 1, 2, 2], [$started['data']['started'], $started['data']['version'], $started['data']['llmCalls'], $started['data']['judgeCalls']]);
        $sent = $this->sent();
        self::assertCount(2, $sent);
        foreach ($sent as $message) {
            self::assertInstanceOf(ExecuteRunResult::class, $message);
        }
        self::assertStringNotContainsString('prompt', $started['text']);

        $second = $this->callTool($token, 'start_run', ['runUuid' => $uuid]);
        self::assertTrue($second['isError']);
        self::assertSame(RunStarter::STILL_RUNNING, $second['text']);
        self::assertSame(1, $this->rows($this->callTool($token, 'get_run', ['runUuid' => $uuid])['data'], 'versions')[0]['number']);
    }

    public function testStartWithBaselineRunsAVersionWithoutSkillsFirstAndRejectsItLater(): void
    {
        [$user, $project] = $this->member();
        $run = $this->persistRun($project, 'Ready', skills: [$this->persistSkill('Style', [$project])], testcases: [$this->persistTestcase('One', $project), $this->persistTestcase('Two', $project)], configured: true);
        $this->storeJudge();
        $uuid = $run->getUuid()->toRfc4122();
        $token = $this->connect($user);
        self::assertTrue($this->callTool($token, 'get_run', ['runUuid' => $uuid])['data']['baselineAvailable']);

        $started = $this->callTool($token, 'start_run', ['runUuid' => $uuid, 'withBaseline' => true]);

        self::assertFalse($started['isError'], $started['text']);
        self::assertSame([2, 1, 4, 4], [$started['data']['version'], $started['data']['baselineVersion'], $started['data']['llmCalls'], $started['data']['judgeCalls']]);
        self::assertCount(4, $this->sent());
        $detail = $this->callTool($token, 'get_run', ['runUuid' => $uuid])['data'];
        self::assertFalse($detail['baselineAvailable']);
        self::assertSame([1, 2], array_column($this->rows($detail, 'versions'), 'number'));
        $this->finishAllResults();
        $again = $this->callTool($token, 'start_run', ['runUuid' => $uuid, 'withBaseline' => true]);
        self::assertTrue($again['isError']);
        self::assertSame(RunStarter::BASELINE_NOT_AVAILABLE, $again['text']);
    }

    public function testDeleteIsAllowedForCreatorAndAdminOnly(): void
    {
        $creator = $this->persistUser('creator');
        $member = $this->persistUser('member');
        $admin = $this->persistUser('boss', UserRole::Admin);
        $project = $this->persistProject('Shared', [$creator, $member]);
        $first = $this->persistRun($project, 'First', creator: $creator);
        $second = $this->persistRun($project, 'Second', creator: $creator);
        $creatorToken = $this->connect($creator);
        $memberToken = $this->connect($member);
        $adminToken = $this->connect($admin);

        $denied = $this->callTool($memberToken, 'delete_run', ['runUuid' => $first->getUuid()->toRfc4122()]);
        self::assertTrue($denied['isError']);
        self::assertStringStartsWith('Forbidden', $denied['text']);
        self::assertFalse($this->callTool($memberToken, 'get_run', ['runUuid' => $first->getUuid()->toRfc4122()])['data']['canDelete']);

        $deleted = $this->callTool($creatorToken, 'delete_run', ['runUuid' => $first->getUuid()->toRfc4122()]);
        self::assertSame([true, 'First'], [$deleted['data']['deleted'], $deleted['data']['title']]);
        self::assertSame('Run not found.', $this->callTool($creatorToken, 'get_run', ['runUuid' => $first->getUuid()->toRfc4122()])['text']);

        self::assertFalse($this->callTool($adminToken, 'delete_run', ['runUuid' => $second->getUuid()->toRfc4122()])['isError']);
    }

    public function testDeleteRemovesTheVersionsToo(): void
    {
        [$user, $project] = $this->member();
        $run = $this->persistRun($project, 'Started', testcases: [$this->persistTestcase('One', $project)], configured: true, creator: $user);
        $this->startRun($run);
        $this->entityManager->flush();
        $token = $this->connect($user);

        $this->callTool($token, 'delete_run', ['runUuid' => $run->getUuid()->toRfc4122()]);

        self::assertSame(0, $this->entityManager->getRepository(RunVersion::class)->count([]));
        self::assertSame(0, $this->entityManager->getRepository(RunResult::class)->count([]));
    }

    public function testEveryRunToolIsForbiddenForAForeignRun(): void
    {
        $project = $this->persistProject('Foreign project');
        $skill = $this->persistSkill('Skill', [$project]);
        $run = $this->persistRun($project, 'Foreign', [$skill], [$this->persistTestcase('Case', $project)], true);
        $this->storeJudge();
        $uuid = $run->getUuid()->toRfc4122();
        $token = $this->connect($this->persistUser('mallory'));

        foreach ([
            'get_run' => [],
            'update_run' => ['title' => 'Mine'],
            'add_run_configuration' => ['provider' => 'claude_code', 'model' => 'sonnet'],
            'remove_run_configuration' => ['provider' => 'claude_code', 'model' => 'opus'],
            'set_run_skills' => ['skillUuids' => []],
            'set_run_testcases' => ['testcaseUuids' => []],
            'start_run' => [],
            'delete_run' => [],
            'get_run_version' => ['number' => 1],
            'compare_run_versions' => [],
        ] as $tool => $arguments) {
            $result = $this->callTool($token, $tool, ['runUuid' => $uuid] + $arguments);
            self::assertTrue($result['isError'], $tool);
            self::assertStringStartsWith('Forbidden', $result['text'], $tool);
        }
        self::assertSame([], $this->sent());
    }

    public function testUnknownRunIsNotFound(): void
    {
        $token = $this->connect($this->persistUser('member'));

        self::assertSame('Run not found.', $this->callTool($token, 'get_run', ['runUuid' => '019f0000-0000-7000-8000-000000000000'])['text']);
        self::assertStringContainsString('not a valid uuid', $this->callTool($token, 'start_run', ['runUuid' => 'nope'])['text']);
    }

    /** @return array{0: User, 1: Project} */
    private function member(): array
    {
        $user = $this->persistUser('member');

        return [$user, $this->persistProject('Project', [$user])];
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<array{string, string, string|null}>
     */
    private function configurations(array $data): array
    {
        $configurations = [];
        foreach ($this->rows($data, 'configurations') as $configuration) {
            self::assertIsString($configuration['provider']);
            self::assertIsString($configuration['model']);
            $effort = $configuration['effort'];
            self::assertTrue(null === $effort || \is_string($effort));
            $configurations[] = [$configuration['provider'], $configuration['model'], $effort];
        }

        return $configurations;
    }

    /** @return list<object> */
    private function sent(): array
    {
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_values(array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), $transport->getSent()));
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<array<mixed>>
     */
    private function rows(array $data, string $key): array
    {
        $rows = [];
        foreach ($this->listAt($data, $key) as $row) {
            self::assertIsArray($row);
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private function at(array $data, string $key): array
    {
        return $this->listAt($data, $key);
    }
}
