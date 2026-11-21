<?php

declare(strict_types=1);

namespace App\Form\Model;

use Symfony\Component\Validator\Constraints as Assert;

/** Filters of the Hugging Face model discovery. Runs need text generation, so that is the default task. */
final readonly class HuggingFaceSearch
{
    public function __construct(
        #[Assert\Length(max: 100)]
        public string $search = '',
        #[Assert\Regex('~\A[a-z0-9-]*\z~')]
        #[Assert\Length(max: 100)]
        public string $task = 'text-generation',
        #[Assert\Regex('~\A[a-z0-9-]*\z~')]
        #[Assert\Length(max: 100)]
        public string $provider = '',
        public bool $warm = false,
    ) {
    }
}
