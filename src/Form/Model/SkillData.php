<?php

declare(strict_types=1);

namespace App\Form\Model;

use App\Entity\SkillVersion;
use App\Skill\SkillHeader;
use App\Validator\NoNulByte;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class SkillData
{
    use NormalizesText;

    #[Assert\NotBlank]
    #[Assert\Length(max: SkillVersion::TITLE_MAX)]
    #[NoNulByte]
    public ?string $title = null;

    #[Assert\Length(max: SkillVersion::DESCRIPTION_MAX)]
    #[NoNulByte]
    public ?string $description = null;

    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 100000)]
    #[NoNulByte]
    public ?string $content = null;

    #[Assert\Callback]
    public function validateTitleName(ExecutionContextInterface $context): void
    {
        $title = $this->normalizedTitle();
        if ('' === $title) {
            return;
        }

        $name = SkillHeader::name($title);
        if (null === $name) {
            $context->buildViolation('The title may only contain letters, digits, spaces and hyphens (ä, ö, ü, ß become ae, oe, ue, ss).')
                ->atPath('title')
                ->addViolation();
        } elseif (SkillHeader::MAX_NAME_LENGTH < \strlen($name)) {
            $context->buildViolation('The skill name made from the title may be at most {{ limit }} characters.')
                ->setParameter('{{ limit }}', (string) SkillHeader::MAX_NAME_LENGTH)
                ->atPath('title')
                ->addViolation();
        }
    }

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
