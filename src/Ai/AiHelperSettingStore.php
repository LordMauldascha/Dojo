<?php

declare(strict_types=1);

namespace App\Ai;

use App\Entity\AiHelperSetting;
use App\Repository\AiHelperSettingRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/** The provider and model the AI helper uses to generate skills and testcases; null until an admin has chosen one. */
final readonly class AiHelperSettingStore
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AiHelperSettingRepository $settings,
    ) {
    }

    public function get(): ?LlmConfiguration
    {
        $setting = $this->settings->findSetting();

        return null === $setting ? null : new LlmConfiguration($setting->getProvider(), $setting->getModel());
    }

    /** Returns false when another admin saved the AI helper for the first time at the same time; the entity manager is closed then. */
    public function save(LlmConfiguration $configuration): bool
    {
        $existing = $this->settings->findSetting();

        if (null === $existing) {
            $this->entityManager->persist(new AiHelperSetting($configuration->provider, $configuration->model));
        } else {
            $existing->change($configuration->provider, $configuration->model);
        }

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }
}
