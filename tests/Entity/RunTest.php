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
    public function testConfigurationsAreDeduplicatedAndRemovedByIndex(): void
    {
        $run = $this->newRun();
        $gpt = new LlmConfiguration(Provider::OpenAi, 'gpt-5.4', Effort::High);

        self::assertTrue($run->addConfiguration($gpt));
        self::assertFalse($run->addConfiguration($gpt));
        self::assertTrue($run->addConfiguration(new LlmConfiguration(Provider::OpenAi, 'gpt-5.4')));
        self::assertTrue($run->addConfiguration(new LlmConfiguration(Provider::ClaudeOAuth, 'opus')));
        $before = $this->configurationKeys($run);
        self::assertCount(3, $before);

        self::assertTrue($run->removeConfigurationAt(1));
        self::assertFalse($run->removeConfigurationAt(5));
        $after = $this->configurationKeys($run);
        self::assertSame(['openai:gpt-5.4:high', 'claude_oauth:opus:-'], $after);
    }

    public function testUnknownModelOfAProviderIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->newRun()->addConfiguration(new LlmConfiguration(Provider::OpenAi, 'opus'));
    }

    public function testStartIsRefusedWithoutTestcaseOrConfiguration(): void
    {
        $run = $this->newRun();
        $run->addConfiguration(new LlmConfiguration(Provider::ClaudeOAuth, 'opus'));
        try {
            $run->start();
            self::fail('start without testcase must fail');
        } catch (\LogicException) {
        }

        $withoutConfiguration = $this->newRun();
        $withoutConfiguration->setTestcases([new Testcase('T', null, 'p', 'o')]);
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
        $skill = new Skill('Review', null, 'skill v1');
        $skill->saveFinal('Review', null, 'skill v2');
        $skill->saveDraft('Review', null, 'skill draft');
        $testcase = new Testcase('Greeting', null, 'prompt 1', 'output 1');
        $testcase->saveDraft('Greeting', null, 'prompt draft', 'output draft');
        $run = $this->newRun();
        $run->setTitle('Nightly');
        $run->setDescription('All reviews');
        $run->addConfiguration(new LlmConfiguration(Provider::ClaudeOAuth, 'opus', Effort::High));
        $run->setSkills([$skill]);
        $run->setTestcases([$testcase]);

        $first = $run->start();
        $second = $run->start();

        self::assertSame([1, 2], [$first->getNumber(), $second->getNumber()]);
        self::assertSame($second, $run->getLatestVersion());
        self::assertSame($first, $run->findVersion(1));
        self::assertSame('Nightly', $first->getTitle());
        self::assertSame('All reviews', $first->getDescription());
        self::assertSame(['claude_oauth:opus:high'], array_map(static fn (LlmConfiguration $c): string => $c->key(), $first->getConfigurations()));
        self::assertSame('2', $this->only($first->getSkillVersions())->getLabel());
        self::assertSame('1', $this->only($first->getTestcaseVersions())->getLabel());
    }

    public function testExistingVersionsDoNotChangeWhenTheRunOrItsSkillsChangeLater(): void
    {
        $skill = new Skill('Review', null, 'skill v1');
        $run = $this->newRun();
        $run->setTitle('Nightly');
        $run->addConfiguration(new LlmConfiguration(Provider::ClaudeOAuth, 'opus'));
        $run->setSkills([$skill]);
        $run->setTestcases([new Testcase('T', null, 'p', 'o')]);
        $first = $run->start();

        $run->setTitle('Renamed');
        $run->removeConfigurationAt(0);
        $run->addConfiguration(new LlmConfiguration(Provider::Gemini, 'gemini-2.5-pro'));
        $run->setSkills([]);
        $skill->saveFinal('Review', null, 'skill v2');
        $second = $run->start();

        self::assertSame('Nightly', $first->getTitle());
        self::assertSame(['claude_oauth:opus:-'], array_map(static fn (LlmConfiguration $c): string => $c->key(), $first->getConfigurations()));
        self::assertSame('1', $this->only($first->getSkillVersions())->getLabel());
        self::assertSame('skill v1', $this->only($first->getSkillVersions())->getContent());
        self::assertCount(0, $second->getSkillVersions());
        self::assertSame('Renamed', $second->getTitle());
    }

    public function testSetSkillsAndTestcasesSyncTheAssignment(): void
    {
        $run = $this->newRun();
        $a = new Skill('A', null, 'x');
        $b = new Skill('B', null, 'x');

        $run->setSkills([$a, $b]);
        $run->setSkills([$b]);

        self::assertSame([$b], array_values($run->getSkills()->toArray()));
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
        return new Run(new Project());
    }
}
