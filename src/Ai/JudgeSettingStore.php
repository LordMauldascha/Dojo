<?php

declare(strict_types=1);

namespace App\Ai;

use App\Entity\JudgeSetting;
use App\Repository\JudgeSettingRepository;
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

    public function save(LlmConfiguration $configuration): void
    {
        $existing = $this->settings->findSetting();

        if (null === $existing) {
            $this->entityManager->persist(new JudgeSetting($configuration->provider, $configuration->model));
        } else {
            $existing->change($configuration->provider, $configuration->model);
        }

        $this->entityManager->flush();
    }
}
