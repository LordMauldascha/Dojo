<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Project;
use App\Entity\Run;
use App\Entity\User;
use App\Pagination\ListQuery;
use App\Pagination\ListQueryApplier;
use App\Pagination\Page;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Queries shared by the project-assigned, versioned entities (Skill, Testcase).
 *
 * @template T of object
 *
 * @extends ServiceEntityRepository<T>
 */
abstract class VersionedEntityRepository extends ServiceEntityRepository
{
    private const ALIAS = 'e';

    private const SORT_FIELDS = [
        'title' => 'v.title',
        'updatedAt' => self::ALIAS.'.updatedAt',
        'createdAt' => self::ALIAS.'.createdAt',
    ];

    /**
     * @param class-string<T> $entityClass
     * @param class-string    $versionClass    version entity of $entityClass
     * @param string          $versionRelation name of the version's association back to the entity
     * @param string          $runRelation     name of the collection on Run that holds the entities
     */
    public function __construct(
        ManagerRegistry $registry,
        private readonly ProjectRepository $projects,
        string $entityClass,
        private readonly string $versionClass,
        private readonly string $versionRelation,
        private readonly string $runRelation,
    ) {
        parent::__construct($registry, $entityClass);
    }

    /** @return T|null */
    public function findActiveByUuid(string $uuid): ?object
    {
        return $this->findOneBy(['uuid' => $uuid, 'deletedAt' => null]);
    }

    /** Items are entities with their active version loaded and their projects initialized. */
    public function paginate(ListQuery $query, int $perPage, User $viewer): Page
    {
        $queryBuilder = $this->createQueryBuilder(self::ALIAS)->addSelect('v')->join(self::ALIAS.'.activeVersion', 'v');
        $this->applyAccessFilter($queryBuilder, self::ALIAS, $viewer);

        ListQueryApplier::search($queryBuilder, $query, ['v.title', 'v.description']);

        if (null !== $query->project && '' !== $query->project) {
            $accessibleOnly = $viewer->isAdmin() ? '' : ' AND fp.id IN ('.$this->projects->accessibleProjectIdsDql('f').')';
            $queryBuilder
                ->andWhere(self::ALIAS.'.id IN (SELECT fe.id FROM '.$this->getEntityName().' fe JOIN fe.projects fp WHERE fp.uuid = :filterProject'.$accessibleOnly.')')
                ->setParameter('filterProject', Uuid::fromString($query->project), UuidType::NAME);
        }

        ListQueryApplier::sort($queryBuilder, $query, self::SORT_FIELDS, self::ALIAS.'.id');

        $page = Page::fromQueryBuilder($queryBuilder, $query->page, $perPage);
        $this->initializeProjects($page->items);

        return $page;
    }

    /** Limits a query to the non-deleted entities the user may see: all for admins, else via an accessible project. */
    public function applyAccessFilter(QueryBuilder $queryBuilder, string $alias, User $user): void
    {
        $queryBuilder->andWhere($alias.'.deletedAt IS NULL');
        if ($user->isAdmin()) {
            return;
        }

        $queryBuilder
            ->andWhere(\sprintf(
                '%1$s.id IN (SELECT ase.id FROM %2$s ase JOIN ase.projects asp WHERE asp.id IN (%3$s))',
                $alias,
                $this->getEntityName(),
                $this->projects->accessibleProjectIdsDql(),
            ))
            ->setParameter('accessUser', $user);
    }

    public function countCreatedBy(User $user): int
    {
        return $this->count(['createdBy' => $user, 'deletedAt' => null]);
    }

    /** @return list<object> open drafts of visible entities, newest first, with the entity loaded */
    public function findRecentDrafts(User $viewer, int $limit): array
    {
        $queryBuilder = $this->getEntityManager()->createQueryBuilder()
            ->select('d', self::ALIAS)
            ->from($this->versionClass, 'd')
            ->join('d.'.$this->versionRelation, self::ALIAS)
            ->where('d.draft = true')
            ->orderBy('d.savedAt', 'DESC')
            ->addOrderBy('d.id', 'DESC')
            ->setMaxResults($limit);
        $this->applyAccessFilter($queryBuilder, self::ALIAS, $viewer);

        /** @var list<object> $drafts */
        $drafts = $queryBuilder->getQuery()->getResult();

        return $drafts;
    }

