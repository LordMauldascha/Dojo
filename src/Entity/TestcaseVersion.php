<?php

declare(strict_types=1);

namespace App\Entity;

use App\Versioning\VersionInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'testcase_version')]
#[ORM\UniqueConstraint(name: 'uniq_testcase_version_number', columns: ['testcase_id', 'number'])]
#[ORM\UniqueConstraint(name: 'uniq_testcase_one_draft', columns: ['testcase_id'], options: ['where' => '(draft = true)'])]
class TestcaseVersion implements VersionInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $savedAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Testcase::class, inversedBy: 'versions')]
        #[ORM\JoinColumn(name: 'testcase_id', nullable: false, onDelete: 'CASCADE')]
        private Testcase $testcase,
        #[ORM\Column]
        private int $number,
        #[ORM\Column]
        private bool $draft,
        #[ORM\Column(length: 255)]
        private string $title,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $description,
        #[ORM\Column(type: Types::TEXT)]
        private string $prompt,
        #[ORM\Column(type: Types::TEXT)]
        private string $outputCriteria,
    ) {
        $this->savedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTestcase(): Testcase
    {
        return $this->testcase;
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

    public function getPrompt(): string
    {
        return $this->prompt;
    }

    public function getOutputCriteria(): string
    {
        return $this->outputCriteria;
    }

    public function getSavedAt(): \DateTimeImmutable
    {
        return $this->savedAt;
    }

    public function setFields(string $title, ?string $description, string $prompt, string $outputCriteria): void
    {
        $this->title = $title;
        $this->description = $description;
        $this->prompt = $prompt;
        $this->outputCriteria = $outputCriteria;
        $this->savedAt = new \DateTimeImmutable();
    }

    public function publish(): void
    {
        $this->draft = false;
    }
}
