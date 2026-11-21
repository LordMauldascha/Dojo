<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\JudgeSetting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<JudgeSetting> */
final class JudgeSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JudgeSetting::class);
    }

    public function findSetting(): ?JudgeSetting
    {
        return $this->find(JudgeSetting::ID);
    }
}
