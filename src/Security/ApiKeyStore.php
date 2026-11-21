<?php

declare(strict_types=1);

namespace App\Security;

use App\Ai\Credential;
use App\Ai\Provider;
use App\Entity\ProviderApiKey;
use App\Repository\ProviderApiKeyRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ApiKeyStore
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ProviderApiKeyRepository $keys,
        private ApiKeyEncryptor $encryptor,
    ) {
    }

    /** @return array<string, ProviderApiKey> keyed by credential value */
    public function findAll(): array
    {
        $byCredential = [];
        foreach ($this->keys->findAll() as $key) {
            $byCredential[$key->getCredential()->value] = $key;
        }

        return $byCredential;
    }

    public function has(Credential $credential): bool
    {
        return null !== $this->keys->findByCredential($credential);
    }

    /** True if at least one credential of the provider is stored. */
    public function hasProvider(Provider $provider): bool
    {
        foreach ($provider->credentials() as $credential) {
            if ($this->has($credential)) {
                return true;
            }
        }

        return false;
    }

    public function save(Credential $credential, string $plainKey): void
    {
        $encrypted = $this->encryptor->encrypt($plainKey);
        $lastFour = mb_substr($plainKey, -4);
        $existing = $this->keys->findByCredential($credential);

        if (null === $existing) {
            $this->entityManager->persist(new ProviderApiKey($credential, $encrypted, $lastFour));
        } else {
            $existing->replaceKey($encrypted, $lastFour);
        }

        $this->entityManager->flush();
    }

    /** Returns false if there was no such credential. */
    public function delete(Credential $credential): bool
    {
        $key = $this->keys->findByCredential($credential);
        if (null === $key) {
            return false;
        }

        $this->entityManager->remove($key);
        $this->entityManager->flush();

        return true;
    }
}
