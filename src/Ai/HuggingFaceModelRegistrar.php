<?php

declare(strict_types=1);

namespace App\Ai;

use App\Entity\HuggingFaceModel;
use App\Repository\HuggingFaceModelRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;

/** Adds a model to the list offered for runs and the judge once the Hub confirms it exists. */
final readonly class HuggingFaceModelRegistrar
{
    public function __construct(
        private HuggingFaceHub $hub,
        private HuggingFaceModelRepository $models,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function add(string $modelId): HuggingFaceModelAddResult
    {
        if (null !== $this->models->findByModelId($modelId)) {
            return HuggingFaceModelAddResult::AlreadyAdded;
        }

        try {
            if (!$this->hub->exists($modelId)) {
                return HuggingFaceModelAddResult::NotFoundOnHub;
            }
        } catch (HttpClientException) {
            return HuggingFaceModelAddResult::HubUnavailable;
        }

        $this->entityManager->persist(new HuggingFaceModel($modelId));
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            return HuggingFaceModelAddResult::Conflict;
        }

        return HuggingFaceModelAddResult::Added;
    }
}
