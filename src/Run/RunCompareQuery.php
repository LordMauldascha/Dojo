<?php

declare(strict_types=1);

namespace App\Run;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class RunCompareQuery
{
    public function __construct(
        #[Assert\Positive]
        public ?int $from = null,
        #[Assert\Positive]
        public ?int $to = null,
    ) {
    }
}
