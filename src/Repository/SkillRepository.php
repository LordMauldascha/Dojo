<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Skill;
use App\Entity\SkillVersion;
use Doctrine\Persistence\ManagerRegistry;

/** @extends VersionedEntityRepository<Skill> */
final class SkillRepository extends VersionedEntityRepository
{
    public function __construct(ManagerRegistry $registry, ProjectRepository $projects)
    {
        parent::__construct($registry, $projects, Skill::class, SkillVersion::class, 'skill', 'skills');
    }
}
