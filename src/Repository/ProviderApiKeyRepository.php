<?php

declare(strict_types=1);

namespace App\Repository;

use App\Ai\Provider;
use App\Entity\ProviderApiKey;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ProviderApiKey> */
final class ProviderApiKeyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProviderApiKey::class);
    }

    public function findByProvider(Provider $provider): ?ProviderApiKey
    {
        return $this->findOneBy(['provider' => $provider]);
    }
}
