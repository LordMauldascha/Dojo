<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Project;
use App\Entity\Testcase;
use App\Entity\User;
use App\Pagination\ListQuery;
use App\Pagination\Page;
use App\Security\UserRole;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<Testcase> */
final class TestcaseRepository extends ServiceEntityRepository
{
    private const SORT_FIELDS = [
        'title' => 'v.title',
        'updatedAt' => 't.updatedAt',
        'createdAt' => 't.createdAt',
    ];

    public function __construct(ManagerRegistry $registry, private readonly ProjectRepository $projects)
    {
        parent::__construct($registry, Testcase::class);
    }

    public function findActiveByUuid(string $uuid): ?Testcase
    {
        return $this->findOneBy(['uuid' => $uuid, 'deletedAt' => null]);
    }

    /** Items are testcases with their active version and project loaded. */
    public function paginate(ListQuery $query, int $perPage, User $viewer): Page
    {
        $queryBuilder = $this->createQueryBuilder('t')
            ->addSelect('v')
            ->addSelect('p')
            ->join('t.activeVersion', 'v')
            ->leftJoin('t.project', 'p');
        $this->applyAccessFilter($queryBuilder, 't', $viewer);

        if ('' !== $query->q) {
            $escaped = addcslashes(mb_strtolower($query->q), '%_\\');
            $queryBuilder
                ->andWhere('(LOWER(v.title) LIKE :q OR LOWER(v.description) LIKE :q)')
                ->setParameter('q', '%'.$escaped.'%');
        }

        if (null !== $query->project && '' !== $query->project) {
            $queryBuilder
                ->andWhere('p.uuid = :filterProject')
                ->setParameter('filterProject', Uuid::fromString($query->project), UuidType::NAME);
        }

        $sortField = self::SORT_FIELDS[$query->sort ?? ''] ?? self::SORT_FIELDS['title'];
        $direction = 'desc' === $query->dir ? 'DESC' : 'ASC';
        $queryBuilder->orderBy($sortField, $direction)->addOrderBy('t.id', 'ASC');

        return Page::fromQueryBuilder($queryBuilder, $query->page, $perPage);
    }

    /** Limits a query to the non-deleted testcases the user may see: all for admins, else via an accessible project. */
    public function applyAccessFilter(QueryBuilder $queryBuilder, string $alias, User $user): void
    {
        $queryBuilder->andWhere($alias.'.deletedAt IS NULL');
        if (UserRole::Admin === $user->getRole()) {
            return;
        }

        $queryBuilder
            ->andWhere(\sprintf('IDENTITY(%s.project) IN (%s)', $alias, $this->projects->accessibleProjectIdsDql()))
            ->setParameter('accessUser', $user);
    }

    public function isAccessibleBy(Testcase $testcase, User $user): bool
    {
        $queryBuilder = $this->createQueryBuilder('t')->select('COUNT(t.id)')->where('t = :testcase')->setParameter('testcase', $testcase);
        $this->applyAccessFilter($queryBuilder, 't', $user);

        return 0 < (int) $queryBuilder->getQuery()->getSingleScalarResult();
    }

    public function countByProject(Project $project): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.project = :project')
            ->andWhere('t.deletedAt IS NULL')
            ->setParameter('project', $project)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
