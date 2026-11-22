<?php

declare(strict_types=1);

namespace App\Run;

use App\Ai\LlmConfiguration;
use App\Entity\RunResult;
use App\Entity\RunVersion;
use App\Entity\SkillVersion;
use App\Entity\TestcaseVersion;

/** Compares two run versions: fields, configurations, and skills/testcases (added, removed, other version). */
final class RunVersionDiffer
{
    public function diff(RunVersion $from, RunVersion $to): RunVersionDiff
    {
        $fields = [];
        if ($from->getTitle() !== $to->getTitle()) {
            $fields['title'] = ['from' => $from->getTitle(), 'to' => $to->getTitle()];
        }
        if ($from->getDescription() !== $to->getDescription()) {
            $fields['description'] = ['from' => $from->getDescription(), 'to' => $to->getDescription()];
        }

        $fromKeys = array_map(static fn (LlmConfiguration $c): string => $c->key(), $from->getConfigurations());
        $toKeys = array_map(static fn (LlmConfiguration $c): string => $c->key(), $to->getConfigurations());

        [$skillsAdded, $skillsRemoved, $skillsChanged] = $this->compare(
            array_values($from->getSkillVersions()->toArray()),
            array_values($to->getSkillVersions()->toArray()),
            static fn (SkillVersion $version): int => spl_object_id($version->getSkill()),
        );
        [$testcasesAdded, $testcasesRemoved, $testcasesChanged] = $this->compare(
            array_values($from->getTestcaseVersions()->toArray()),
            array_values($to->getTestcaseVersions()->toArray()),
            static fn (TestcaseVersion $version): int => spl_object_id($version->getTestcase()),
        );

        return new RunVersionDiff(
            $fields,
            array_values(array_filter($to->getConfigurations(), static fn (LlmConfiguration $c): bool => !\in_array($c->key(), $fromKeys, true))),
            array_values(array_filter($from->getConfigurations(), static fn (LlmConfiguration $c): bool => !\in_array($c->key(), $toKeys, true))),
            $skillsAdded,
            $skillsRemoved,
            $skillsChanged,
            $testcasesAdded,
            $testcasesRemoved,
            $testcasesChanged,
            ...$this->scores($from, $to),
        );
    }

    /**
     * Cells are matched by testcase (not its version) and configuration key; skipped configurations have no results.
     *
     * @return array{list<array{testcase: string, configuration: string, from: int|null, to: int|null}>, array<string, array{from: float|null, to: float|null, cells: int}>}
     */
    private function scores(RunVersion $from, RunVersion $to): array
    {
        $fromCells = [];
        foreach ($from->getResults() as $result) {
            $fromCells[$this->cell($result)] = $result;
        }

        $scores = [];
        $samples = [];
        foreach ($to->getResults() as $result) {
            $before = $fromCells[$this->cell($result)] ?? null;
            if (null === $before || (null === $before->getScore() && null === $result->getScore())) {
                continue;
            }
            $label = $result->getConfiguration()->label();
            $scores[] = ['testcase' => $result->getTestcaseVersion()->getTitle(), 'configuration' => $label, 'from' => $before->getScore(), 'to' => $result->getScore()];
            $samples[$label] ??= ['from' => [], 'to' => []];
            // like for like: a cell scored on one side only would move one average but not the other
            [$fromScore, $toScore] = [$before->getScore(), $result->getScore()];
            if (null !== $fromScore && null !== $toScore) {
                $samples[$label]['from'][] = $fromScore;
                $samples[$label]['to'][] = $toScore;
            }
        }

        $averages = array_map(fn (array $side): array => ['from' => $this->mean($side['from']), 'to' => $this->mean($side['to']), 'cells' => \count($side['from'])], $samples);

        return [$scores, $averages];
    }

