<?php

declare(strict_types=1);

namespace App\Run;

use App\Ai\LlmConfiguration;
use App\Entity\SkillVersion;
use App\Entity\TestcaseVersion;

final readonly class RunVersionDiff
{
    /**
     * @param array<string, array{from: string|null, to: string|null}>                           $fields
     * @param list<LlmConfiguration>                                                             $configurationsAdded
     * @param list<LlmConfiguration>                                                             $configurationsRemoved
     * @param list<SkillVersion>                                                                 $skillsAdded
     * @param list<SkillVersion>                                                                 $skillsRemoved
     * @param list<array{from: SkillVersion, to: SkillVersion}>                                  $skillsChanged
     * @param list<TestcaseVersion>                                                              $testcasesAdded
     * @param list<TestcaseVersion>                                                              $testcasesRemoved
     * @param list<array{from: TestcaseVersion, to: TestcaseVersion}>                            $testcasesChanged
     * @param list<array{testcase: string, configuration: string, from: int|null, to: int|null}> $scores                cells in both versions with a score in at least one
     * @param array<string, array{from: float|null, to: float|null, cells: int}>                 $averages              mean score per configuration label over the cells of $scores scored in both versions, and their number
     */
    public function __construct(
        public array $fields,
        public array $configurationsAdded,
        public array $configurationsRemoved,
        public array $skillsAdded,
        public array $skillsRemoved,
        public array $skillsChanged,
        public array $testcasesAdded,
        public array $testcasesRemoved,
        public array $testcasesChanged,
        public array $scores = [],
        public array $averages = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->fields
            && [] === $this->configurationsAdded
            && [] === $this->configurationsRemoved
            && [] === $this->skillsAdded
            && [] === $this->skillsRemoved
            && [] === $this->skillsChanged
            && [] === $this->testcasesAdded
            && [] === $this->testcasesRemoved
            && [] === $this->testcasesChanged
            && [] === $this->scores;
    }
}
