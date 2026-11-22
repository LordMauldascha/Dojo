<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AiHelperSetting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AiHelperSetting> */
final class AiHelperSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AiHelperSetting::class);
    }

    public function findSetting(): ?AiHelperSetting
    {
        return $this->find(AiHelperSetting::ID);
    }
}
