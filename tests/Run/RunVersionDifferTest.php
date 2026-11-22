<?php

declare(strict_types=1);

namespace App\Tests\Run;

use App\Ai\Effort;
use App\Ai\LlmConfiguration;
use App\Ai\Provider;
use App\Entity\Project;
use App\Entity\Run;
use App\Entity\RunVersion;
use App\Entity\Skill;
use App\Entity\Testcase;
use App\Run\RunVersionDiffer;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;

final class RunVersionDifferTest extends PhpUnitTestCase
{
    private Project $project;
    private Testcase $testcase;
    private Run $run;

    protected function setUp(): void
    {
        $this->project = new Project();
        $this->testcase = $this->makeTestcase('Greeting', 'prompt 1', 'output 1', $this->project);
        $this->run = new Run($this->project);
        $this->run->setTitle('Nightly');
        $this->run->setDescription('All reviews');
        $this->run->addConfiguration(new LlmConfiguration(Provider::ClaudeCode, 'opus', Effort::High));
        $this->run->setTestcases([$this->testcase]);
    }

    public function testIdenticalVersionsHaveNoDifference(): void
    {
        $diff = (new RunVersionDiffer())->diff($this->start(), $this->start());

        self::assertTrue($diff->isEmpty());
    }

    public function testChangedTitleAndDescriptionAreReported(): void
    {
        $first = $this->start();
        $this->run->setTitle('Weekly');
        $this->run->setDescription(null);
        $second = $this->start();

        $diff = (new RunVersionDiffer())->diff($first, $second);

        self::assertSame([
            'title' => ['from' => 'Nightly', 'to' => 'Weekly'],
            'description' => ['from' => 'All reviews', 'to' => null],
        ], $diff->fields);
        self::assertFalse($diff->isEmpty());
    }

    public function testAddedAndRemovedConfigurationsAreReported(): void
    {
        $first = $this->start();
        $this->run->removeConfiguration('claude_code:opus:high');
        $this->run->addConfiguration(new LlmConfiguration(Provider::Gemini, 'gemini-3.5-flash'));
        $this->run->addConfiguration(new LlmConfiguration(Provider::ClaudeCode, 'opus'));
        $second = $this->start();

        $diff = (new RunVersionDiffer())->diff($first, $second);

        self::assertSame(['gemini:gemini-3.5-flash:-', 'claude_code:opus:-'], array_map(static fn (LlmConfiguration $c): string => $c->key(), $diff->configurationsAdded));
        self::assertSame(['claude_code:opus:high'], array_map(static fn (LlmConfiguration $c): string => $c->key(), $diff->configurationsRemoved));
    }

    public function testSkillsAddedRemovedAndChangedAreReported(): void
    {
        $kept = $this->skill('Kept');
        $dropped = $this->skill('Dropped');
        $new = $this->skill('New');
        $this->run->setSkills([$kept, $dropped]);
        $first = $this->start();

        $kept->saveFinal('Kept', null, 'v2');
        $this->run->setSkills([$kept, $new]);
        $second = $this->start();

        $diff = (new RunVersionDiffer())->diff($first, $second);

        self::assertSame(['New'], array_map(static fn ($v): string => $v->getTitle(), $diff->skillsAdded));
        self::assertSame(['Dropped'], array_map(static fn ($v): string => $v->getTitle(), $diff->skillsRemoved));
        self::assertCount(1, $diff->skillsChanged);
        self::assertSame('Kept', $diff->skillsChanged[0]['to']->getTitle());
        self::assertSame(1, $diff->skillsChanged[0]['from']->getNumber());
        self::assertSame(2, $diff->skillsChanged[0]['to']->getNumber());
        self::assertSame([], $diff->testcasesAdded);
    }

    public function testTestcasesAddedRemovedAndChangedAreReported(): void
    {
        $extra = $this->makeTestcase('Extra', 'p', 'o', $this->project);
        $first = $this->start();

        $this->testcase->saveFinal('Greeting', null, 'prompt 2', 'output 2');
        $this->run->setTestcases([$this->testcase, $extra]);
        $second = $this->start();
        $this->run->setTestcases([$extra]);
        $third = $this->start();

        $differ = new RunVersionDiffer();
        $firstToSecond = $differ->diff($first, $second);
        self::assertSame(['Extra'], array_map(static fn ($v): string => $v->getTitle(), $firstToSecond->testcasesAdded));
        self::assertCount(1, $firstToSecond->testcasesChanged);
        self::assertSame([1, 2], [$firstToSecond->testcasesChanged[0]['from']->getNumber(), $firstToSecond->testcasesChanged[0]['to']->getNumber()]);

        $secondToThird = $differ->diff($second, $third);
        self::assertSame(['Greeting'], array_map(static fn ($v): string => $v->getTitle(), $secondToThird->testcasesRemoved));
    }

    public function testSoftDeletedSkillsStayVisibleInTheDiff(): void
    {
        $skill = $this->skill('Review');
        $this->run->setSkills([$skill]);
        $first = $this->start();
        $skill->softDelete();
        $this->run->setSkills([]);
        $second = $this->start();

        $diff = (new RunVersionDiffer())->diff($first, $second);

        self::assertCount(1, $diff->skillsRemoved);
        self::assertSame('Review', $diff->skillsRemoved[0]->getTitle());
        self::assertTrue($diff->skillsRemoved[0]->getSkill()->isDeleted());
    }

