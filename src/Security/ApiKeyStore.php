<?php

declare(strict_types=1);

namespace App\Security;

use App\Ai\Provider;
use App\Entity\ProviderApiKey;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ApiKeyStore
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ApiKeyEncryptor $encryptor,
    ) {
    }

    /** @return array<string, ProviderApiKey> keyed by provider value */
    public function findAll(): array
    {
        $byProvider = [];
        foreach ($this->entityManager->getRepository(ProviderApiKey::class)->findAll() as $key) {
            $byProvider[$key->getProvider()->value] = $key;
        }

        return $byProvider;
    }

    public function has(Provider $provider): bool
    {
        return null !== $this->entityManager->getRepository(ProviderApiKey::class)->findOneBy(['provider' => $provider]);
    }

    public function save(Provider $provider, string $plainKey): void
    {
        $encrypted = $this->encryptor->encrypt($plainKey);
        $lastFour = mb_substr($plainKey, -4);
        $existing = $this->entityManager->getRepository(ProviderApiKey::class)->findOneBy(['provider' => $provider]);

        if (null === $existing) {
            $this->entityManager->persist(new ProviderApiKey($provider, $encrypted, $lastFour));
        } else {
            $existing->replaceKey($encrypted, $lastFour);
        }

        $this->entityManager->flush();
    }
}
