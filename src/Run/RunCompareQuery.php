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

    /**
     * Defaults: the latest version against the one before it.
     *
     * @return array{0: int|null, 1: int|null} from and to
     */
    public function resolve(?int $latest): array
    {
        $to = $this->to ?? $latest;
        $from = $this->from ?? (null === $to || 1 >= $to ? null : $to - 1);

        return [$from, $to];
    }
}
