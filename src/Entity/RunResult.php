<?php

declare(strict_types=1);

namespace App\Entity;

use App\Ai\LlmConfiguration;
use App\Run\RunResultStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** One cell of a run version: the answer of one configuration to one testcase and its rating by the judge. */
#[ORM\Entity]
#[ORM\Table(name: 'run_result')]
#[ORM\UniqueConstraint(name: 'uniq_run_result_cell', columns: ['run_version_id', 'testcase_version_id', 'config_key'])]
class RunResult
{
    /** Minutes a result may stay in running or judging before it counts as stuck. */
    public const STALE_AFTER_MINUTES = 30;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** @var array{provider: string, model: string, effort: string|null} */
    #[ORM\Column(type: Types::JSON)]
    private array $configuration;

    #[ORM\Column(length: 255)]
    private string $configKey;

    #[ORM\Column(length: 16, enumType: RunResultStatus::class)]
    private RunResultStatus $status = RunResultStatus::Pending;

    #[ORM\Column]
    private \DateTimeImmutable $statusChangedAt;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $output = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $error = null;

    #[ORM\Column(nullable: true)]
    private ?int $inputTokens = null;

    #[ORM\Column(nullable: true)]
    private ?int $outputTokens = null;

    #[ORM\Column(nullable: true)]
    private ?int $durationMs = null;

    /** @var list<array{criterion: string, met: bool, reason: string}>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $criteria = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $score = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    /** Optimistic lock: a worker or sweeper whose copy is outdated cannot overwrite the result. */
    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    private int $version = 1;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: RunVersion::class, inversedBy: 'results')]
        #[ORM\JoinColumn(name: 'run_version_id', nullable: false, onDelete: 'CASCADE')]
        private RunVersion $runVersion,
        #[ORM\ManyToOne(targetEntity: TestcaseVersion::class)]
        #[ORM\JoinColumn(name: 'testcase_version_id', nullable: false, onDelete: 'CASCADE')]
        private TestcaseVersion $testcaseVersion,
        LlmConfiguration $configuration,
    ) {
        $this->configuration = $configuration->toArray();
        $this->configKey = $configuration->key();
        $this->statusChangedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRunVersion(): RunVersion
    {
        return $this->runVersion;
    }

    public function getTestcaseVersion(): TestcaseVersion
    {
        return $this->testcaseVersion;
    }

    public function getConfiguration(): LlmConfiguration
    {
        return LlmConfiguration::fromArray($this->configuration);
    }

    public function getConfigurationKey(): string
    {
        return $this->configKey;
    }

    public function getStatus(): RunResultStatus
    {
        return $this->status;
    }

    public function getStatusChangedAt(): \DateTimeImmutable
    {
        return $this->statusChangedAt;
    }

    public function getOutput(): ?string
    {
        return $this->output;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getInputTokens(): ?int
    {
        return $this->inputTokens;
    }

    public function getOutputTokens(): ?int
    {
        return $this->outputTokens;
    }

    public function getDurationMs(): ?int
    {
        return $this->durationMs;
    }

    /** @return list<array{criterion: string, met: bool, reason: string}>|null */
    public function getCriteria(): ?array
    {
        return $this->criteria;
    }

    public function getScore(): ?int
    {
        return $this->score;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function markRunning(): bool
    {
        if (!$this->transition(RunResultStatus::Pending, RunResultStatus::Running)) {
            return false;
        }
        $this->startedAt ??= $this->statusChangedAt;

        return true;
    }

    /** Puts a result back in the queue so a retry can pick it up. */
    public function markPending(): bool
    {
        return $this->transition(RunResultStatus::Running, RunResultStatus::Pending);
    }

    public function markJudging(string $output, ?int $inputTokens, ?int $outputTokens, int $durationMs): bool
    {
        if (!$this->transition(RunResultStatus::Running, RunResultStatus::Judging)) {
            return false;
        }
        $this->output = $output;
        $this->inputTokens = $inputTokens;
        $this->outputTokens = $outputTokens;
        $this->durationMs = $durationMs;

        return true;
    }

    /** @param list<array{criterion: string, met: bool, reason: string}> $criteria */
    public function markDone(array $criteria): bool
    {
        if (RunResultStatus::Judging !== $this->status) {
            return false;
        }
        if ([] === $criteria) {
            throw new \InvalidArgumentException('A rating needs at least one criterion.');
        }
        $this->transition(RunResultStatus::Judging, RunResultStatus::Done);
        $met = \count(array_filter($criteria, static fn (array $criterion): bool => $criterion['met']));
        $this->criteria = $criteria;
        $this->score = (int) round(100 * $met / \count($criteria));
        $this->finishedAt = $this->statusChangedAt;

        return true;
    }

    /** @param string $error a short code or exception class, never prompt or output content */
    public function markFailed(string $error, RunResultStatus $expected): bool
    {
        if (!$expected->isOpen() || !$this->transition($expected, RunResultStatus::Failed)) {
            return false;
        }
        $this->error = $error;
        $this->finishedAt = $this->statusChangedAt;

        return true;
    }

    public function isStale(\DateTimeImmutable $now): bool
    {
        return (RunResultStatus::Running === $this->status || RunResultStatus::Judging === $this->status)
            && $this->statusChangedAt->modify(\sprintf('+%d minutes', self::STALE_AFTER_MINUTES)) < $now;
    }

    private function transition(RunResultStatus $from, RunResultStatus $to): bool
    {
        if ($from !== $this->status) {
            return false;
        }
        $this->status = $to;
        $this->statusChangedAt = new \DateTimeImmutable();

        return true;
    }
}
