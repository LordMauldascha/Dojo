<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Ai\EffortMapper;
use App\Ai\LlmConfiguration;
use App\Entity\Run;
use App\Entity\RunResult;
use App\Entity\RunVersion;
use App\Entity\SkillVersion;
use App\Entity\TestcaseVersion;
use App\Mcp\ToolSupport;
use App\Repository\RunRepository;
use App\Run\RunCompareQuery;
use App\Run\RunVersionDiffer;
use App\Run\StaleResultSweeper;
use App\Security\Voter\RunVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

/** Run versions as in the web UI: the snapshot with its result matrix, and the comparison of two versions. Rights are those of the signed-in user. */
final readonly class RunVersionTools
{
    public function __construct(
        private ToolSupport $support,
        private RunRepository $runs,
        private StaleResultSweeper $sweeper,
        private RunVersionDiffer $differ,
    ) {
    }

    /** @return array<string, mixed> */
    #[McpTool(
        name: 'get_run_version',
        description: 'Shows one version (snapshot) of a run: status, progress (done/total), judge, configurations (with skipped and effort notes), skills and testcases as started, and the result matrix per testcase and configuration with status, score, met/total criteria, error code, duration and tokens. The model outputs and the judge reasons can be very large and are only included with includeOutputs=true.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    public function get(string $runUuid, int $number, bool $includeOutputs = false): array
    {
        $run = $this->loadViewable($runUuid);
        $version = $run->findVersion($number) ?? throw new ToolCallException('Version not found.');
        $this->sweeper->sweep($version);

        $skipped = array_map(static fn (LlmConfiguration $configuration): string => $configuration->key(), $version->getSkippedConfigurations());
        $judge = $version->getJudge();

        return $this->summary($version) + [
            'run' => ['uuid' => $run->getUuid()->toRfc4122(), 'title' => $run->getTitle()],
            'title' => $version->getTitle(),
            'description' => $version->getDescription(),
            'judge' => null === $judge ? null : ['provider' => $judge->provider->value, 'model' => $judge->model],
            'configurations' => array_map(
                static fn (LlmConfiguration $configuration): array => [
                    'key' => $configuration->key(),
                    'label' => $configuration->label(),
                    'skipped' => \in_array($configuration->key(), $skipped, true),
                    'effortNote' => EffortMapper::map($configuration)['note'],
                ],
                $version->getConfigurations(),
            ),
            'skills' => array_values(array_map(
                static fn (SkillVersion $skill): array => ['title' => $skill->getTitle(), 'number' => $skill->getNumber(), 'deleted' => $skill->getSkill()->isDeleted()],
                $version->getSkillVersions()->toArray(),
            )),
            'testcases' => array_values(array_map(
                static fn (TestcaseVersion $testcase): array => ['title' => $testcase->getTitle(), 'number' => $testcase->getNumber(), 'deleted' => $testcase->getTestcase()->isDeleted()],
                $version->getTestcaseVersions()->toArray(),
            )),
            'matrix' => $this->matrix($version, $skipped, $includeOutputs),
        ];
    }

    /** @return array<string, mixed> */
    #[McpTool(
        name: 'compare_run_versions',
        description: 'Compares two versions of a run: changed title and description, added and removed configurations, skills and testcases, skills and testcases with another version number, and the score per testcase and configuration plus the average per configuration. Without from and to the latest version is compared with the one before it.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    public function compare(string $runUuid, ?int $from = null, ?int $to = null): array
    {
        $run = $this->loadViewable($runUuid);
        $query = new RunCompareQuery($from, $to);
        $this->support->validate($query);
        [$fromNumber, $toNumber] = $query->resolve($run->getLatestVersion()?->getNumber());
        if (null === $fromNumber || null === $toNumber) {
            throw new ToolCallException('Comparing needs two versions; this run has fewer.');
        }

        $diff = $this->differ->diff(
            $run->findVersion($fromNumber) ?? throw new ToolCallException('Version not found.'),
            $run->findVersion($toNumber) ?? throw new ToolCallException('Version not found.'),
        );
        $averages = [];
        foreach ($diff->averages as $configuration => $average) {
            $averages[] = ['configuration' => $configuration] + $average;
        }

        return [
            'from' => $fromNumber,
            'to' => $toNumber,
            'unchanged' => $diff->isEmpty(),
            'fields' => $diff->fields,
            'configurationsAdded' => array_map(static fn (LlmConfiguration $configuration): string => $configuration->label(), $diff->configurationsAdded),
            'configurationsRemoved' => array_map(static fn (LlmConfiguration $configuration): string => $configuration->label(), $diff->configurationsRemoved),
            'skillsAdded' => array_map($this->named(...), $diff->skillsAdded),
            'skillsRemoved' => array_map($this->named(...), $diff->skillsRemoved),
            'skillsChanged' => array_map($this->changed(...), $diff->skillsChanged),
            'testcasesAdded' => array_map($this->named(...), $diff->testcasesAdded),
            'testcasesRemoved' => array_map($this->named(...), $diff->testcasesRemoved),
            'testcasesChanged' => array_map($this->changed(...), $diff->testcasesChanged),
            'scores' => $diff->scores,
            'averages' => $averages,
        ];
    }

    /**
     * What the run list and get_run show per version: status and progress, without the matrix.
     *
     * @return array<string, mixed>
     */
    public function summary(RunVersion $version): array
    {
        $results = $version->getResults()->toArray();

        return [
            'number' => $version->getNumber(),
            'status' => $version->getStatus()->value,
            'statusLabel' => $version->getStatusLabel(),
            'done' => \count(array_filter($results, static fn (RunResult $result): bool => !$result->getStatus()->isOpen())),
            'total' => \count($results),
            'criteriaScore' => $version->getCriteriaScore(),
            'averageDurationMs' => $version->getAverageDurationMs(),
            'averageInputTokens' => $version->getAverageInputTokens(),
            'averageOutputTokens' => $version->getAverageOutputTokens(),
            'createdAt' => $this->support->iso($version->getCreatedAt()),
        ];
    }

    private function loadViewable(string $uuid): Run
    {
        $run = $this->support->find($this->runs, $uuid, 'Run');
        $this->support->grant(RunVoter::VIEW, $run);

        return $run;
    }

    /**
     * Rows sorted by title, then id, one cell per configuration, like the matrix of the version page.
     *
     * @param list<string> $skipped
     *
     * @return list<array<string, mixed>>
     */
    private function matrix(RunVersion $version, array $skipped, bool $withOutputs): array
    {
        $results = [];
        foreach ($version->getResults() as $result) {
            $results[$result->getTestcaseVersion()->getId().'|'.$result->getConfigurationKey()] = $result;
        }
        $rows = $version->getTestcaseVersions()->toArray();
        usort($rows, static fn (TestcaseVersion $a, TestcaseVersion $b): int => strnatcasecmp($a->getTitle(), $b->getTitle()) ?: $a->getId() <=> $b->getId());

        $matrix = [];
        foreach ($rows as $row) {
            $cells = [];
            foreach ($version->getConfigurations() as $configuration) {
                $key = $configuration->key();
                $cells[] = $this->cell($key, \in_array($key, $skipped, true), $results[$row->getId().'|'.$key] ?? null, $withOutputs);
            }
            $matrix[] = ['testcase' => $row->getTitle(), 'testcaseVersion' => $row->getNumber(), 'cells' => $cells];
        }

        return $matrix;
    }

    /** @return array<string, mixed> */
    private function cell(string $configuration, bool $skipped, ?RunResult $result, bool $withOutputs): array
    {
        if ($skipped) {
            return ['configuration' => $configuration, 'status' => 'skipped'];
        }
        if (null === $result) {
            return ['configuration' => $configuration, 'status' => null];
        }

        $criteria = $result->getCriteria() ?? [];
        $cell = [
            'configuration' => $configuration,
            'status' => $result->getStatus()->value,
            'score' => $result->getScore(),
            'criteriaMet' => \count(array_filter($criteria, static fn (array $criterion): bool => $criterion['met'])),
            'criteriaTotal' => \count($criteria),
            'error' => $result->getError(),
            'durationMs' => $result->getDurationMs(),
            'inputTokens' => $result->getInputTokens(),
            'outputTokens' => $result->getOutputTokens(),
        ];

        return $withOutputs ? $cell + ['output' => $result->getOutput(), 'criteria' => $criteria] : $cell;
    }

    /** @return array{title: string, number: int} */
    private function named(SkillVersion|TestcaseVersion $version): array
    {
        return ['title' => $version->getTitle(), 'number' => $version->getNumber()];
    }

    /**
     * @param array{from: SkillVersion|TestcaseVersion, to: SkillVersion|TestcaseVersion} $pair
     *
     * @return array{title: string, fromNumber: int, toNumber: int}
     */
    private function changed(array $pair): array
    {
        return ['title' => $pair['to']->getTitle(), 'fromNumber' => $pair['from']->getNumber(), 'toNumber' => $pair['to']->getNumber()];
    }
}
