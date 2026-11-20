<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Project;
use App\Entity\Run;
use App\Pagination\ListQuery;
use App\Pagination\ListQueryApplier;
use App\Pagination\Page;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Run> */
final class RunRepository extends ServiceEntityRepository
{
    private const SORT_FIELDS = [
        'title' => 'r.title',
        'skills' => 'skillCount',
        'testcases' => 'testcaseCount',
        'updatedAt' => 'r.updatedAt',
        'createdAt' => 'r.createdAt',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Run::class);
    }

    /** Items are rows of the form [0 => Run, 'skillCount', 'testcaseCount' => int|string] of one project. */
    public function paginate(Project $project, ListQuery $query, int $perPage): Page
    {
        $queryBuilder = $this->createQueryBuilder('r')
            ->addSelect('SIZE(r.skills) AS skillCount')
            ->addSelect('SIZE(r.testcases) AS testcaseCount')
            ->where('r.project = :project')
            ->setParameter('project', $project);

        ListQueryApplier::search($queryBuilder, $query, ['r.title', 'r.description']);

        ListQueryApplier::sort($queryBuilder, $query, self::SORT_FIELDS, 'r.id');

        return Page::fromQueryBuilder($queryBuilder, $query->page, $perPage);
    }

    public function countByProject(Project $project): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.project = :project')
            ->setParameter('project', $project)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