    /** @param T $entity */
    public function isAccessibleBy(object $entity, User $user): bool
    {
        $queryBuilder = $this->createQueryBuilder(self::ALIAS)->select('COUNT('.self::ALIAS.'.id)')->where(self::ALIAS.' = :entity')->setParameter('entity', $entity);
        $this->applyAccessFilter($queryBuilder, self::ALIAS, $user);

        return 0 < (int) $queryBuilder->getQuery()->getSingleScalarResult();
    }

    /** @return list<T> */
    public function findByProject(Project $project): array
    {
        /** @var list<T> $entities */
        $entities = $this->createQueryBuilder(self::ALIAS)
            ->addSelect('v')
            ->join(self::ALIAS.'.activeVersion', 'v')
            ->join(self::ALIAS.'.projects', 'p')
            ->where('p = :project')
            ->andWhere(self::ALIAS.'.deletedAt IS NULL')
            ->setParameter('project', $project)
            ->orderBy('v.title', 'ASC')
            ->addOrderBy(self::ALIAS.'.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $entities;
    }

    public function countByProject(Project $project): int
    {
        return (int) $this->createQueryBuilder(self::ALIAS)
            ->select('COUNT(DISTINCT '.self::ALIAS.'.id)')
            ->join(self::ALIAS.'.projects', 'p')
            ->where('p = :project')
            ->andWhere(self::ALIAS.'.deletedAt IS NULL')
            ->setParameter('project', $project)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Draft version number per entity id, only for entities that have a draft, without loading their versions.
     *
     * @param list<mixed> $entities
     *
     * @return array<int, int>
     */
    public function draftNumbers(array $entities): array
    {
        if ([] === $entities) {
            return [];
        }

        /** @var list<array{entityId: int|string, number: int}> $rows */
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select(\sprintf('IDENTITY(v.%s) AS entityId', $this->versionRelation), 'v.number AS number')
            ->from($this->versionClass, 'v')
            ->where('v.draft = true')
            ->andWhere(\sprintf('v.%s IN (:entities)', $this->versionRelation))
            ->setParameter('entities', $entities)
            ->getQuery()
            ->getArrayResult();

        $numbers = [];
        foreach ($rows as $row) {
            $numbers[(int) $row['entityId']] = $row['number'];
        }

        return $numbers;
    }

    /**
     * Number of runs per entity id; non-admins only count runs of projects they can access.
     *
     * @param list<mixed> $entities
     *
     * @return array<int, int>
     */
    public function runCounts(array $entities, User $viewer): array
    {
        if ([] === $entities) {
            return [];
        }

        $queryBuilder = $this->getEntityManager()->createQueryBuilder()
            ->select(self::ALIAS.'.id AS entityId', 'COUNT(r.id) AS runCount')
            ->from(Run::class, 'r')
            ->join('r.'.$this->runRelation, self::ALIAS)
            ->where(self::ALIAS.' IN (:entities)')
            ->setParameter('entities', $entities)
            ->groupBy(self::ALIAS.'.id');
        if (!$viewer->isAdmin()) {
            $queryBuilder
                ->andWhere(\sprintf('IDENTITY(r.project) IN (%s)', $this->projects->accessibleProjectIdsDql()))
                ->setParameter('accessUser', $viewer);
        }

        $counts = [];
        /** @var list<array{entityId: int, runCount: int|string}> $rows */
        $rows = $queryBuilder->getQuery()->getArrayResult();
        foreach ($rows as $row) {
            $counts[$row['entityId']] = (int) $row['runCount'];
        }

        return $counts;
    }

    /** @param list<mixed> $entities */
    private function initializeProjects(array $entities): void
    {
        if ([] === $entities) {
            return;
        }

        $this->createQueryBuilder(self::ALIAS)
            ->addSelect('p')
            ->leftJoin(self::ALIAS.'.projects', 'p')
            ->where(self::ALIAS.' IN (:entities)')
            ->setParameter('entities', $entities)
            ->getQuery()
            ->getResult();
    }
}
