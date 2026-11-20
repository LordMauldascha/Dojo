<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Project;
use App\Entity\Skill;
use App\Entity\User;
use App\Pagination\ListQuery;
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

        if ('' !== $query->q) {
            $escaped = addcslashes(mb_strtolower($query->q), '%_\\');
            $queryBuilder
                ->andWhere('(LOWER(v.title) LIKE :q OR LOWER(v.description) LIKE :q)')
                ->setParameter('q', '%'.$escaped.'%');
        }

        if (null !== $query->project && '' !== $query->project) {
            $accessibleOnly = UserRole::Admin === $viewer->getRole() ? '' : ' AND fp.id IN ('.$this->projects->accessibleProjectIdsDql('f').')';
            $queryBuilder
                ->andWhere('s.id IN (SELECT fs.id FROM '.Skill::class.' fs JOIN fs.projects fp WHERE fp.uuid = :filterProject'.$accessibleOnly.')')
                ->setParameter('filterProject', Uuid::fromString($query->project), UuidType::NAME);
        }

        $sortField = self::SORT_FIELDS[$query->sort ?? ''] ?? self::SORT_FIELDS['title'];
        $direction = 'desc' === $query->dir ? 'DESC' : 'ASC';
        $queryBuilder->orderBy($sortField, $direction)->addOrderBy('s.id', 'ASC');

        $page = Page::fromQueryBuilder($queryBuilder, $query->page, $perPage);
        $this->initializeProjects($page->items);

        return $page;
    }

    /** Limits a query to the non-deleted skills the user may see: all for admins, else via an accessible project. */
    public function applyAccessFilter(QueryBuilder $queryBuilder, string $alias, User $user): void
    {
        $queryBuilder->andWhere($alias.'.deletedAt IS NULL');
        if (UserRole::Admin === $user->getRole()) {
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
            ->getQuery()
            ->getResult();

        return $skills;
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
