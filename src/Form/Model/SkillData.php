<?php

declare(strict_types=1);

namespace App\Form\Model;

use App\Entity\SkillVersion;
use Symfony\Component\Validator\Constraints as Assert;

final class SkillData
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public ?string $title = null;

    #[Assert\Length(max: 2000)]
    public ?string $description = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 100000)]
    public ?string $content = null;

    public static function fromVersion(SkillVersion $version): self
    {
        $data = new self();
        $data->title = $version->getTitle();
        $data->description = $version->getDescription();
        $data->content = $version->getContent();

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

    public function normalizedContent(): string
    {
        return $this->content ?? '';
    }
}
