<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Ai\LlmConfiguration;
use App\Ai\Provider;
use App\Entity\Project;
use App\Entity\Run;
use App\Entity\RunResult;
use App\Entity\Testcase;
use App\Run\RunResultStatus;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;

final class RunResultTest extends PhpUnitTestCase
{
    public function testScoreIsPercentageOfMetCriteria(): void
    {
        $four = $this->judging();
        $four->markDone($this->criteria([true, true, true, true, false]));
        $one = $this->judging();
        $one->markDone($this->criteria([true, false, false]));

        self::assertSame(80, $four->getScore());
        self::assertSame(33, $one->getScore());
        self::assertSame(RunResultStatus::Done, $four->getStatus());
        self::assertCount(5, $four->getCriteria() ?? []);
        self::assertNotNull($four->getFinishedAt());
    }

    public function testTransitionFromWrongStatusChangesNothing(): void
    {
        $result = $this->newResult();

        self::assertFalse($result->markPending());
        self::assertFalse($result->markJudging('answer', 1, 2, 3));
        self::assertFalse($result->markDone($this->criteria([true])));
        self::assertFalse($result->markFailed('timeout', RunResultStatus::Running));
        self::assertSame(RunResultStatus::Pending, $result->getStatus());
        self::assertNull($result->getOutput());
        self::assertNull($result->getError());
    }

    public function testEmptyRatingOnlyThrowsInTheJudgingStatus(): void
    {
        $result = $this->newResult();

        self::assertFalse($result->markDone([]));
        self::assertSame(RunResultStatus::Pending, $result->getStatus());

        $result->markRunning();
        $result->markJudging('answer', null, null, 1);
        $this->expectException(\InvalidArgumentException::class);
        $result->markDone([]);
    }

    public function testFinishedResultsCannotBeFailedOrOverwritten(): void
    {
        $done = $this->judging();
        $done->markDone($this->criteria([true]));
        self::assertFalse($done->markFailed('timeout', RunResultStatus::Done));
        self::assertNull($done->getError());
        self::assertSame(RunResultStatus::Done, $done->getStatus());

        $failed = $this->newResult();
        $failed->markFailed('first', RunResultStatus::Pending);
        self::assertFalse($failed->markFailed('second', RunResultStatus::Failed));
        self::assertSame('first', $failed->getError());
    }

    public function testTransitionsFollowTheLifecycle(): void
    {
        $result = $this->newResult();

        self::assertTrue($result->markRunning());
        self::assertFalse($result->markRunning());
        self::assertTrue($result->markPending());
        self::assertTrue($result->markRunning());
        self::assertTrue($result->markJudging('answer', 10, 20, 300));
        self::assertSame(['answer', 10, 20, 300], [$result->getOutput(), $result->getInputTokens(), $result->getOutputTokens(), $result->getDurationMs()]);
        self::assertFalse($result->markFailed('timeout', RunResultStatus::Running));
        self::assertSame(RunResultStatus::Judging, $result->getStatus());
        self::assertTrue($result->markFailed('timeout', RunResultStatus::Judging));
        self::assertSame('timeout', $result->getError());
        self::assertNotNull($result->getFinishedAt());
    }

    public function testStaleAfterThirtyMinutesOnlyWhenRunningOrJudging(): void
    {
        $result = $this->newResult();
        $changed = $result->getStatusChangedAt();
        $later = $changed->modify('+31 minutes');

        self::assertFalse($result->isStale($later), 'pending never times out');

        $result->markRunning();
        $changed = $result->getStatusChangedAt();
        self::assertTrue($result->isStale($changed->modify('+31 minutes')));
        self::assertFalse($result->isStale($changed->modify('+29 minutes')));

        $result->markJudging('answer', null, null, 1);
        $changed = $result->getStatusChangedAt();
        self::assertTrue($result->isStale($changed->modify('+31 minutes')));

        $result->markDone($this->criteria([true]));
        self::assertFalse($result->isStale($later->modify('+1 day')));

        $failed = $this->newResult();
        $failed->markFailed('timeout', RunResultStatus::Pending);
        self::assertFalse($failed->isStale($later->modify('+1 day')));
    }

    private function judging(): RunResult
    {
        $result = $this->newResult();
        $result->markRunning();
        $result->markJudging('answer', null, null, 1);

        return $result;
    }

    /**
     * @param list<bool> $met
     *
     * @return list<array{criterion: string, met: bool, reason: string}>
     */
    private function criteria(array $met): array
    {
        return array_map(static fn (bool $value, int $index): array => ['criterion' => 'criterion '.$index, 'met' => $value, 'reason' => 'reason'], $met, array_keys($met));
    }

    private function newResult(): RunResult
    {
        $project = new Project();
        $testcase = new Testcase('Greeting', null, 'prompt', 'output');
        $testcase->addProject($project);
        $run = new Run($project);
        $run->setTestcases([$testcase]);
        $configuration = new LlmConfiguration(Provider::ClaudeCode, 'opus');
        $run->addConfiguration($configuration);
        $version = $run->start(static fn (): bool => true, $configuration);

        return $version->getResults()->first() ?: self::fail('no result');
    }
}
