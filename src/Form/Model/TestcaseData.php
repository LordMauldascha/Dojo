<?php

declare(strict_types=1);

namespace App\Form\Model;

use App\Entity\TestcaseVersion;
use App\Validator\NoNulByte;
use Symfony\Component\Validator\Constraints as Assert;

final class TestcaseData
{
    use NormalizesText;

    #[Assert\NotBlank]
    #[Assert\Length(max: TestcaseVersion::TITLE_MAX)]
    #[NoNulByte]
    public ?string $title = null;

    #[Assert\Length(max: TestcaseVersion::DESCRIPTION_MAX)]
    #[NoNulByte]
    public ?string $description = null;

    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 100000)]
    #[NoNulByte]
    public ?string $prompt = null;

    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 100000)]
    #[NoNulByte]
    public ?string $outputCriteria = null;

    public static function fromVersion(TestcaseVersion $version): self
    {
        $data = new self();
        $data->title = $version->getTitle();
        $data->description = $version->getDescription();
        $data->prompt = $version->getPrompt();
        $data->outputCriteria = $version->getOutputCriteria();

        return $data;
    }

    public function normalizedPrompt(): string
    {
        return $this->prompt ?? '';
    }

    public function normalizedOutputCriteria(): string
    {
        return $this->outputCriteria ?? '';
    }
}
