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

    /** @return list<TestcaseVersion> open drafts of visible testcases, newest first, with the testcase loaded */
    public function findRecentDrafts(User $viewer, int $limit): array
    {
        $queryBuilder = $this->getEntityManager()->createQueryBuilder()
            ->select('d', 't')
            ->from(TestcaseVersion::class, 'd')
            ->join('d.testcase', 't')
            ->where('d.draft = true')
            ->orderBy('d.savedAt', 'DESC')
            ->addOrderBy('d.id', 'DESC')
            ->setMaxResults($limit);
        $this->applyAccessFilter($queryBuilder, 't', $viewer);

        /** @var list<TestcaseVersion> $drafts */
        $drafts = $queryBuilder->getQuery()->getResult();

        return $drafts;
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
     * Draft version number per testcase id, only for testcases that have a draft, without loading their versions.
     *
     * @param list<mixed> $testcases
     *
     * @return array<int, int>
     */
    public function draftNumbers(array $testcases): array
    {
        if ([] === $testcases) {
            return [];
        }

        /** @var list<array{testcaseId: int|string, number: int}> $rows */
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(v.testcase) AS testcaseId', 'v.number AS number')
            ->from(TestcaseVersion::class, 'v')
            ->where('v.draft = true')
            ->andWhere('v.testcase IN (:testcases)')
            ->setParameter('testcases', $testcases)
            ->getQuery()
            ->getArrayResult();

        $numbers = [];
        foreach ($rows as $row) {
            $numbers[(int) $row['testcaseId']] = $row['number'];
        }

        return $numbers;
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
