<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use App\Ai\Effort;
use App\Ai\LlmConfiguration;
use App\Ai\Provider;
use App\Entity\Run;
use App\Entity\RunVersion;
use App\Entity\User;
use App\Run\RunResultStatus;
use App\Tests\Support\McpWebTestCase;

final class RunVersionToolsTest extends McpWebTestCase
{
    private const OUTPUT = '<b>answer</b> & more';

    public function testVersionWithoutOutputsOmitsOutputsAndJudgeReasons(): void
    {
        [$user, $run] = $this->versionWithResults();

        $result = $this->callTool($this->connect($user), 'get_run_version', ['runUuid' => $run->getUuid()->toRfc4122(), 'number' => 1]);

        self::assertFalse($result['isError'], $result['text']);
        self::assertStringNotContainsString('answer', $result['text']);
        self::assertStringNotContainsString('reason 0', $result['text']);
        self::assertStringNotContainsString('SECRET-SKILL-BODY', $result['text']);
        $cell = $this->cell($result['data'], 'Greeting', 'claude_code:opus:-');
        self::assertArrayNotHasKey('output', $cell);
        self::assertArrayNotHasKey('criteria', $cell);
        self::assertSame(['done', 80, 4, 5, 300, 10, 20], [$cell['status'], $cell['score'], $cell['criteriaMet'], $cell['criteriaTotal'], $cell['durationMs'], $cell['inputTokens'], $cell['outputTokens']]);
    }

    public function testVersionWithOutputsIncludesOutputAndJudgeReasons(): void
    {
        [$user, $run] = $this->versionWithResults();

        $result = $this->callTool($this->connect($user), 'get_run_version', ['runUuid' => $run->getUuid()->toRfc4122(), 'number' => 1, 'includeOutputs' => true]);

        self::assertFalse($result['isError'], $result['text']);
        $cell = $this->cell($result['data'], 'Greeting', 'claude_code:opus:-');
        self::assertSame(self::OUTPUT, $cell['output']);
        $criteria = $this->rows($cell, 'criteria');
        self::assertCount(5, $criteria);
        self::assertSame(['Mentions the topic', true, 'reason 0'], [$criteria[0]['criterion'], $criteria[0]['met'], $criteria[0]['reason']]);
        self::assertFalse($criteria[4]['met']);
        self::assertStringNotContainsString('SECRET-SKILL-BODY', $result['text']);
    }

    public function testVersionShowsSnapshotProgressMatrixSkippedAndEffortNotes(): void
    {
        [$user, $run] = $this->versionWithResults();

        $data = $this->callTool($this->connect($user), 'get_run_version', ['runUuid' => $run->getUuid()->toRfc4122(), 'number' => 1])['data'];

        self::assertSame([1, 'running', 3, 4], [$data['number'], $data['status'], $data['done'], $data['total']]);
        self::assertSame(['provider' => 'claude_code', 'model' => 'opus'], $data['judge']);
        self::assertSame(['Guidelines'], array_column($this->listAt($data, 'skills'), 'title'));
        self::assertSame(['Farewell', 'Greeting'], $this->sorted(array_column($this->listAt($data, 'testcases'), 'title')));

        $configurations = [];
        foreach ($this->rows($data, 'configurations') as $configuration) {
            self::assertIsString($configuration['key']);
            $configurations[$configuration['key']] = $configuration;
        }
        self::assertFalse($configurations['claude_code:opus:-']['skipped']);
        self::assertSame('effort not supported', $configurations['huggingface:org/model:high']['effortNote']);
        self::assertTrue($configurations['openai:gpt-5.4:-']['skipped']);

        $matrix = $this->listAt($data, 'matrix');
        self::assertSame(['Farewell', 'Greeting'], array_column($matrix, 'testcase'), 'rows sorted by title');
        self::assertSame('running', $this->cell($data, 'Farewell', 'claude_code:opus:-')['status']);
        self::assertSame('failed', $this->cell($data, 'Greeting', 'huggingface:org/model:high')['status']);
        self::assertSame('timeout', $this->cell($data, 'Greeting', 'huggingface:org/model:high')['error']);
        self::assertSame('skipped', $this->cell($data, 'Greeting', 'openai:gpt-5.4:-')['status']);
        $unreported = $this->cell($data, 'Farewell', 'huggingface:org/model:high');
        self::assertSame(['done', 100, null, null], [$unreported['status'], $unreported['score'], $unreported['inputTokens'], $unreported['outputTokens']]);
    }

    public function testVersionSweepsStaleResults(): void
    {
        $user = $this->persistUser('member');
        $project = $this->persistProject('Project', [$user]);
        $run = $this->persistRun($project, 'Stale', testcases: [$this->persistTestcase('Greeting', $project)], configured: true);
        $this->startRun($run);
        $this->entityManager->flush();
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE run_result SET status = 'judging', status_changed_at = ?",
            [(new \DateTimeImmutable('-31 minutes'))->format('Y-m-d H:i:s')],
        );
        $this->entityManager->clear();

