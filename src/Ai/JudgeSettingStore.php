<?php

declare(strict_types=1);

namespace App\Ai;

use App\Entity\JudgeSetting;
use App\Repository\JudgeSettingRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/** The provider and model the LLM judge uses to rate testcases; null until an admin has chosen one. */
final readonly class JudgeSettingStore
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private JudgeSettingRepository $settings,
    ) {
    }

    public function get(): ?LlmConfiguration
    {
        $setting = $this->settings->findSetting();

        return null === $setting ? null : new LlmConfiguration($setting->getProvider(), $setting->getModel());
    }

    /** Returns false when another admin saved the judge for the first time at the same time; the entity manager is closed then. */
    public function save(LlmConfiguration $configuration): bool
    {
        $existing = $this->settings->findSetting();

        if (null === $existing) {
            $this->entityManager->persist(new JudgeSetting($configuration->provider, $configuration->model));
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
