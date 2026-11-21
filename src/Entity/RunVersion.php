<?php

declare(strict_types=1);

namespace App\Entity;

use App\Ai\LlmConfiguration;
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

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @param list<array{provider: string, model: string, effort: string|null}> $configurations
     * @param list<SkillVersion>                                                $skillVersions
     * @param list<TestcaseVersion>                                             $testcaseVersions
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
    ) {
        $this->skillVersions = new ArrayCollection($skillVersions);
        $this->testcaseVersions = new ArrayCollection($testcaseVersions);
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
