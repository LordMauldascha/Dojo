<?php

declare(strict_types=1);

namespace App\Entity;

use App\Ai\Provider;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'provider_api_key')]
class ProviderApiKey
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $encryptedKey;

    #[ORM\Column(length: 4)]
    private string $lastFour;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\Column(length: 32, unique: true, enumType: Provider::class)]
        private Provider $provider,
        string $encryptedKey,
        string $lastFour,
    ) {
        $this->encryptedKey = $encryptedKey;
        $this->lastFour = $lastFour;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getProvider(): Provider
    {
        return $this->provider;
    }

    public function getEncryptedKey(): string
    {
        return $this->encryptedKey;
    }

    public function getLastFour(): string
    {
        return $this->lastFour;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function replaceKey(string $encryptedKey, string $lastFour): void
    {
        $this->encryptedKey = $encryptedKey;
        $this->lastFour = $lastFour;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