    public function testScoresAreComparedPerCellAcrossTestcaseVersions(): void
    {
        $other = $this->makeTestcase('Other', 'p', 'o', $this->project);
        $this->run->setTestcases([$this->testcase, $other]);
        $first = $this->start();
        $this->score($first, $this->testcase, 60);
        $this->score($first, $other, 80);

        $this->testcase->saveFinal('Greeting', null, 'prompt 2', 'output 2');
        $second = $this->start();
        $this->score($second, $this->testcase, 80);
        $this->score($second, $other, 80);

        $diff = (new RunVersionDiffer())->diff($first, $second);

        $label = (new LlmConfiguration(Provider::ClaudeCode, 'opus', Effort::High))->label();
        self::assertSame([
            ['testcase' => 'Greeting', 'configuration' => $label, 'from' => 60, 'to' => 80],
            ['testcase' => 'Other', 'configuration' => $label, 'from' => 80, 'to' => 80],
        ], $diff->scores);
        self::assertSame([$label => ['from' => 70.0, 'to' => 80.0, 'cells' => 2]], $diff->averages);
    }

    public function testCellsInOnlyOneVersionAreNotCompared(): void
    {
        $extra = $this->makeTestcase('Extra', 'p', 'o', $this->project);
        $first = $this->start();
        $this->score($first, $this->testcase, 50);
        $this->run->setTestcases([$this->testcase, $extra]);
        $this->run->addConfiguration(new LlmConfiguration(Provider::Gemini, 'gemini-3.5-flash'));
        $second = $this->start();
        $this->score($second, $this->testcase, 50);
        $this->score($second, $extra, 90);

        $diff = (new RunVersionDiffer())->diff($first, $second);

        self::assertSame([['testcase' => 'Greeting', 'configuration' => (new LlmConfiguration(Provider::ClaudeCode, 'opus', Effort::High))->label(), 'from' => 50, 'to' => 50]], $diff->scores);
        self::assertSame(['Claude Code / opus / effort high'], array_keys($diff->averages));
    }

    public function testMissingScoreStaysNullAndAveragesUseOnlyCellsScoredInBothVersions(): void
    {
        $other = $this->makeTestcase('Other', 'p', 'o', $this->project);
        $unrated = $this->makeTestcase('Unrated', 'p', 'o', $this->project);
        $this->run->setTestcases([$this->testcase, $other, $unrated]);
        $first = $this->start();
        $this->score($first, $this->testcase, 50);
        $this->score($first, $other, 100);
        $second = $this->start();
        $this->score($second, $this->testcase, 40);

        $diff = (new RunVersionDiffer())->diff($first, $second);

        $label = (new LlmConfiguration(Provider::ClaudeCode, 'opus', Effort::High))->label();
        self::assertSame([
            ['testcase' => 'Greeting', 'configuration' => $label, 'from' => 50, 'to' => 40],
            ['testcase' => 'Other', 'configuration' => $label, 'from' => 100, 'to' => null],
        ], $diff->scores);
        // "Other" lost its score: averaging it on one side only would show 75.0 -> 40.0
        self::assertSame([$label => ['from' => 50.0, 'to' => 40.0, 'cells' => 1]], $diff->averages);
    }

    public function testConfigurationWithoutACellScoredInBothVersionsHasNoAverage(): void
    {
        $other = $this->makeTestcase('Other', 'p', 'o', $this->project);
        $this->run->setTestcases([$this->testcase, $other]);
        $first = $this->start();
        $this->score($first, $this->testcase, 70);
        $second = $this->start();
        $this->score($second, $other, 90);

        $diff = (new RunVersionDiffer())->diff($first, $second);

        $label = (new LlmConfiguration(Provider::ClaudeCode, 'opus', Effort::High))->label();
        self::assertCount(2, $diff->scores);
        self::assertSame([$label => ['from' => null, 'to' => null, 'cells' => 0]], $diff->averages);
    }

    public function testVersionsWithoutAnyScoreHaveNoScoreDifference(): void
    {
        $diff = (new RunVersionDiffer())->diff($this->start(), $this->start());

        self::assertSame([], $diff->scores);
        self::assertSame([], $diff->averages);
        self::assertTrue($diff->isEmpty());
    }

    public function testConfigurationSkippedInOneVersionIsNotReportedAsRemoved(): void
    {
        $this->run->addConfiguration(new LlmConfiguration(Provider::Gemini, 'gemini-3.5-flash'));
        $first = $this->start();
        $second = $this->run->start(static fn (Provider $provider): bool => Provider::Gemini !== $provider, new LlmConfiguration(Provider::ClaudeCode, 'opus'));

        $diff = (new RunVersionDiffer())->diff($first, $second);

        self::assertCount(1, $second->getSkippedConfigurations());
        self::assertSame([], $diff->configurationsRemoved);
        self::assertSame([], $diff->configurationsAdded);
    }

    private function skill(string $title): Skill
    {
        $skill = new Skill($title, null, 'v1');
        $skill->addProject($this->project);

        return $skill;
    }

    private function makeTestcase(string $title, string $prompt, string $outputCriteria, Project $project): Testcase
    {
        $testcase = new Testcase($title, null, $prompt, $outputCriteria);
        $testcase->addProject($project);

        return $testcase;
    }

    /** Rates the result of the testcase's active version with $score percent (100 criteria, $score of them met). */
    private function score(RunVersion $version, Testcase $testcase, int $score): void
    {
        foreach ($version->getResults() as $result) {
            if ($result->getTestcaseVersion()->getTestcase() !== $testcase || Provider::ClaudeCode !== $result->getConfiguration()->provider) {
                continue;
            }
            $result->markRunning();
            $result->markJudging('answer', null, null, 1);
            $result->markDone(array_map(static fn (int $i): array => ['criterion' => 'c'.$i, 'met' => $score > $i, 'reason' => 'r'], range(0, 99)));
        }
    }

    private function start(): RunVersion
    {
        return $this->run->start(static fn (): bool => true, new LlmConfiguration(Provider::ClaudeCode, 'opus'));
    }
}
