<?php

declare(strict_types=1);

namespace App\Pagination;

use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;

final readonly class Page
{
    public int $pageCount;

    /** @param list<mixed> $items */
    private function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
    ) {
        $this->pageCount = max(1, (int) ceil($total / $perPage));
    }

    public static function fromQueryBuilder(QueryBuilder $queryBuilder, int $page, int $perPage): self
    {
        $total = (new Paginator($queryBuilder->getQuery(), false))->count();
        $page = min(max(1, $page), max(1, (int) ceil($total / $perPage)));

        $queryBuilder->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage);
        $items = iterator_to_array(new Paginator($queryBuilder->getQuery(), false), false);

        return new self($items, $total, $page, $perPage);
    }
}
