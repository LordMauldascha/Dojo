<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TestcaseRepository;
use App\Versioning\VersionedInterface;
use App\Versioning\VersionInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TestcaseRepository::class)]
#[ORM\Table(name: 'testcase')]
class Testcase implements VersionedInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $uuid;

    /** @var Collection<int, TestcaseVersion> */
    #[ORM\OneToMany(targetEntity: TestcaseVersion::class, mappedBy: 'testcase', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['number' => 'ASC'])]
    private Collection $versions;

    /** Latest final version; only null while the testcase is being constructed. */
    #[ORM\ManyToOne(targetEntity: TestcaseVersion::class)]
    #[ORM\JoinColumn(name: 'active_version_id', nullable: true, onDelete: 'SET NULL')]
    private ?TestcaseVersion $activeVersion = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    /** Creates version 1 as the final, active version. No project means admin-only. */
    public function __construct(
        string $title,
        ?string $description,
        string $prompt,
        string $expectedOutput,
        #[ORM\ManyToOne(targetEntity: Project::class)]
        #[ORM\JoinColumn(name: 'project_id', nullable: true, onDelete: 'SET NULL')]
        private ?Project $project = null,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'created_by_id', nullable: true, onDelete: 'SET NULL')]
        private ?User $createdBy = null,
    ) {
        $this->uuid = Uuid::v7();
        $this->versions = new ArrayCollection();
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
        $this->addVersion(false, $title, $description, $prompt, $expectedOutput);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function moveToProject(?Project $project): void
    {
        if ($project !== $this->project) {
            $this->project = $project;
            $this->touch();
        }
    }

    /** @return Collection<int, TestcaseVersion> */
    public function getVersions(): Collection
    {
        return $this->versions;
    }

    public function getActiveVersion(): TestcaseVersion
    {
        return $this->activeVersion ?? throw new \LogicException('Testcase has no final version.');
    }

    public function getDraft(): ?TestcaseVersion
    {
        foreach ($this->versions as $version) {
            if ($version->isDraft()) {
                return $version;
            }
        }

        return null;
    }

    /** The version a user continues to edit: the draft if there is one, else the active version. */
    public function getEditableVersion(): TestcaseVersion
    {
        return $this->getDraft() ?? $this->getActiveVersion();
    }

    public function findVersion(int $number): ?TestcaseVersion
    {
        foreach ($this->versions as $version) {
            if ($number === $version->getNumber()) {
                return $version;
            }
        }

        return null;
    }

    /** Creates the draft, or overwrites the existing one in place. */
    public function saveDraft(string $title, ?string $description, string $prompt, string $expectedOutput): TestcaseVersion
    {
        $draft = $this->getDraft();
        if (null === $draft) {
            $draft = $this->addVersion(true, $title, $description, $prompt, $expectedOutput);
        } else {
            $draft->setFields($title, $description, $prompt, $expectedOutput);
        }
        $this->touch();

        return $draft;
    }

    /** The draft becomes the final version with its number; without a draft a new final version is created. */
    public function saveFinal(string $title, ?string $description, string $prompt, string $expectedOutput): TestcaseVersion
    {
        $draft = $this->getDraft();
        if (null === $draft) {
            $version = $this->addVersion(false, $title, $description, $prompt, $expectedOutput);
        } else {
            $draft->setFields($title, $description, $prompt, $expectedOutput);
            $draft->publish();
            $version = $draft;
            $this->activeVersion = $version;
        }
        $this->touch();

        return $version;
    }

    public function discardDraft(): void
    {
        $draft = $this->getDraft();
        if (null !== $draft) {
            $this->versions->removeElement($draft);
            $this->touch();
        }
    }

    /** Copies an old final version as a new final version; the history is kept. */
    public function restore(VersionInterface $version): TestcaseVersion
    {
        if (!$version instanceof TestcaseVersion || $version->isDraft() || !$this->versions->contains($version)) {
            throw new \InvalidArgumentException('Only a final version of this testcase can be restored.');
        }
        if (null !== $this->getDraft()) {
            throw new \LogicException('A draft exists: save it as final or discard it first.');
        }
        $restored = $this->addVersion(false, $version->getTitle(), $version->getDescription(), $version->getPrompt(), $version->getExpectedOutput());
        $this->touch();

        return $restored;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isDeleted(): bool
    {
        return null !== $this->deletedAt;
    }

    public function softDelete(): void
    {
        $this->deletedAt = new \DateTimeImmutable();
    }

    private function addVersion(bool $draft, string $title, ?string $description, string $prompt, string $expectedOutput): TestcaseVersion
    {
        $number = 1 + array_reduce($this->versions->toArray(), static fn (int $max, TestcaseVersion $v): int => max($max, $v->getNumber()), 0);
        $version = new TestcaseVersion($this, $number, $draft, $title, $description, $prompt, $expectedOutput);
        $this->versions->add($version);
        if (!$draft) {
            $this->activeVersion = $version;
        }

        return $version;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
