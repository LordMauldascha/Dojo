<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Ai\Effort;
use App\Ai\LlmConfiguration;
use App\Ai\Provider;
use App\Entity\Project;
use App\Entity\Run;
use App\Entity\Skill;
use App\Entity\Testcase;
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
            $run->start();
            self::fail('start without testcase must fail');
        } catch (\LogicException) {
        }

        $withoutConfiguration = $this->newRun();
        $withoutConfiguration->setTestcases([$this->makeTestcase('T', 'p', 'o', $this->project)]);
        try {
            $withoutConfiguration->start();
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

        $first = $run->start();
        $second = $run->start();

        self::assertSame([1, 2], [$first->getNumber(), $second->getNumber()]);
        self::assertSame($second, $run->getLatestVersion());
        self::assertSame($first, $run->findVersion(1));
        self::assertSame('Nightly', $first->getTitle());
        self::assertSame('All reviews', $first->getDescription());
        self::assertSame(['claude_code:opus:high'], array_map(static fn (LlmConfiguration $c): string => $c->key(), $first->getConfigurations()));
        self::assertSame('2', $this->only($first->getSkillVersions())->getLabel());
        self::assertSame('1', $this->only($first->getTestcaseVersions())->getLabel());
    }

    public function testExistingVersionsDoNotChangeWhenTheRunOrItsSkillsChangeLater(): void
    {
        $skill = $this->skill('Review', 'skill v1');
        $run = $this->newRun();
        $run->setTitle('Nightly');
        $run->addConfiguration(new LlmConfiguration(Provider::ClaudeCode, 'opus'));
        $run->setSkills([$skill]);
        $run->setTestcases([$this->makeTestcase('T', 'p', 'o', $this->project)]);
        $first = $run->start();

        $run->setTitle('Renamed');
        $run->removeConfiguration('claude_code:opus:-');
        $run->addConfiguration(new LlmConfiguration(Provider::Gemini, 'gemini-2.5-pro'));
        $run->setSkills([]);
        $skill->saveFinal('Review', null, 'skill v2');
        $second = $run->start();

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

        $version = $run->start();

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
        $run->start();
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
