<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Group;
use App\Entity\User;
use App\Pagination\ListQuery;
use App\Pagination\Page;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Group> */
final class GroupRepository extends ServiceEntityRepository
{
    private const SORT_FIELDS = [
        'title' => 'g.title',
        'users' => 'userCount',
        'updatedAt' => 'g.updatedAt',
        'createdAt' => 'g.createdAt',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Group::class);
    }

    /** Items are rows of the form [0 => Group, 'userCount' => int|string]. */
    public function paginate(ListQuery $query, int $perPage): Page
    {
        $queryBuilder = $this->createQueryBuilder('g')->addSelect('SIZE(g.users) AS userCount');

        if ('' !== $query->q) {
            $escaped = addcslashes(mb_strtolower($query->q), '%_\\');
            $queryBuilder->andWhere('LOWER(g.title) LIKE :q')->setParameter('q', '%'.$escaped.'%');
        }

        $sortField = self::SORT_FIELDS[$query->sort ?? ''] ?? self::SORT_FIELDS['title'];
        $direction = 'desc' === $query->dir ? 'DESC' : 'ASC';
        $queryBuilder->orderBy($sortField, $direction)->addOrderBy('g.id', 'ASC');

        return Page::fromQueryBuilder($queryBuilder, $query->page, $perPage);
    }

    /** @return list<User> */
    public function findAssignableUsers(Group $group): array
    {
        /** @var list<User> $users */
        $users = $this->getEntityManager()->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.id NOT IN (SELECT m.id FROM '.Group::class.' g JOIN g.users m WHERE g = :group)')
            ->setParameter('group', $group)
            ->orderBy('u.username', 'ASC')
            ->getQuery()
            ->getResult();

        return $users;
    }
}
