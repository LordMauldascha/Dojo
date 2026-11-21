<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Pagination\ListQuery;
use App\Pagination\ListQueryApplier;
use App\Pagination\Page;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<User> */
final class UserRepository extends ServiceEntityRepository
{
    private const SORT_FIELDS = [
        'title' => 'u.username',
        'role' => 'u.role',
        'status' => 'u.enabled',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function paginate(ListQuery $query, int $perPage): Page
    {
        $queryBuilder = $this->createQueryBuilder('u');

        ListQueryApplier::search($queryBuilder, $query, ['u.username']);

        ListQueryApplier::sort($queryBuilder, $query, self::SORT_FIELDS, 'u.id');

        return Page::fromQueryBuilder($queryBuilder, $query->page, $perPage);
    }
}
