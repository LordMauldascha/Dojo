<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'skill_version')]
#[ORM\UniqueConstraint(name: 'uniq_skill_version_number', columns: ['skill_id', 'number'])]
#[ORM\UniqueConstraint(name: 'uniq_skill_one_draft', columns: ['skill_id'], options: ['where' => '(draft = true)'])]
class SkillVersion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $savedAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Skill::class, inversedBy: 'versions')]
        #[ORM\JoinColumn(name: 'skill_id', nullable: false, onDelete: 'CASCADE')]
        private Skill $skill,
        #[ORM\Column]
        private int $number,
        #[ORM\Column]
        private bool $draft,
        #[ORM\Column(length: 255)]
        private string $title,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $description,
        #[ORM\Column(type: Types::TEXT)]
        private string $content,
    ) {
        $this->savedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSkill(): Skill
    {
        return $this->skill;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    public function isDraft(): bool
    {
        return $this->draft;
    }

    /** "3" for a final version, "3-draft" for the draft. */
    public function getLabel(): string
    {
        return $this->number.($this->draft ? '-draft' : '');
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getSavedAt(): \DateTimeImmutable
    {
        return $this->savedAt;
    }

    public function setFields(string $title, ?string $description, string $content): void
    {
        $this->title = $title;
        $this->description = $description;
        $this->content = $content;
        $this->savedAt = new \DateTimeImmutable();
    }

    public function publish(): void
    {
        $this->draft = false;
    }
}
