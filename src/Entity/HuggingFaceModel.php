<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\HuggingFaceModelRepository;
use Doctrine\ORM\Mapping as ORM;

/** A Hugging Face model an admin added; only these are offered in runs and as judge. */
#[ORM\Entity(repositoryClass: HuggingFaceModelRepository::class)]
#[ORM\Table(name: 'huggingface_model')]
class HuggingFaceModel
{
    /** A path segment that is not "." or "..". */
    private const SEGMENT = '(?!\.{1,2}(?:/|\z))[A-Za-z0-9._-]+';

    /** "organization/name" as on the Hub; the few legacy models (gpt2) have no organization. Anchored for use inside delimiters. */
    public const ID_PATTERN = '\A'.self::SEGMENT.'(?:/'.self::SEGMENT.')?\z';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\Column(length: 255, unique: true)]
        private string $modelId,
    ) {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getModelId(): string
    {
        return $this->modelId;
    }
}
