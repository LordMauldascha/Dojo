<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Project;
use App\Entity\Testcase;
use App\Entity\TestcaseVersion;
use App\Entity\User;
use App\Pagination\ListQuery;
use App\Pagination\ListQueryApplier;
use App\Pagination\Page;
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

        ListQueryApplier::search($queryBuilder, $query, ['v.title', 'v.description']);

        if (null !== $query->project && '' !== $query->project) {
            $queryBuilder
                ->andWhere('p.uuid = :filterProject')
                ->setParameter('filterProject', Uuid::fromString($query->project), UuidType::NAME);
        }

        ListQueryApplier::sort($queryBuilder, $query, self::SORT_FIELDS, 't.id');

        return Page::fromQueryBuilder($queryBuilder, $query->page, $perPage);
    }

    /** Limits a query to the non-deleted testcases the user may see: all for admins, else via an accessible project. */
    public function applyAccessFilter(QueryBuilder $queryBuilder, string $alias, User $user): void
    {
        $queryBuilder->andWhere($alias.'.deletedAt IS NULL');
        if ($user->isAdmin()) {
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

    /** @return list<Testcase> */
    public function findByProject(Project $project): array
    {
        /** @var list<Testcase> $testcases */
        $testcases = $this->createQueryBuilder('t')
            ->addSelect('v')
            ->join('t.activeVersion', 'v')
            ->where('t.project = :project')
            ->andWhere('t.deletedAt IS NULL')
            ->setParameter('project', $project)
            ->orderBy('v.title', 'ASC')
            ->addOrderBy('t.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $testcases;
    }

    /**
     * Ids of the given testcases that have a draft, without loading their versions.
     *
     * @param list<mixed> $testcases
     *
     * @return list<int>
     */
    public function draftIds(array $testcases): array
    {
        if ([] === $testcases) {
            return [];
        }

        /** @var list<int|string> $ids */
        $ids = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(v.testcase)')
            ->from(TestcaseVersion::class, 'v')
            ->where('v.draft = true')
            ->andWhere('v.testcase IN (:testcases)')
            ->setParameter('testcases', $testcases)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(intval(...), $ids);
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
