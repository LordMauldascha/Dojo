<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Ai\Effort;
use App\Ai\LlmConfiguration;
use App\Ai\Provider;
use App\Entity\Project;
use App\Entity\Run;
use App\Entity\RunResult;
use App\Entity\RunVersion;
use App\Entity\Skill;
use App\Entity\Testcase;
use App\Run\RunResultStatus;
use App\Run\RunVersionStatus;
use Doctrine\Common\Collections\Collection;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;

final class RunTest extends PhpUnitTestCase
{
    private Project $project;

    protected function setUp(): void
    {
        $this->project = new Project();
    }

    public function testConfigurationsAreDeduplicatedAndRemovedByKey(): void
    {
        $run = $this->newRun();
        $gpt = new LlmConfiguration(Provider::OpenAi, 'gpt-5.4', Effort::High);

        self::assertTrue($run->addConfiguration($gpt));
        self::assertFalse($run->addConfiguration($gpt));
        self::assertTrue($run->addConfiguration(new LlmConfiguration(Provider::OpenAi, 'gpt-5.4')));
        self::assertTrue($run->addConfiguration(new LlmConfiguration(Provider::ClaudeCode, 'opus')));
        $before = $this->configurationKeys($run);
        self::assertCount(3, $before);

        self::assertTrue($run->removeConfiguration('openai:gpt-5.4:-'));
        self::assertFalse($run->removeConfiguration('openai:gpt-5.4:-'));
        self::assertFalse($run->removeConfiguration('does:not:exist'));
        $after = $this->configurationKeys($run);
        self::assertSame(['openai:gpt-5.4:high', 'claude_code:opus:-'], $after);
    }

    public function testUnknownModelOfAProviderIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->newRun()->addConfiguration(new LlmConfiguration(Provider::OpenAi, 'opus'));
    }

    public function testStartIsRefusedWithoutTestcaseOrConfiguration(): void
    {
        $run = $this->newRun();
        $run->addConfiguration(new LlmConfiguration(Provider::ClaudeCode, 'opus'));
        try {
            $this->start($run);
            self::fail('start without testcase must fail');
        } catch (\LogicException) {
        }

        $withoutConfiguration = $this->newRun();
        $withoutConfiguration->setTestcases([$this->makeTestcase('T', 'p', 'o', $this->project)]);
        try {
            $this->start($withoutConfiguration);
            self::fail('start without configuration must fail');
        } catch (\LogicException) {
        }

        self::assertCount(0, $run->getVersions());
        self::assertCount(0, $withoutConfiguration->getVersions());
    }

    public function testStartNumbersVersionsAndSnapshotsTheActiveVersionsIgnoringDrafts(): void
    {
        $skill = $this->skill('Review', 'skill v1');
        $skill->saveFinal('Review', null, 'skill v2');
        $skill->saveDraft('Review', null, 'skill draft');
        $testcase = $this->makeTestcase('Greeting', 'prompt 1', 'output 1', $this->project);
        $testcase->saveDraft('Greeting', null, 'prompt draft', 'output draft');
        $run = $this->newRun();
        $run->setTitle('Nightly');
        $run->setDescription('All reviews');
        $run->addConfiguration(new LlmConfiguration(Provider::ClaudeCode, 'opus', Effort::High));
        $run->setSkills([$skill]);
        $run->setTestcases([$testcase]);

        $first = $this->start($run);
        $second = $this->start($run);

        self::assertSame([1, 2], [$first->getNumber(), $second->getNumber()]);
        self::assertSame($second, $run->getLatestVersion());
        self::assertSame($first, $run->findVersion(1));
        self::assertSame('Nightly', $first->getTitle());
        self::assertSame('All reviews', $first->getDescription());
        self::assertSame(['claude_code:opus:high'], array_map(static fn (LlmConfiguration $c): string => $c->key(), $first->getConfigurations()));
        self::assertSame('2', $this->only($first->getSkillVersions())->getLabel());
        self::assertSame('1', $this->only($first->getTestcaseVersions())->getLabel());
    }

    public function testStartCreatesOneResultPerAvailableConfigurationAndTestcase(): void
    {
        $run = $this->newRun();
        $run->addConfiguration(new LlmConfiguration(Provider::ClaudeCode, 'opus'));
        $run->addConfiguration(new LlmConfiguration(Provider::OpenAi, 'gpt-5.4'));
        $run->setTestcases([
            $this->makeTestcase('A', 'p', 'o', $this->project),
            $this->makeTestcase('B', 'p', 'o', $this->project),
            $this->makeTestcase('C', 'p', 'o', $this->project),
        ]);

        $version = $run->start(static fn (Provider $provider): bool => Provider::ClaudeCode === $provider, $this->judge());

        self::assertCount(3, $version->getResults());
        foreach ($version->getResults() as $result) {
            self::assertSame('claude_code:opus:-', $result->getConfigurationKey());
            self::assertSame(RunResultStatus::Pending, $result->getStatus());
        }
        self::assertSame(['openai:gpt-5.4:-'], array_map(static fn (LlmConfiguration $c): string => $c->key(), $version->getSkippedConfigurations()));
        self::assertCount(2, $version->getConfigurations());
        self::assertSame(['claude_code:opus:-', 'openai:gpt-5.4:-'], array_map(static fn (LlmConfiguration $c): string => $c->key(), $version->getConfigurations()));
    }

    public function testStartIsRefusedWithoutAvailableConfiguration(): void
    {
        $run = $this->newRun();
        $run->addConfiguration(new LlmConfiguration(Provider::ClaudeCode, 'opus'));
        $run->setTestcases([$this->makeTestcase('A', 'p', 'o', $this->project)]);

        try {
            $run->start(static fn (): bool => false, $this->judge());
            self::fail('start without an available configuration must fail');
        } catch (\LogicException $exception) {
            self::assertSame('No LLM configuration has an API key.', $exception->getMessage());
        }
        self::assertCount(0, $run->getVersions());
    }

    public function testStartCopiesJudge(): void
    {
        $run = $this->newRun();
        $run->addConfiguration(new LlmConfiguration(Provider::ClaudeCode, 'opus'));
        $run->setTestcases([$this->makeTestcase('A', 'p', 'o', $this->project)]);
        $judge = new LlmConfiguration(Provider::Gemini, 'gemini-3.5-flash');

        $version = $this->start($run, $judge);

        self::assertSame('gemini:gemini-3.5-flash:-', $version->getJudge()?->key());
    }

    public function testVersionStatusIsDerivedFromResults(): void
    {
        $run = $this->newRun();
        $run->addConfiguration(new LlmConfiguration(Provider::ClaudeCode, 'opus'));
        $run->setTestcases([$this->makeTestcase('A', 'p', 'o', $this->project), $this->makeTestcase('B', 'p', 'o', $this->project)]);
        $version = $this->start($run);
        /** @var list<RunResult> $results */
        $results = array_values($version->getResults()->toArray());

        self::assertSame(RunVersionStatus::Running, $version->getStatus());

        $results[0]->markRunning();
        self::assertSame(RunVersionStatus::Running, $version->getStatus());
        $results[0]->markJudging('answer', null, null, 1);
        self::assertSame(RunVersionStatus::Running, $version->getStatus());
        $results[0]->markDone([['criterion' => 'c', 'met' => true, 'reason' => 'r']]);
        self::assertSame(RunVersionStatus::Running, $version->getStatus(), 'the other result is still pending');

        $results[1]->markFailed('timeout', RunResultStatus::Pending);
        self::assertSame(RunVersionStatus::CompletedWithErrors, $version->getStatus());

        $clean = $this->start($run);
        foreach ($clean->getResults() as $result) {
            $result->markRunning();
            $result->markJudging('answer', null, null, 1);
            $result->markDone([['criterion' => 'c', 'met' => true, 'reason' => 'r']]);
        }
        self::assertSame(RunVersionStatus::Completed, $clean->getStatus());

        $old = new RunVersion($run, 99, 'Old', null, [['provider' => 'claude_code', 'model' => 'opus', 'effort' => null]], [], [], null);
        self::assertSame(RunVersionStatus::NotExecuted, $old->getStatus());
        self::assertNull($old->getJudge());
        self::assertSame([], $old->getSkippedConfigurations());
        self::assertCount(1, $old->getConfigurations());
    }

    public function testStatusLabelShowsWhetherCellsRunOrAreJudged(): void
    {
        $run = $this->newRun();
        $run->addConfiguration(new LlmConfiguration(Provider::ClaudeCode, 'opus'));
        $run->setTestcases([$this->makeTestcase('A', 'p', 'o', $this->project), $this->makeTestcase('B', 'p', 'o', $this->project)]);
        $version = $this->start($run);
        /** @var list<RunResult> $results */
        $results = array_values($version->getResults()->toArray());

        self::assertSame('running', $version->getStatusLabel(), 'pending cells count as running');
        foreach ($results as $result) {
            $result->markRunning();
            $result->markJudging('answer', null, null, 1);
        }
        self::assertSame('judging', $version->getStatusLabel());
        $results[0]->markDone([['criterion' => 'c', 'met' => true, 'reason' => 'r']]);
        self::assertSame('judging', $version->getStatusLabel());
        $results[1]->markDone([['criterion' => 'c', 'met' => true, 'reason' => 'r']]);
        self::assertSame('finished', $version->getStatusLabel());

        $failed = $this->start($run);
        foreach ($failed->getResults() as $result) {
            $result->markFailed('timeout', RunResultStatus::Pending);
        }
        self::assertSame('finished with errors', $failed->getStatusLabel());

        $old = new RunVersion($run, 99, 'Old', null, [['provider' => 'claude_code', 'model' => 'opus', 'effort' => null]], [], [], null);
        self::assertSame('not executed', $old->getStatusLabel());
    }

    public function testCriteriaScoreIsTheShareOfMetCriteriaOverAllJudgedCells(): void
    {
        $run = $this->newRun();
        $run->addConfiguration(new LlmConfiguration(Provider::ClaudeCode, 'opus'));
        $run->setTestcases([$this->makeTestcase('A', 'p', 'o', $this->project), $this->makeTestcase('B', 'p', 'o', $this->project), $this->makeTestcase('C', 'p', 'o', $this->project)]);
        $version = $this->start($run);
        /** @var list<RunResult> $results */
        $results = array_values($version->getResults()->toArray());

        self::assertNull($version->getCriteriaScore(), 'nothing judged yet');
        foreach ($results as $result) {
            $result->markRunning();
            $result->markJudging('answer', null, null, 1);
        }
        $results[0]->markDone([['criterion' => 'a', 'met' => true, 'reason' => 'r'], ['criterion' => 'b', 'met' => true, 'reason' => 'r']]);
        self::assertSame(100, $version->getCriteriaScore());
        $results[1]->markDone([['criterion' => 'a', 'met' => true, 'reason' => 'r'], ['criterion' => 'b', 'met' => false, 'reason' => 'r']]);
        self::assertSame(75, $version->getCriteriaScore(), '3 of 4 criteria, not the mean of the cell scores');
        $results[2]->markFailed('invalid_judge_answer', RunResultStatus::Judging);
        self::assertSame(75, $version->getCriteriaScore(), 'a failed cell has no criteria');
    }

    public function testAveragesCountOnlyResultsThatHaveTheValue(): void
    {
        $run = $this->newRun();
        $run->addConfiguration(new LlmConfiguration(Provider::ClaudeCode, 'opus'));
        $run->setTestcases([$this->makeTestcase('A', 'p', 'o', $this->project), $this->makeTestcase('B', 'p', 'o', $this->project), $this->makeTestcase('C', 'p', 'o', $this->project)]);
        $version = $this->start($run);
        /** @var list<RunResult> $results */
        $results = array_values($version->getResults()->toArray());

        self::assertNull($version->getAverageDurationMs());
        self::assertNull($version->getAverageInputTokens());
        self::assertNull($version->getAverageOutputTokens());

        $results[0]->markRunning();
        $results[0]->markJudging('a', 100, 10, 1000);
        $results[1]->markRunning();
        $results[1]->markJudging('b', null, 15, 2000);
        $results[2]->markRunning();
        $results[2]->markFailed('timeout', RunResultStatus::Running);

        self::assertSame(1500.0, $version->getAverageDurationMs(), 'the failed cell has no duration');
        self::assertSame(100.0, $version->getAverageInputTokens());
        self::assertSame(12.5, $version->getAverageOutputTokens());
    }

    public function testExistingVersionsDoNotChangeWhenTheRunOrItsSkillsChangeLater(): void
    {
        $skill = $this->skill('Review', 'skill v1');
        $run = $this->newRun();
        $run->setTitle('Nightly');
        $run->addConfiguration(new LlmConfiguration(Provider::ClaudeCode, 'opus'));
        $run->setSkills([$skill]);
        $run->setTestcases([$this->makeTestcase('T', 'p', 'o', $this->project)]);
        $first = $this->start($run);

        $run->setTitle('Renamed');
        $run->removeConfiguration('claude_code:opus:-');
        $run->addConfiguration(new LlmConfiguration(Provider::Gemini, 'gemini-3.5-flash'));
        $run->setSkills([]);
        $skill->saveFinal('Review', null, 'skill v2');
        $second = $this->start($run);

        self::assertSame('Nightly', $first->getTitle());
        self::assertSame(['claude_code:opus:-'], array_map(static fn (LlmConfiguration $c): string => $c->key(), $first->getConfigurations()));
        self::assertSame('1', $this->only($first->getSkillVersions())->getLabel());
        self::assertSame('skill v1', $this->only($first->getSkillVersions())->getContent());
        self::assertCount(0, $second->getSkillVersions());
        self::assertSame('Renamed', $second->getTitle());
    }

    public function testSetSkillsAndTestcasesSyncTheAssignment(): void
    {
        $run = $this->newRun();
        $a = $this->skill('A', 'x');
        $b = $this->skill('B', 'x');

        $run->setSkills([$a, $b]);
        $run->setSkills([$b]);

        self::assertSame([$b], array_values($run->getSkills()->toArray()));
    }

    public function testStartLeavesOutDeletedAndForeignSkillsAndTestcases(): void
    {
        $kept = $this->skill('Kept', 'x');
        $deleted = $this->skill('Deleted', 'x');
        $deleted->softDelete();
        $foreign = new Skill('Foreign', null, 'x');
        $foreign->addProject(new Project());
        $testcase = $this->makeTestcase('Greeting', 'p', 'o', $this->project);
        $deletedTestcase = $this->makeTestcase('Deleted case', 'p', 'o', $this->project);
        $deletedTestcase->softDelete();
        $foreignTestcase = $this->makeTestcase('Foreign case', 'p', 'o', new Project());
        $run = $this->newRun();
        $run->addConfiguration(new LlmConfiguration(Provider::ClaudeCode, 'opus'));
        $run->setSkills([$kept, $deleted, $foreign]);
        $run->setTestcases([$testcase, $deletedTestcase, $foreignTestcase]);

        $version = $this->start($run);

        self::assertSame(['Kept'], array_map(static fn ($v): string => $v->getTitle(), $version->getSkillVersions()->toArray()));
        self::assertSame(['Greeting'], array_map(static fn ($v): string => $v->getTitle(), $version->getTestcaseVersions()->toArray()));
    }

    public function testStartNeedsAValidTestcaseNotOnlyAnAssignedOne(): void
    {
        $deleted = $this->makeTestcase('Deleted case', 'p', 'o', $this->project);
        $deleted->softDelete();
        $run = $this->newRun();
        $run->addConfiguration(new LlmConfiguration(Provider::ClaudeCode, 'opus'));
        $run->setTestcases([$deleted]);

        $this->expectException(\LogicException::class);
        $this->start($run);
    }

    public function testUpdatedAtMovesOnlyWhenTheAssignmentChanges(): void
    {
        $run = $this->newRun();
        $skill = $this->skill('A', 'x');
        $run->setSkills([$skill]);
        $before = $run->getUpdatedAt();

        usleep(2000);
        $run->setSkills([$skill]);
        $run->setTestcases([]);
        self::assertEquals($before, $run->getUpdatedAt());

        usleep(2000);
        $run->setSkills([]);
        self::assertGreaterThan($before, $run->getUpdatedAt());
    }

    /**
     * @template T of object
     *
     * @param Collection<int, T> $collection
     *
     * @return T
     */
    private function only(Collection $collection): object
    {
        $items = array_values($collection->toArray());
        self::assertCount(1, $items);

        return $items[0];
    }

    /** @return list<string> */
    private function configurationKeys(Run $run): array
    {
        return array_map(static fn (LlmConfiguration $c): string => $c->key(), $run->getConfigurations());
    }

    private function judge(): LlmConfiguration
    {
        return new LlmConfiguration(Provider::ClaudeCode, 'opus');
    }

    private function start(Run $run, ?LlmConfiguration $judge = null): RunVersion
    {
        return $run->start(static fn (): bool => true, $judge ?? $this->judge());
    }

    private function newRun(): Run
    {
        return new Run($this->project);
    }

    private function skill(string $title, string $content): Skill
    {
        $skill = new Skill($title, null, $content);
        $skill->addProject($this->project);

        return $skill;
    }

    private function makeTestcase(string $title, string $prompt, string $outputCriteria, Project $project): Testcase
    {
        $testcase = new Testcase($title, null, $prompt, $outputCriteria);
        $testcase->addProject($project);

        return $testcase;
    }
}
