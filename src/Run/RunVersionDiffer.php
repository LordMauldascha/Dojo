<?php

declare(strict_types=1);

namespace App\Run;

use App\Ai\LlmConfiguration;
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
        );
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
