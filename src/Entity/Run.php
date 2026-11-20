<?php

declare(strict_types=1);

namespace App\Entity;

use App\Ai\LlmConfiguration;
use App\Repository\RunRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: RunRepository::class)]
#[ORM\Table(name: 'run')]
class Run
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $uuid;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $title = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 2000)]
    private ?string $description = null;

    /** @var list<array{provider: string, model: string, effort: string|null}> */
    #[ORM\Column(type: Types::JSON)]
    private array $configurations = [];

    /** @var Collection<int, Skill> */
    #[ORM\ManyToMany(targetEntity: Skill::class)]
    #[ORM\JoinTable(name: 'run_skill')]
    #[ORM\JoinColumn(name: 'run_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'skill_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $skills;

    /** @var Collection<int, Testcase> */
    #[ORM\ManyToMany(targetEntity: Testcase::class)]
    #[ORM\JoinTable(name: 'run_testcase')]
    #[ORM\JoinColumn(name: 'run_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'testcase_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $testcases;

    /** @var Collection<int, RunVersion> */
    #[ORM\OneToMany(targetEntity: RunVersion::class, mappedBy: 'run', cascade: ['persist'])]
    #[ORM\OrderBy(['number' => 'ASC'])]
    private Collection $versions;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Project::class)]
        #[ORM\JoinColumn(name: 'project_id', nullable: false, onDelete: 'CASCADE')]
        private Project $project,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'created_by_id', nullable: true, onDelete: 'SET NULL')]
        private ?User $createdBy = null,
    ) {
        $this->uuid = Uuid::v7();
        $this->skills = new ArrayCollection();
        $this->testcases = new ArrayCollection();
        $this->versions = new ArrayCollection();
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function getProject(): Project
    {
        return $this->project;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(?string $title): void
    {
        $title = trim($title ?? '');
        if ($title !== $this->title) {
            $this->title = $title;
            $this->touch();
        }
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $description = trim($description ?? '');
        $description = '' === $description ? null : $description;
        if ($description !== $this->description) {
            $this->description = $description;
            $this->touch();
        }
    }

    /** @return list<LlmConfiguration> */
    public function getConfigurations(): array
    {
        return array_map(LlmConfiguration::fromArray(...), $this->configurations);
    }

    /** Returns false if the same configuration already exists. */
    public function addConfiguration(LlmConfiguration $configuration): bool
    {
        if (!\in_array($configuration->model, $configuration->provider->models(), true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown model "%s" for provider "%s".', $configuration->model, $configuration->provider->value));
        }
        foreach ($this->getConfigurations() as $existing) {
            if ($existing->key() === $configuration->key()) {
                return false;
            }
        }
        $this->configurations[] = $configuration->toArray();
        $this->touch();

        return true;
    }

    public function removeConfigurationAt(int $index): bool
    {
        if (!isset($this->configurations[$index])) {
            return false;
        }
        array_splice($this->configurations, $index, 1);
        $this->touch();

        return true;
    }

    /** @return Collection<int, Skill> */
    public function getSkills(): Collection
    {
        return $this->skills;
    }

    /** @param list<Skill> $skills */
    public function setSkills(array $skills): void
    {
        foreach ($this->skills->toArray() as $skill) {
            if (!\in_array($skill, $skills, true)) {
                $this->skills->removeElement($skill);
            }
        }
        foreach ($skills as $skill) {
            if (!$this->skills->contains($skill)) {
                $this->skills->add($skill);
            }
        }
        $this->touch();
    }

    /** @return Collection<int, Testcase> */
    public function getTestcases(): Collection
    {
        return $this->testcases;
    }

    /** @param list<Testcase> $testcases */
    public function setTestcases(array $testcases): void
    {
        foreach ($this->testcases->toArray() as $testcase) {
            if (!\in_array($testcase, $testcases, true)) {
                $this->testcases->removeElement($testcase);
            }
        }
        foreach ($testcases as $testcase) {
            if (!$this->testcases->contains($testcase)) {
                $this->testcases->add($testcase);
            }
        }
        $this->touch();
    }

    /** @return Collection<int, RunVersion> */
    public function getVersions(): Collection
    {
        return $this->versions;
    }

    public function findVersion(int $number): ?RunVersion
    {
        foreach ($this->versions as $version) {
            if ($number === $version->getNumber()) {
                return $version;
            }
        }

        return null;
    }

    public function getLatestVersion(): ?RunVersion
    {
        $latest = $this->versions->last();

        return false === $latest ? null : $latest;
    }

    /** Snapshots the run with the latest final version of every assigned skill and testcase (drafts are ignored). */
    public function start(): RunVersion
    {
        if ($this->testcases->isEmpty()) {
            throw new \LogicException('A run needs at least one testcase to start.');
        }
        if ([] === $this->configurations) {
            throw new \LogicException('A run needs at least one LLM configuration to start.');
        }

        $version = new RunVersion(
            $this,
            1 + ($this->getLatestVersion()?->getNumber() ?? 0),
            $this->title,
            $this->description,
            $this->configurations,
            array_values($this->skills->map(static fn (Skill $skill): SkillVersion => $skill->getActiveVersion())->toArray()),
            array_values($this->testcases->map(static fn (Testcase $testcase): TestcaseVersion => $testcase->getActiveVersion())->toArray()),
        );
        $this->versions->add($version);

        return $version;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
