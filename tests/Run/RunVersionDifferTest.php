<?php

declare(strict_types=1);

namespace App\Tests\Run;

use App\Ai\Effort;
use App\Ai\LlmConfiguration;
use App\Ai\Provider;
use App\Entity\Project;
use App\Entity\Run;
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
        $diff = (new RunVersionDiffer())->diff($this->run->start(), $this->run->start());

        self::assertTrue($diff->isEmpty());
    }

    public function testChangedTitleAndDescriptionAreReported(): void
    {
        $first = $this->run->start();
        $this->run->setTitle('Weekly');
        $this->run->setDescription(null);
        $second = $this->run->start();

        $diff = (new RunVersionDiffer())->diff($first, $second);

        self::assertSame([
            'title' => ['from' => 'Nightly', 'to' => 'Weekly'],
            'description' => ['from' => 'All reviews', 'to' => null],
        ], $diff->fields);
        self::assertFalse($diff->isEmpty());
    }

    public function testAddedAndRemovedConfigurationsAreReported(): void
    {
        $first = $this->run->start();
        $this->run->removeConfiguration('claude_code:opus:high');
        $this->run->addConfiguration(new LlmConfiguration(Provider::Gemini, 'gemini-2.5-pro'));
        $this->run->addConfiguration(new LlmConfiguration(Provider::ClaudeCode, 'opus'));
        $second = $this->run->start();

        $diff = (new RunVersionDiffer())->diff($first, $second);

        self::assertSame(['gemini:gemini-2.5-pro:-', 'claude_code:opus:-'], array_map(static fn (LlmConfiguration $c): string => $c->key(), $diff->configurationsAdded));
        self::assertSame(['claude_code:opus:high'], array_map(static fn (LlmConfiguration $c): string => $c->key(), $diff->configurationsRemoved));
    }

    public function testSkillsAddedRemovedAndChangedAreReported(): void
    {
        $kept = $this->skill('Kept');
        $dropped = $this->skill('Dropped');
        $new = $this->skill('New');
        $this->run->setSkills([$kept, $dropped]);
        $first = $this->run->start();

        $kept->saveFinal('Kept', null, 'v2');
        $this->run->setSkills([$kept, $new]);
        $second = $this->run->start();

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
        $first = $this->run->start();

        $this->testcase->saveFinal('Greeting', null, 'prompt 2', 'output 2');
        $this->run->setTestcases([$this->testcase, $extra]);
        $second = $this->run->start();
        $this->run->setTestcases([$extra]);
        $third = $this->run->start();

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
        $first = $this->run->start();
        $skill->softDelete();
        $this->run->setSkills([]);
        $second = $this->run->start();

        $diff = (new RunVersionDiffer())->diff($first, $second);

        self::assertCount(1, $diff->skillsRemoved);
        self::assertSame('Review', $diff->skillsRemoved[0]->getTitle());
        self::assertTrue($diff->skillsRemoved[0]->getSkill()->isDeleted());
    }

    private function skill(string $title): Skill
    {
        $skill = new Skill($title, null, 'v1');
        $skill->addProject($this->project);

        return $skill;
    }

    private function makeTestcase(string $title, string $prompt, string $expectedOutput, Project $project): Testcase
    {
        $testcase = new Testcase($title, null, $prompt, $expectedOutput);
        $testcase->addProject($project);

        return $testcase;
    }
}
