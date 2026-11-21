<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Group;
use App\Entity\Project;
use App\Entity\Run;
use App\Entity\RunVersion;
use App\Entity\Skill;
use App\Entity\Testcase;
use App\Entity\User;
use App\Pagination\ListQuery;
use App\Pagination\ListQueryApplier;
use App\Pagination\Page;
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
        'skills' => 'skillCount',
        'testcases' => 'testcaseCount',
        'updatedAt' => 'p.updatedAt',
        'createdAt' => 'p.createdAt',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Project::class);
    }

    /** Items are rows of the form [0 => Project, 'userCount', 'groupCount', 'skillCount', 'testcaseCount' => int|string]. */
    public function paginate(ListQuery $query, int $perPage, User $viewer): Page
    {
        $queryBuilder = $this->createQueryBuilder('p')
            ->addSelect('SIZE(p.users) AS userCount')
            ->addSelect('SIZE(p.groups) AS groupCount');
        $this->selectContentCounts($queryBuilder);
        $this->applyAccessFilter($queryBuilder, 'p', $viewer);

        ListQueryApplier::search($queryBuilder, $query, ['p.title', 'p.description']);

        ListQueryApplier::sort($queryBuilder, $query, self::SORT_FIELDS, 'p.id');

        return Page::fromQueryBuilder($queryBuilder, $query->page, $perPage);
    }

    /**
     * Rows of the form [0 => Project, 'skillCount', 'testcaseCount', 'runCount' => int|string, 'lastRun' => ?\DateTimeImmutable].
     *
     * @return list<array<int|string, mixed>>
     */
    public function findOverview(User $viewer, int $limit): array
    {
        $queryBuilder = $this->createQueryBuilder('p')
            ->addSelect('(SELECT COUNT(r.id) FROM '.Run::class.' r WHERE r.project = p) AS runCount')
            ->addSelect('(SELECT MAX(rv.createdAt) FROM '.RunVersion::class.' rv JOIN rv.run rr WHERE rr.project = p) AS lastRun')
            ->orderBy('p.title', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->setMaxResults($limit);
        $this->selectContentCounts($queryBuilder);
        $this->applyAccessFilter($queryBuilder, 'p', $viewer);

        /** @var list<array<int|string, mixed>> $rows */
        $rows = $queryBuilder->getQuery()->getResult();
        foreach ($rows as &$row) {
            $row['lastRun'] = \is_string($row['lastRun']) ? new \DateTimeImmutable($row['lastRun']) : null;
        }

        return $rows;
    }

    /** Limits a query to the projects the user may see: all for admins, else direct or via a group. */
    public function applyAccessFilter(QueryBuilder $queryBuilder, string $alias, User $user): void
    {
        if ($user->isAdmin()) {
            return;
        }

        $queryBuilder
            ->andWhere(\sprintf('%s.id IN (%s)', $alias, $this->accessibleProjectIdsDql()))
            ->setParameter('accessUser', $user);
    }

    /**
     * DQL subselect of the project ids the user bound to :accessUser may see (for non-admins).
     * The suffix makes the aliases unique when the fragment is used more than once in a query.
     */
    public function accessibleProjectIdsDql(string $suffix = ''): string
    {
        return \sprintf(
            'SELECT ap%2$s.id FROM %1$s ap%2$s WHERE ap%2$s.id IN (SELECT dp%2$s.id FROM %1$s dp%2$s JOIN dp%2$s.users du%2$s WHERE du%2$s = :accessUser)'
            .' OR ap%2$s.id IN (SELECT gp%2$s.id FROM %1$s gp%2$s JOIN gp%2$s.groups gg%2$s JOIN gg%2$s.users gu%2$s WHERE gu%2$s = :accessUser)',
            Project::class,
            $suffix,
        );
    }

    public function countAccessibleBy(User $user): int
    {
        $queryBuilder = $this->createQueryBuilder('p')->select('COUNT(p.id)');
        $this->applyAccessFilter($queryBuilder, 'p', $user);

        return (int) $queryBuilder->getQuery()->getSingleScalarResult();
    }

    /** @return list<Project> */
    public function findAccessibleBy(User $user): array
    {
        $queryBuilder = $this->createQueryBuilder('p')->orderBy('p.title', 'ASC');
        $this->applyAccessFilter($queryBuilder, 'p', $user);

        /** @var list<Project> $projects */
        $projects = $queryBuilder->getQuery()->getResult();

        return $projects;
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

    private function selectContentCounts(QueryBuilder $queryBuilder): void
    {
        $queryBuilder
            ->addSelect('(SELECT COUNT(sk.id) FROM '.Skill::class.' sk JOIN sk.projects skp WHERE skp = p AND sk.deletedAt IS NULL) AS skillCount')
            ->addSelect('(SELECT COUNT(tc.id) FROM '.Testcase::class.' tc WHERE tc.project = p AND tc.deletedAt IS NULL) AS testcaseCount');
    }
}