    /**
     * Mean per configuration label and in total over the cells that exist in both versions. A value counts only when both sides have it,
     * the failure rate only over cells that are finished on both sides.
     *
     * @return list<array{label: string, score: array{from: float|null, to: float|null}, durationMs: array{from: float|null, to: float|null}, inputTokens: array{from: float|null, to: float|null}, outputTokens: array{from: float|null, to: float|null}, failureRate: array{from: float|null, to: float|null}}>
     */
    public function metrics(RunVersion $from, RunVersion $to): array
    {
        $fromCells = [];
        foreach ($from->getResults() as $result) {
            $fromCells[$this->cell($result)] = $result;
        }

        $pairs = [];
        foreach ($to->getResults() as $result) {
            if (null !== ($before = $fromCells[$this->cell($result)] ?? null)) {
                $pairs[$result->getConfiguration()->label()][] = [$before, $result];
            }
        }
        if ([] === $pairs) {
            return [];
        }

        $rows = [];
        foreach ($pairs as $label => $group) {
            $rows[] = $this->metricsRow((string) $label, $group);
        }
        $rows[] = $this->metricsRow('Total', array_merge(...array_values($pairs)));

        return $rows;
    }

    /**
     * @param list<array{RunResult, RunResult}> $pairs
     *
     * @return array{label: string, score: array{from: float|null, to: float|null}, durationMs: array{from: float|null, to: float|null}, inputTokens: array{from: float|null, to: float|null}, outputTokens: array{from: float|null, to: float|null}, failureRate: array{from: float|null, to: float|null}}
     */
    private function metricsRow(string $label, array $pairs): array
    {
        $finished = array_filter($pairs, static fn (array $pair): bool => !$pair[0]->getStatus()->isOpen() && !$pair[1]->getStatus()->isOpen());
        $failed = static fn (RunResult $result): int => RunResultStatus::Failed === $result->getStatus() ? 100 : 0;

        return [
            'label' => $label,
            'score' => $this->side($pairs, static fn (RunResult $r): ?int => $r->getScore()),
            'durationMs' => $this->side($pairs, static fn (RunResult $r): ?int => $r->getDurationMs()),
            'inputTokens' => $this->side($pairs, static fn (RunResult $r): ?int => $r->getInputTokens()),
            'outputTokens' => $this->side($pairs, static fn (RunResult $r): ?int => $r->getOutputTokens()),
            'failureRate' => [
                'from' => $this->mean(array_map(static fn (array $pair): int => $failed($pair[0]), array_values($finished))),
                'to' => $this->mean(array_map(static fn (array $pair): int => $failed($pair[1]), array_values($finished))),
            ],
        ];
    }

    /**
     * @param list<array{RunResult, RunResult}> $pairs
     * @param callable(RunResult): ?int         $value
     *
     * @return array{from: float|null, to: float|null}
     */
    private function side(array $pairs, callable $value): array
    {
        $both = array_filter(array_map(static fn (array $pair): array => [$value($pair[0]), $value($pair[1])], $pairs), static fn (array $values): bool => null !== $values[0] && null !== $values[1]);

        return [
            'from' => $this->mean(array_column($both, 0)),
            'to' => $this->mean(array_column($both, 1)),
        ];
    }

    private function cell(RunResult $result): string
    {
        return spl_object_id($result->getTestcaseVersion()->getTestcase()).'|'.$result->getConfigurationKey();
    }

    /** @param list<int> $scores */
    private function mean(array $scores): ?float
    {
        return [] === $scores ? null : array_sum($scores) / \count($scores);
    }

    /**
     * @template T of SkillVersion|TestcaseVersion
     *
     * @param list<T>          $from
     * @param list<T>          $to
     * @param callable(T): int $identity
     *
     * @return array{list<T>, list<T>, list<array{from: T, to: T}>}
     */
    private function compare(array $from, array $to, callable $identity): array
    {
        $fromById = [];
        foreach ($from as $version) {
            $fromById[$identity($version)] = $version;
        }
        $toById = [];
        foreach ($to as $version) {
            $toById[$identity($version)] = $version;
        }

        $added = $removed = $changed = [];
        foreach ($toById as $id => $version) {
            if (!isset($fromById[$id])) {
                $added[] = $version;
            } elseif ($fromById[$id]->getNumber() !== $version->getNumber()) {
                $changed[] = ['from' => $fromById[$id], 'to' => $version];
            }
        }
        foreach ($fromById as $id => $version) {
            if (!isset($toById[$id])) {
                $removed[] = $version;
            }
        }

        return [$added, $removed, $changed];
    }
}
