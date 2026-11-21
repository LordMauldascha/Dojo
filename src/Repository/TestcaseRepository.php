<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Testcase;
use App\Entity\TestcaseVersion;
use Doctrine\Persistence\ManagerRegistry;

/** @extends VersionedEntityRepository<Testcase> */
final class TestcaseRepository extends VersionedEntityRepository
{
    public function __construct(ManagerRegistry $registry, ProjectRepository $projects)
    {
        parent::__construct($registry, $projects, Testcase::class, TestcaseVersion::class, 'testcase', 'testcases');
    }
}
