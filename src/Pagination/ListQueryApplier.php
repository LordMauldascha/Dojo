<?php

declare(strict_types=1);

namespace App\Pagination;

use Doctrine\ORM\QueryBuilder;

/** The search and sort rules shared by all overview queries. */
final class ListQueryApplier
{
    /**
     * Case-insensitive "contains" search over the given DQL fields; % and _ are matched literally.
     *
     * @param list<string> $fields
     */
    public static function search(QueryBuilder $queryBuilder, ListQuery $query, array $fields): void
    {
        if ('' === $query->q) {
            return;
        }

        $conditions = array_map(static fn (string $field): string => \sprintf('LOWER(%s) LIKE :q', $field), $fields);
        $escaped = addcslashes(mb_strtolower($query->q), '%_\\');
        $queryBuilder
            ->andWhere('('.implode(' OR ', $conditions).')')
            ->setParameter('q', '%'.$escaped.'%');
    }

    /**
     * Sorts by a whitelisted column (unknown values fall back to the title) with the id as tie breaker.
     *
     * @param array<string, string> $sortFields request value => DQL expression, must contain "title"
     */
    public static function sort(QueryBuilder $queryBuilder, ListQuery $query, array $sortFields, string $idField): void
    {
        $queryBuilder
            ->orderBy($sortFields[$query->sort ?? ''] ?? $sortFields['title'], 'desc' === $query->dir ? 'DESC' : 'ASC')
            ->addOrderBy($idField, 'ASC');
    }
}