        $data = $this->callTool($this->connect($user), 'get_run_version', ['runUuid' => $run->getUuid()->toRfc4122(), 'number' => 1])['data'];

        self::assertSame('completed_with_errors', $data['status']);
        self::assertSame('timeout', $this->cell($data, 'Greeting', 'claude_code:opus:-')['error']);
    }

    public function testVersionWithoutResultsIsNotExecuted(): void
    {
        $user = $this->persistUser('member');
        $project = $this->persistProject('Project', [$user]);
        $run = $this->persistRun($project, 'Old', configured: true);
        $this->entityManager->persist(new RunVersion($run, 1, 'Old', null, [], [], [], null));
        $this->entityManager->flush();

        $data = $this->callTool($this->connect($user), 'get_run_version', ['runUuid' => $run->getUuid()->toRfc4122(), 'number' => 1])['data'];

        self::assertSame(['not_executed', 0, 0, null, []], [$data['status'], $data['done'], $data['total'], $data['judge'], $data['matrix']]);
    }

    public function testUnknownVersionAndInvalidRunAreReported(): void
    {
        [$user, $run] = $this->versionWithResults();
        $token = $this->connect($user);

        self::assertSame('Version not found.', $this->callTool($token, 'get_run_version', ['runUuid' => $run->getUuid()->toRfc4122(), 'number' => 9])['text']);
        self::assertSame('Run not found.', $this->callTool($token, 'get_run_version', ['runUuid' => '019f0000-0000-7000-8000-000000000000', 'number' => 1])['text']);
    }

    public function testCompareShowsChangesAndScoresOfTwoVersions(): void
    {
        $user = $this->persistUser('member');
        $project = $this->persistProject('Project', [$user]);
        $greeting = $this->persistTestcase('Greeting', $project);
        $farewell = $this->persistTestcase('Farewell', $project);
        $run = $this->persistRun($project, 'Before', testcases: [$greeting], configured: true);
        $first = $this->startRun($run);
        $this->finish($first, 1, 2);
        $run->setTitle('After');
        $run->setTestcases([$greeting, $farewell]);
        $run->addConfiguration(new LlmConfiguration(Provider::ClaudeCode, 'sonnet'));
        $second = $this->startRun($run);
        $this->finish($second, 2, 2);
        $this->entityManager->flush();

        $token = $this->connect($user);
        $uuid = $run->getUuid()->toRfc4122();
        $explicit = $this->callTool($token, 'compare_run_versions', ['runUuid' => $uuid, 'from' => 1, 'to' => 2]);
        $default = $this->callTool($token, 'compare_run_versions', ['runUuid' => $uuid]);

        self::assertFalse($explicit['isError'], $explicit['text']);
        self::assertSame($explicit['data'], $default['data'], 'default is the latest against the one before');
        $data = $explicit['data'];
        self::assertSame([1, 2, false], [$data['from'], $data['to'], $data['unchanged']]);
        self::assertSame(['title' => ['from' => 'Before', 'to' => 'After']], $data['fields']);
        self::assertSame(['Claude Code / sonnet / no effort'], $data['configurationsAdded']);
        self::assertSame([['title' => 'Farewell', 'number' => 1]], $data['testcasesAdded']);
        self::assertSame([], $data['testcasesRemoved']);
        $scores = $this->listAt($data, 'scores');
        self::assertContains(['testcase' => 'Greeting', 'configuration' => 'Claude Code / opus / no effort', 'from' => 50, 'to' => 100], $scores);
        $averages = $this->rows($data, 'averages');
        self::assertSame(['Claude Code / opus / no effort', 50, 100, 1], [$averages[0]['configuration'], $averages[0]['from'], $averages[0]['to'], $averages[0]['cells']]);
    }

    public function testCompareValidatesLikeTheUi(): void
    {
        $user = $this->persistUser('member');
        $project = $this->persistProject('Project', [$user]);
        $empty = $this->persistRun($project, 'Empty');
        $single = $this->persistRun($project, 'Single', testcases: [$this->persistTestcase('Case', $project)], configured: true);
        $this->startRun($single);
        $this->entityManager->flush();
        $token = $this->connect($user);

        $none = $this->callTool($token, 'compare_run_versions', ['runUuid' => $empty->getUuid()->toRfc4122()]);
        self::assertTrue($none['isError']);
        self::assertSame('Comparing needs two versions; this run has fewer.', $none['text']);
        self::assertTrue($this->callTool($token, 'compare_run_versions', ['runUuid' => $single->getUuid()->toRfc4122()])['isError']);
        self::assertTrue($this->callTool($token, 'compare_run_versions', ['runUuid' => $single->getUuid()->toRfc4122(), 'from' => 0, 'to' => 1])['isError']);
        self::assertSame('Version not found.', $this->callTool($token, 'compare_run_versions', ['runUuid' => $single->getUuid()->toRfc4122(), 'from' => 1, 'to' => 5])['text']);

        $same = $this->callTool($token, 'compare_run_versions', ['runUuid' => $single->getUuid()->toRfc4122(), 'from' => 1, 'to' => 1]);
        self::assertFalse($same['isError'], $same['text']);
        self::assertTrue($same['data']['unchanged']);
    }

    public function testVersionAndCompareAreForbiddenForAForeignRun(): void
    {
        [, $run] = $this->versionWithResults();
        $token = $this->connect($this->persistUser('mallory'));

        $version = $this->callTool($token, 'get_run_version', ['runUuid' => $run->getUuid()->toRfc4122(), 'number' => 1, 'includeOutputs' => true]);
        $compare = $this->callTool($token, 'compare_run_versions', ['runUuid' => $run->getUuid()->toRfc4122(), 'from' => 1, 'to' => 1]);

        foreach ([$version, $compare] as $result) {
            self::assertTrue($result['isError']);
            self::assertStringStartsWith('Forbidden', $result['text']);
            self::assertStringNotContainsString('answer', $result['text']);
        }
    }

    /**
     * Two testcases; Claude Code and Hugging Face (effort) run, OpenAI is skipped. Greeting x Claude Code is done (4 of 5
     * criteria, 80 %), Greeting x Hugging Face failed with a timeout, Farewell x Claude Code is running, Farewell x Hugging Face done.
     *
     * @return array{0: User, 1: Run}
     */
    private function versionWithResults(): array
    {
        $user = $this->persistUser('member');
        $project = $this->persistProject('Project', [$user]);
        $skill = $this->persistSkill('Guidelines', [$project], content: 'SECRET-SKILL-BODY');
        $run = $this->persistRun($project, 'Matrix', [$skill], [$this->persistTestcase('Greeting', $project), $this->persistTestcase('Farewell', $project)]);
        $run->addConfiguration(new LlmConfiguration(Provider::ClaudeCode, 'opus'));
        $run->addConfiguration(new LlmConfiguration(Provider::HuggingFace, 'org/model', Effort::High));
        $run->addConfiguration(new LlmConfiguration(Provider::OpenAi, 'gpt-5.4'));
        $version = $run->start(static fn (Provider $provider): bool => Provider::OpenAi !== $provider, new LlmConfiguration(Provider::ClaudeCode, 'opus'));
        $this->entityManager->flush();

        $cells = [];
        foreach ($version->getResults() as $result) {
            $cells[$result->getTestcaseVersion()->getTitle().'|'.$result->getConfigurationKey()] = $result;
        }
        $criteria = array_map(static fn (int $i): array => ['criterion' => 'Mentions the topic', 'met' => 4 !== $i, 'reason' => 'reason '.$i], range(0, 4));

        self::assertTrue($cells['Greeting|claude_code:opus:-']->markRunning());
        self::assertTrue($cells['Greeting|claude_code:opus:-']->markJudging(self::OUTPUT, 10, 20, 300));
        self::assertTrue($cells['Greeting|claude_code:opus:-']->markDone($criteria));
        self::assertTrue($cells['Greeting|huggingface:org/model:high']->markRunning());
        self::assertTrue($cells['Greeting|huggingface:org/model:high']->markFailed('timeout', RunResultStatus::Running));
        self::assertTrue($cells['Farewell|claude_code:opus:-']->markRunning());
        self::assertTrue($cells['Farewell|huggingface:org/model:high']->markRunning());
        self::assertTrue($cells['Farewell|huggingface:org/model:high']->markJudging('ok', null, null, 5));
        self::assertTrue($cells['Farewell|huggingface:org/model:high']->markDone([['criterion' => 'c', 'met' => true, 'reason' => 'r']]));
        $this->entityManager->flush();

        return [$user, $run];
    }

    /** Every cell done with $met of $total criteria met. */
    private function finish(RunVersion $version, int $met, int $total): void
    {
        foreach ($version->getResults() as $result) {
            $criteria = [];
            for ($i = 0; $i < $total; ++$i) {
                $criteria[] = ['criterion' => 'c'.$i, 'met' => $i < $met, 'reason' => 'r'];
            }
            $result->markRunning();
            $result->markJudging('ok', 1, 1, 1);
            $result->markDone($criteria);
        }
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private function cell(array $data, string $testcase, string $configuration): array
    {
        foreach ($this->rows($data, 'matrix') as $row) {
            if ($testcase !== $row['testcase']) {
                continue;
            }
            foreach ($this->rows($row, 'cells') as $cell) {
                if ($configuration === $cell['configuration']) {
                    return $cell;
                }
            }
        }

        self::fail(\sprintf('No cell %s x %s.', $testcase, $configuration));
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<mixed>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
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
}
