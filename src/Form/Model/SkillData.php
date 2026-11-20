<?php

declare(strict_types=1);

namespace App\Form\Model;

use App\Entity\SkillVersion;
use App\Validator\NoNulByte;
use Symfony\Component\Validator\Constraints as Assert;

final class SkillData
{
    use NormalizesText;

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    #[NoNulByte]
    public ?string $title = null;

    #[Assert\Length(max: 2000)]
    #[NoNulByte]
    public ?string $description = null;

    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 100000)]
    #[NoNulByte]
    public ?string $content = null;

    public static function fromVersion(SkillVersion $version): self
    {
        $data = new self();
        $data->title = $version->getTitle();
        $data->description = $version->getDescription();
        $data->content = $version->getContent();

        return $data;
    }

    public function normalizedContent(): string
    {
        return $this->content ?? '';
    }
}
