<?php

declare(strict_types=1);

namespace App\Run\Judge;

use Symfony\Component\Validator\Constraints as Assert;

/** One entry of the judge's answer: whether the criterion with this 1-based index is met. */
final readonly class CriterionVerdict
{
    public function __construct(
        #[Assert\Positive]
        public int $index,
        public bool $met,
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 2000)]
        public string $reason,
    ) {
    }
}
