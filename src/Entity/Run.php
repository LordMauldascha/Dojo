<?php

declare(strict_types=1);

namespace App\Entity;

use App\Ai\LlmConfiguration;
use App\Entity\Trait\HasTimestamps;
use App\Entity\Trait\HasUuid;
use App\Repository\RunRepository;
use App\Validator\NoNulByte;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: RunRepository::class)]
#[ORM\Table(name: 'run')]
class Run
{
    use HasTimestamps;
    use HasUuid;

    public const TITLE_MAX = 255;
    public const DESCRIPTION_MAX = 2000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: self::TITLE_MAX)]
    #[Assert\NotBlank]
    #[Assert\Length(max: self::TITLE_MAX)]
    #[NoNulByte]
    private string $title = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: self::DESCRIPTION_MAX)]
    #[NoNulByte]
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
        $models = $configuration->provider->models();
        // an empty list means the models are dynamic (Hugging Face); ModelCatalog validates those
        if ([] !== $models && !\in_array($configuration->model, $models, true)) {
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

    public function removeConfiguration(string $key): bool
    {
        foreach ($this->getConfigurations() as $index => $configuration) {
            if ($configuration->key() === $key) {
                array_splice($this->configurations, $index, 1);
                $this->touch();

                return true;
            }
        }

        return false;
    }

    /** @return Collection<int, Skill> */
    public function getSkills(): Collection
    {
        return $this->skills;
    }

    /** @param list<Skill> $skills */
    public function setSkills(array $skills): void
    {
        if ($this->sync($this->skills, $skills)) {
            $this->touch();
        }
    }

    /** @return Collection<int, Testcase> */
    public function getTestcases(): Collection
    {
        return $this->testcases;
    }

    /** @param list<Testcase> $testcases */
    public function setTestcases(array $testcases): void
    {
        if ($this->sync($this->testcases, $testcases)) {
            $this->touch();
        }
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

    /**
     * Snapshots the run with the latest final version of every assigned skill and testcase (drafts are ignored).
     * Items that were deleted or do not belong to the run's project are left out, even if a prune is still pending.
     */
    public function start(): RunVersion
    {
        $skills = array_filter($this->skills->toArray(), fn (Skill $skill): bool => !$skill->isDeleted() && $skill->getProjects()->contains($this->project));
        $testcases = array_filter($this->testcases->toArray(), fn (Testcase $testcase): bool => !$testcase->isDeleted() && $testcase->getProjects()->contains($this->project));

        if ([] === $testcases) {
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
            array_values(array_map(static fn (Skill $skill): SkillVersion => $skill->getActiveVersion(), $skills)),
            array_values(array_map(static fn (Testcase $testcase): TestcaseVersion => $testcase->getActiveVersion(), $testcases)),
        );
        $this->versions->add($version);

        return $version;
    }

    /**
     * @template T of object
     *
     * @param Collection<int, T> $collection
     * @param list<T>            $items
     *
     * @return bool whether the collection changed
     */
    private function sync(Collection $collection, array $items): bool
    {
        $changed = false;
        foreach ($collection->toArray() as $existing) {
            if (!\in_array($existing, $items, true)) {
                $collection->removeElement($existing);
                $changed = true;
            }
        }
        foreach ($items as $item) {
            if (!$collection->contains($item)) {
                $collection->add($item);
                $changed = true;
            }
        }

        return $changed;
    }
}
