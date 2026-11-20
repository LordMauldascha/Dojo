<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Group;
use App\Entity\Project;
use App\Entity\User;
use App\Pagination\ListQuery;
use App\Pagination\Page;
use App\Security\UserRole;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Project> */
final class ProjectRepository extends ServiceEntityRepository
{
    private const SORT_FIELDS = [
        'title' => 'p.title',
        'users' => 'userCount',
        'groups' => 'groupCount',
        'updatedAt' => 'p.updatedAt',
        'createdAt' => 'p.createdAt',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Project::class);
    }

    /** Items are rows of the form [0 => Project, 'userCount' => int|string, 'groupCount' => int|string]. */
    public function paginate(ListQuery $query, int $perPage, User $viewer): Page
    {
        $queryBuilder = $this->createQueryBuilder('p')
            ->addSelect('SIZE(p.users) AS userCount')
            ->addSelect('SIZE(p.groups) AS groupCount');
        $this->applyAccessFilter($queryBuilder, 'p', $viewer);

        if ('' !== $query->q) {
            $escaped = addcslashes(mb_strtolower($query->q), '%_\\');
            $queryBuilder
                ->andWhere('(LOWER(p.title) LIKE :q OR LOWER(p.description) LIKE :q)')
                ->setParameter('q', '%'.$escaped.'%');
        }

        $sortField = self::SORT_FIELDS[$query->sort ?? ''] ?? self::SORT_FIELDS['title'];
        $direction = 'desc' === $query->dir ? 'DESC' : 'ASC';
        $queryBuilder->orderBy($sortField, $direction)->addOrderBy('p.id', 'ASC');

        return Page::fromQueryBuilder($queryBuilder, $query->page, $perPage);
    }

    /** Limits a query to the projects the user may see: all for admins, else direct or via a group. */
    public function applyAccessFilter(QueryBuilder $queryBuilder, string $alias, User $user): void
    {
        if (UserRole::Admin === $user->getRole()) {
            return;
        }

        $queryBuilder
            ->andWhere(\sprintf(
                '(%1$s.id IN (SELECT dp.id FROM %2$s dp JOIN dp.users du WHERE du = :accessUser)'
                .' OR %1$s.id IN (SELECT gp.id FROM %2$s gp JOIN gp.groups gg JOIN gg.users gu WHERE gu = :accessUser))',
                $alias,
                Project::class,
            ))
            ->setParameter('accessUser', $user);
    }

    public function isAccessibleBy(Project $project, User $user): bool
    {
        $queryBuilder = $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p = :project')
            ->setParameter('project', $project);
        $this->applyAccessFilter($queryBuilder, 'p', $user);

        return 0 < (int) $queryBuilder->getQuery()->getSingleScalarResult();
    }

    /** @return list<Project> */
    public function findByGroup(Group $group): array
    {
        /** @var list<Project> $projects */
        $projects = $this->createQueryBuilder('p')
            ->join('p.groups', 'g')
            ->where('g = :group')
            ->setParameter('group', $group)
            ->orderBy('p.title', 'ASC')
            ->getQuery()
            ->getResult();

        return $projects;
    }

    /** @return list<Group> */
    public function findAssignableGroups(Project $project): array
    {
        /** @var list<Group> $groups */
        $groups = $this->getEntityManager()->createQueryBuilder()
            ->select('g')
            ->from(Group::class, 'g')
            ->where('g.id NOT IN (SELECT ag.id FROM '.Project::class.' p JOIN p.groups ag WHERE p = :project)')
            ->setParameter('project', $project)
            ->orderBy('g.title', 'ASC')
            ->getQuery()
            ->getResult();

        return $groups;
    }

    /** @return list<User> */
    public function findAssignableUsers(Project $project): array
    {
        /** @var list<User> $users */
        $users = $this->getEntityManager()->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.id NOT IN (SELECT au.id FROM '.Project::class.' p JOIN p.users au WHERE p = :project)')
            ->setParameter('project', $project)
            ->orderBy('u.username', 'ASC')
            ->getQuery()
            ->getResult();

        return $users;
    }
}
