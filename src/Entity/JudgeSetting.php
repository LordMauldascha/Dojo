<?php

declare(strict_types=1);

namespace App\Entity;

use App\Ai\Provider;
use App\Repository\JudgeSettingRepository;
use Doctrine\ORM\Mapping as ORM;

/** Provider and model of the LLM judge. A singleton: the table holds at most the row with ID 1. */
#[ORM\Entity(repositoryClass: JudgeSettingRepository::class)]
#[ORM\Table(name: 'judge_setting')]
class JudgeSetting
{
    public const ID = 1;

    #[ORM\Id]
    #[ORM\Column]
    private int $id = self::ID;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\Column(length: 32, enumType: Provider::class)]
        private Provider $provider,
        #[ORM\Column(length: 255)]
        private string $model,
    ) {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getProvider(): Provider
    {
        return $this->provider;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function change(Provider $provider, string $model): void
    {
        $this->provider = $provider;
        $this->model = $model;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
