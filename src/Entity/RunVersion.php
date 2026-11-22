<?php

declare(strict_types=1);

namespace App\Entity;

use App\Ai\LlmConfiguration;
use App\Ai\Provider;
use App\Run\RunResultStatus;
use App\Run\RunVersionStatus;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Immutable snapshot of a run, created when the run is started. */
#[ORM\Entity]
#[ORM\Table(name: 'run_version')]
#[ORM\UniqueConstraint(name: 'uniq_run_version_number', columns: ['run_id', 'number'])]
class RunVersion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** @var Collection<int, SkillVersion> */
    #[ORM\ManyToMany(targetEntity: SkillVersion::class)]
    #[ORM\JoinTable(name: 'run_version_skill')]
    #[ORM\JoinColumn(name: 'run_version_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'skill_version_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $skillVersions;

    /** @var Collection<int, TestcaseVersion> */
    #[ORM\ManyToMany(targetEntity: TestcaseVersion::class)]
    #[ORM\JoinTable(name: 'run_version_testcase')]
    #[ORM\JoinColumn(name: 'run_version_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'testcase_version_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $testcaseVersions;

    /** @var Collection<int, RunResult> */
    #[ORM\OneToMany(targetEntity: RunResult::class, mappedBy: 'runVersion', cascade: ['persist'])]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $results;

    /** Null only for versions created before runs were executed. */
    #[ORM\Column(length: 32, nullable: true, enumType: Provider::class)]
    private ?Provider $judgeProvider;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $judgeModel;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @param list<array{provider: string, model: string, effort: string|null, skipped?: string|null}> $configurations
     * @param list<SkillVersion>                                                                       $skillVersions
     * @param list<TestcaseVersion>                                                                    $testcaseVersions
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: Run::class, inversedBy: 'versions')]
        #[ORM\JoinColumn(name: 'run_id', nullable: false, onDelete: 'CASCADE')]
        private Run $run,
        #[ORM\Column]
        private int $number,
        #[ORM\Column(length: Run::TITLE_MAX)]
        private string $title,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $description,
        #[ORM\Column(type: Types::JSON)]
        private array $configurations,
        array $skillVersions,
        array $testcaseVersions,
        ?LlmConfiguration $judge,
    ) {
        $this->skillVersions = new ArrayCollection($skillVersions);
        $this->testcaseVersions = new ArrayCollection($testcaseVersions);
        $this->results = new ArrayCollection();
        $this->judgeProvider = $judge?->provider;
        $this->judgeModel = $judge?->model;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRun(): Run
    {
        return $this->run;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    /** @return list<LlmConfiguration> */
    public function getConfigurations(): array
    {
        return array_map(LlmConfiguration::fromArray(...), $this->configurations);
    }

    /** @return list<LlmConfiguration> the configurations that had no API key at start and got no results */
    public function getSkippedConfigurations(): array
    {
        return array_values(array_map(
            LlmConfiguration::fromArray(...),
            array_filter($this->configurations, static fn (array $entry): bool => null !== ($entry['skipped'] ?? null)),
        ));
    }

    /** The judge that rated this version; null for versions from before runs were executed. */
    public function getJudge(): ?LlmConfiguration
    {
        return null === $this->judgeProvider || null === $this->judgeModel ? null : new LlmConfiguration($this->judgeProvider, $this->judgeModel);
    }

    /** @return Collection<int, RunResult> */
    public function getResults(): Collection
    {
        return $this->results;
    }

    public function getStatus(): RunVersionStatus
    {
        if ($this->results->isEmpty()) {
            return RunVersionStatus::NotExecuted;
        }
        $statuses = $this->results->map(static fn (RunResult $result): RunResultStatus => $result->getStatus())->toArray();
        if ([] !== array_filter($statuses, static fn (RunResultStatus $status): bool => $status->isOpen())) {
            return RunVersionStatus::Running;
        }

        return \in_array(RunResultStatus::Failed, $statuses, true) ? RunVersionStatus::CompletedWithErrors : RunVersionStatus::Completed;
    }

    /** Share of met criteria over all judged cells in percent (not the mean of the cell scores); null until one is judged. */
    public function getCriteriaScore(): ?int
    {
        $criteria = array_merge(...array_map(static fn (RunResult $result): array => $result->getCriteria() ?? [], array_values($this->results->toArray())));
        if ([] === $criteria) {
            return null;
        }

        return (int) round(100 * \count(array_filter($criteria, static fn (array $criterion): bool => $criterion['met'])) / \count($criteria));
    }

    /** Display status: a running version is "judging" once no cell waits for or calls the tested model any more. */
    public function getStatusLabel(): string
    {
        return match ($this->getStatus()) {
            RunVersionStatus::NotExecuted => 'not executed',
            RunVersionStatus::Completed => 'finished',
            RunVersionStatus::CompletedWithErrors => 'finished with errors',
            RunVersionStatus::Running => $this->results->exists(static fn (int $key, RunResult $result): bool => \in_array($result->getStatus(), [RunResultStatus::Pending, RunResultStatus::Running], true)) ? 'running' : 'judging',
        };
    }

    /** @return Collection<int, SkillVersion> */
    public function getSkillVersions(): Collection
    {
        return $this->skillVersions;
    }

    /** @return Collection<int, TestcaseVersion> */
    public function getTestcaseVersions(): Collection
    {
        return $this->testcaseVersions;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
