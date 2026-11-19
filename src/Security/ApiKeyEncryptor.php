<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reversible encryption (XSalsa20-Poly1305) for outgoing provider API keys.
 * A one-way hash is not an option: the provider needs the plaintext on every request.
 */
final readonly class ApiKeyEncryptor
{
    private string $key;

    public function __construct(
        #[Autowire(env: 'base64:API_KEY_ENCRYPTION_KEY')]
        string $key,
    ) {
        if (SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== \strlen($key)) {
            throw new \LogicException('API_KEY_ENCRYPTION_KEY must be a base64 encoded 32 byte key. Generate one with: php -r \'echo base64_encode(sodium_crypto_secretbox_keygen());\'');
        }

        $this->key = $key;
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce.sodium_crypto_secretbox($plaintext, $nonce, $this->key));
    }

    public function decrypt(string $ciphertext): string
    {
        $raw = base64_decode($ciphertext, true);
        if (false === $raw || SODIUM_CRYPTO_SECRETBOX_NONCEBYTES >= \strlen($raw)) {
            throw new \RuntimeException('Invalid encrypted API key.');
        }

        $plaintext = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key,
        );
        if (false === $plaintext) {
            throw new \RuntimeException('API key could not be decrypted (wrong key or corrupted data).');
        }

        return $plaintext;
    }
}
