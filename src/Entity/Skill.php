<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SkillRepository;
use App\Versioning\VersionedInterface;
use App\Versioning\VersionInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: SkillRepository::class)]
#[ORM\Table(name: 'skill')]
class Skill implements VersionedInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $uuid;

    /** @var Collection<int, SkillVersion> */
    #[ORM\OneToMany(targetEntity: SkillVersion::class, mappedBy: 'skill', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['number' => 'ASC'])]
    private Collection $versions;

    /** Latest final version; only null while the skill is being constructed. */
    #[ORM\ManyToOne(targetEntity: SkillVersion::class)]
    #[ORM\JoinColumn(name: 'active_version_id', nullable: true, onDelete: 'SET NULL')]
    private ?SkillVersion $activeVersion = null;

    /** @var Collection<int, Project> */
    #[ORM\ManyToMany(targetEntity: Project::class)]
    #[ORM\JoinTable(name: 'skill_project')]
    #[ORM\JoinColumn(name: 'skill_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'project_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $projects;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    /** Creates version 1 as the final, active version. */
    public function __construct(
        string $title,
        ?string $description,
        string $content,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'created_by_id', nullable: true, onDelete: 'SET NULL')]
        private ?User $createdBy = null,
    ) {
        $this->uuid = Uuid::v7();
        $this->versions = new ArrayCollection();
        $this->projects = new ArrayCollection();
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
        $this->addVersion(false, $title, $description, $content);
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

    /** @return Collection<int, SkillVersion> */
    public function getVersions(): Collection
    {
        return $this->versions;
    }

    public function getActiveVersion(): SkillVersion
    {
        return $this->activeVersion ?? throw new \LogicException('Skill has no final version.');
    }

    public function getDraft(): ?SkillVersion
    {
        foreach ($this->versions as $version) {
            if ($version->isDraft()) {
                return $version;
            }
        }

        return null;
    }

    /** The version a user continues to edit: the draft if there is one, else the active version. */
    public function getEditableVersion(): SkillVersion
    {
        return $this->getDraft() ?? $this->getActiveVersion();
    }

    public function findVersion(int $number): ?SkillVersion
    {
        foreach ($this->versions as $version) {
            if ($number === $version->getNumber()) {
                return $version;
            }
        }

        return null;
    }

    /** Creates the draft, or overwrites the existing one in place. */
    public function saveDraft(string $title, ?string $description, string $content): SkillVersion
    {
        $draft = $this->getDraft();
        if (null === $draft) {
            $draft = $this->addVersion(true, $title, $description, $content);
        } else {
            $draft->setFields($title, $description, $content);
        }
        $this->touch();

        return $draft;
    }

    /** The draft becomes the final version with its number; without a draft a new final version is created. */
    public function saveFinal(string $title, ?string $description, string $content): SkillVersion
    {
        $draft = $this->getDraft();
        if (null === $draft) {
            $version = $this->addVersion(false, $title, $description, $content);
        } else {
            $draft->setFields($title, $description, $content);
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
    public function restore(VersionInterface $version): SkillVersion
    {
        if (!$version instanceof SkillVersion || $version->isDraft() || !$this->versions->contains($version)) {
            throw new \InvalidArgumentException('Only a final version of this skill can be restored.');
        }
        if (null !== $this->getDraft()) {
            throw new \LogicException('A draft exists: save it as final or discard it first.');
        }
        $restored = $this->addVersion(false, $version->getTitle(), $version->getDescription(), $version->getContent());
        $this->touch();

        return $restored;
    }

    /**
     * Applies a project selection made within $editable (the projects the user may change); other assignments stay.
     * Returns false and changes nothing if that would leave the skill without any project and that is not allowed.
     *
     * @param list<Project> $editable
     * @param list<Project> $chosen
     */
    public function replaceProjects(array $editable, array $chosen, bool $mayBeEmpty): bool
    {
        $untouched = array_filter($this->projects->toArray(), static fn (Project $project): bool => !\in_array($project, $editable, true));
        if (!$mayBeEmpty && [] === $untouched && [] === $chosen) {
            return false;
        }

        foreach ($editable as $project) {
            \in_array($project, $chosen, true) ? $this->addProject($project) : $this->removeProject($project);
        }

        return true;
    }

    /** @return Collection<int, Project> */
    public function getProjects(): Collection
    {
        return $this->projects;
    }

    public function addProject(Project $project): void
    {
        if (!$this->projects->contains($project)) {
            $this->projects->add($project);
            $project->touch();
            $this->touch();
        }
    }

    public function removeProject(Project $project): void
    {
        if ($this->projects->removeElement($project)) {
            $project->touch();
            $this->touch();
        }
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

    private function addVersion(bool $draft, string $title, ?string $description, string $content): SkillVersion
    {
        $number = 1 + array_reduce($this->versions->toArray(), static fn (int $max, SkillVersion $v): int => max($max, $v->getNumber()), 0);
        $version = new SkillVersion($this, $number, $draft, $title, $description, $content);
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
