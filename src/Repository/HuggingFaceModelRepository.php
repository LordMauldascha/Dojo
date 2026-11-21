<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\HuggingFaceModel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<HuggingFaceModel> */
final class HuggingFaceModelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HuggingFaceModel::class);
    }

    /** @return list<HuggingFaceModel> */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['modelId' => 'ASC']);
    }

    /** @return list<string> */
    public function modelIds(): array
    {
        return array_map(static fn (HuggingFaceModel $model): string => $model->getModelId(), $this->findAllOrdered());
    }

    public function findByModelId(string $modelId): ?HuggingFaceModel
    {
        return $this->findOneBy(['modelId' => $modelId]);
    }
}
