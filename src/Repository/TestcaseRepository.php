<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Project;
use App\Entity\Run;
use App\Entity\Testcase;
use App\Entity\TestcaseVersion;
use App\Entity\User;
use App\Pagination\ListQuery;
use App\Pagination\ListQueryApplier;
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

    /** Items are testcases with their active version and projects loaded. */
    public function paginate(ListQuery $query, int $perPage, User $viewer): Page
    {
        $queryBuilder = $this->createQueryBuilder('t')
            ->addSelect('v')
            ->join('t.activeVersion', 'v');
        $this->applyAccessFilter($queryBuilder, 't', $viewer);

        ListQueryApplier::search($queryBuilder, $query, ['v.title', 'v.description']);

        if (null !== $query->project && '' !== $query->project) {
            $accessibleOnly = $viewer->isAdmin() ? '' : ' AND fp.id IN ('.$this->projects->accessibleProjectIdsDql('f').')';
            $queryBuilder
                ->andWhere('t.id IN (SELECT ft.id FROM '.Testcase::class.' ft JOIN ft.projects fp WHERE fp.uuid = :filterProject'.$accessibleOnly.')')
                ->setParameter('filterProject', Uuid::fromString($query->project), UuidType::NAME);
        }

        ListQueryApplier::sort($queryBuilder, $query, self::SORT_FIELDS, 't.id');

        $page = Page::fromQueryBuilder($queryBuilder, $query->page, $perPage);
        $this->initializeProjects($page->items);

        return $page;
    }

    /** Limits a query to the non-deleted testcases the user may see: all for admins, else via an accessible project. */
    public function applyAccessFilter(QueryBuilder $queryBuilder, string $alias, User $user): void
    {
        $queryBuilder->andWhere($alias.'.deletedAt IS NULL');
        if ($user->isAdmin()) {
            return;
        }

        $queryBuilder
            ->andWhere(\sprintf(
                '%1$s.id IN (SELECT atc.id FROM %2$s atc JOIN atc.projects atp WHERE atp.id IN (%3$s))',
                $alias,
                Testcase::class,
                $this->projects->accessibleProjectIdsDql(),
            ))
            ->setParameter('accessUser', $user);
    }

    public function countCreatedBy(User $user): int
    {
        return $this->count(['createdBy' => $user, 'deletedAt' => null]);
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
            ->join('t.projects', 'p')
            ->where('p = :project')
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
            ->select('COUNT(DISTINCT t.id)')
            ->join('t.projects', 'p')
            ->where('p = :project')
            ->andWhere('t.deletedAt IS NULL')
            ->setParameter('project', $project)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Number of runs per testcase id; non-admins only count runs of projects they can access.
     *
     * @param list<mixed> $testcases
     *
     * @return array<int, int>
     */
    public function runCounts(array $testcases, User $viewer): array
    {
        if ([] === $testcases) {
            return [];
        }

        $queryBuilder = $this->getEntityManager()->createQueryBuilder()
            ->select('t.id AS testcaseId', 'COUNT(r.id) AS runCount')
            ->from(Run::class, 'r')
            ->join('r.testcases', 't')
            ->where('t IN (:testcases)')
            ->setParameter('testcases', $testcases)
            ->groupBy('t.id');
        if (UserRole::Admin !== $viewer->getRole()) {
            $queryBuilder
                ->andWhere(\sprintf('IDENTITY(r.project) IN (%s)', $this->projects->accessibleProjectIdsDql()))
                ->setParameter('accessUser', $viewer);
        }

        $counts = [];
        /** @var list<array{testcaseId: int, runCount: int|string}> $rows */
        $rows = $queryBuilder->getQuery()->getArrayResult();
        foreach ($rows as $row) {
            $counts[$row['testcaseId']] = (int) $row['runCount'];
        }

        return $counts;
    }

    /** @param list<mixed> $testcases */
    private function initializeProjects(array $testcases): void
    {
        if ([] === $testcases) {
            return;
        }

        $this->createQueryBuilder('t')
            ->addSelect('p')
            ->leftJoin('t.projects', 'p')
            ->where('t IN (:testcases)')
            ->setParameter('testcases', $testcases)
            ->getQuery()
            ->getResult();
    }
}
