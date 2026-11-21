<?php

declare(strict_types=1);

namespace App\Entity;

use App\Ai\Credential;
use App\Repository\ProviderApiKeyRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProviderApiKeyRepository::class)]
#[ORM\Table(name: 'provider_api_key')]
class ProviderApiKey
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\Column(name: 'provider', length: 32, unique: true, enumType: Credential::class)]
        private Credential $credential,
        #[ORM\Column(type: Types::TEXT)]
        private string $encryptedKey,
        #[ORM\Column(length: 4)]
        private string $lastFour,
    ) {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getCredential(): Credential
    {
        return $this->credential;
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
