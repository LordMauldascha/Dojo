<?php

declare(strict_types=1);

namespace App\Pagination;

use App\Validator\NoNulByte;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ListQuery
{
    public function __construct(
        #[Assert\Positive]
        public int $page = 1,
        #[Assert\Choice(choices: PageSizeResolver::SIZES)]
        public ?int $perPage = null,
        #[Assert\Length(max: 32)]
        public ?string $sort = null,
        #[Assert\Choice(choices: ['asc', 'desc'])]
        public string $dir = 'asc',
        #[Assert\Length(max: 100)]
        #[NoNulByte]
        public string $q = '',
        #[Assert\Uuid]
        public ?string $project = null,
    ) {
    }
}
