<?php

declare(strict_types=1);

namespace App\Form\Model;

use App\Entity\TestcaseVersion;
use Symfony\Component\Validator\Constraints as Assert;

final class TestcaseData
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public ?string $title = null;

    #[Assert\Length(max: 2000)]
    public ?string $description = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 100000)]
    public ?string $prompt = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 100000)]
    public ?string $expectedOutput = null;

    public static function fromVersion(TestcaseVersion $version): self
    {
        $data = new self();
        $data->title = $version->getTitle();
        $data->description = $version->getDescription();
        $data->prompt = $version->getPrompt();
        $data->expectedOutput = $version->getExpectedOutput();

        return $data;
    }

    public function normalizedTitle(): string
    {
        return trim($this->title ?? '');
    }

    public function normalizedDescription(): ?string
    {
        $description = trim($this->description ?? '');

        return '' === $description ? null : $description;
    }

    public function normalizedPrompt(): string
    {
        return $this->prompt ?? '';
    }

    public function normalizedExpectedOutput(): string
    {
        return $this->expectedOutput ?? '';
    }
}
