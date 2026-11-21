<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Project;
use App\Entity\Run;
use App\Entity\Skill;
use App\Entity\SkillVersion;
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

/** @extends ServiceEntityRepository<Skill> */
final class SkillRepository extends ServiceEntityRepository
{
    private const SORT_FIELDS = [
        'title' => 'v.title',
        'updatedAt' => 's.updatedAt',
        'createdAt' => 's.createdAt',
    ];

    public function __construct(ManagerRegistry $registry, private readonly ProjectRepository $projects)
    {
        parent::__construct($registry, Skill::class);
    }

    public function findActiveByUuid(string $uuid): ?Skill
    {
        return $this->findOneBy(['uuid' => $uuid, 'deletedAt' => null]);
    }

    /** Items are skills with their active version loaded and their projects initialized. */
    public function paginate(ListQuery $query, int $perPage, User $viewer): Page
    {
        $queryBuilder = $this->createQueryBuilder('s')->addSelect('v')->join('s.activeVersion', 'v');
        $this->applyAccessFilter($queryBuilder, 's', $viewer);

        ListQueryApplier::search($queryBuilder, $query, ['v.title', 'v.description']);

        if (null !== $query->project && '' !== $query->project) {
            $accessibleOnly = $viewer->isAdmin() ? '' : ' AND fp.id IN ('.$this->projects->accessibleProjectIdsDql('f').')';
            $queryBuilder
                ->andWhere('s.id IN (SELECT fs.id FROM '.Skill::class.' fs JOIN fs.projects fp WHERE fp.uuid = :filterProject'.$accessibleOnly.')')
                ->setParameter('filterProject', Uuid::fromString($query->project), UuidType::NAME);
        }

        ListQueryApplier::sort($queryBuilder, $query, self::SORT_FIELDS, 's.id');

        $page = Page::fromQueryBuilder($queryBuilder, $query->page, $perPage);
        $this->initializeProjects($page->items);

        return $page;
    }

    /** Limits a query to the non-deleted skills the user may see: all for admins, else via an accessible project. */
    public function applyAccessFilter(QueryBuilder $queryBuilder, string $alias, User $user): void
    {
        $queryBuilder->andWhere($alias.'.deletedAt IS NULL');
        if ($user->isAdmin()) {
            return;
        }

        $queryBuilder
            ->andWhere(\sprintf(
                '%1$s.id IN (SELECT ask.id FROM %2$s ask JOIN ask.projects asp WHERE asp.id IN (%3$s))',
                $alias,
                Skill::class,
                $this->projects->accessibleProjectIdsDql(),
            ))
            ->setParameter('accessUser', $user);
    }

    public function countCreatedBy(User $user): int
    {
        return $this->count(['createdBy' => $user, 'deletedAt' => null]);
    }

    /** @return list<SkillVersion> open drafts of visible skills, newest first, with the skill loaded */
    public function findRecentDrafts(User $viewer, int $limit): array
    {
        $queryBuilder = $this->getEntityManager()->createQueryBuilder()
            ->select('d', 's')
            ->from(SkillVersion::class, 'd')
            ->join('d.skill', 's')
            ->where('d.draft = true')
            ->orderBy('d.savedAt', 'DESC')
            ->addOrderBy('d.id', 'DESC')
            ->setMaxResults($limit);
        $this->applyAccessFilter($queryBuilder, 's', $viewer);

        /** @var list<SkillVersion> $drafts */
        $drafts = $queryBuilder->getQuery()->getResult();

        return $drafts;
    }

    public function isAccessibleBy(Skill $skill, User $user): bool
    {
        $queryBuilder = $this->createQueryBuilder('s')->select('COUNT(s.id)')->where('s = :skill')->setParameter('skill', $skill);
        $this->applyAccessFilter($queryBuilder, 's', $user);

        return 0 < (int) $queryBuilder->getQuery()->getSingleScalarResult();
    }

    /** @return list<Skill> */
    public function findByProject(Project $project): array
    {
        /** @var list<Skill> $skills */
        $skills = $this->createQueryBuilder('s')
            ->addSelect('v')
            ->join('s.activeVersion', 'v')
            ->join('s.projects', 'p')
            ->where('p = :project')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('project', $project)
            ->orderBy('v.title', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $skills;
    }

    /** @return list<Skill> */
    public function findAssignableToProject(Project $project): array
    {
        /** @var list<Skill> $skills */
        $skills = $this->createQueryBuilder('s')
            ->addSelect('v')
            ->join('s.activeVersion', 'v')
            ->where('s.deletedAt IS NULL')
            ->andWhere('s.id NOT IN (SELECT ps.id FROM '.Skill::class.' ps JOIN ps.projects pp WHERE pp = :project)')
            ->setParameter('project', $project)
            ->orderBy('v.title', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $skills;
    }

    /**
     * Draft version number per skill id, only for skills that have a draft, without loading their versions.
     *
     * @param list<mixed> $skills
     *
     * @return array<int, int>
     */
    public function draftNumbers(array $skills): array
    {
        if ([] === $skills) {
            return [];
        }

        /** @var list<array{skillId: int|string, number: int}> $rows */
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(v.skill) AS skillId', 'v.number AS number')
            ->from(SkillVersion::class, 'v')
            ->where('v.draft = true')
            ->andWhere('v.skill IN (:skills)')
            ->setParameter('skills', $skills)
            ->getQuery()
            ->getArrayResult();

        $numbers = [];
        foreach ($rows as $row) {
            $numbers[(int) $row['skillId']] = $row['number'];
        }

        return $numbers;
    }

    /**
     * Number of runs per skill id; non-admins only count runs of projects they can access.
     *
     * @param list<mixed> $skills
     *
     * @return array<int, int>
     */
    public function runCounts(array $skills, User $viewer): array
    {
        if ([] === $skills) {
            return [];
        }

        $queryBuilder = $this->getEntityManager()->createQueryBuilder()
            ->select('s.id AS skillId', 'COUNT(r.id) AS runCount')
            ->from(Run::class, 'r')
            ->join('r.skills', 's')
            ->where('s IN (:skills)')
            ->setParameter('skills', $skills)
            ->groupBy('s.id');
        if (UserRole::Admin !== $viewer->getRole()) {
            $queryBuilder
                ->andWhere(\sprintf('IDENTITY(r.project) IN (%s)', $this->projects->accessibleProjectIdsDql()))
                ->setParameter('accessUser', $viewer);
        }

        $counts = [];
        /** @var list<array{skillId: int, runCount: int|string}> $rows */
        $rows = $queryBuilder->getQuery()->getArrayResult();
        foreach ($rows as $row) {
            $counts[$row['skillId']] = (int) $row['runCount'];
        }

        return $counts;
    }

    /** @param list<mixed> $skills */
    private function initializeProjects(array $skills): void
    {
        if ([] === $skills) {
            return;
        }

        $this->createQueryBuilder('s')
            ->addSelect('p')
            ->leftJoin('s.projects', 'p')
            ->where('s IN (:skills)')
            ->setParameter('skills', $skills)
            ->getQuery()
            ->getResult();
    }
}
