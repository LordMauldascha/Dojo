<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\ApiKeyEncryptor;
use PHPUnit\Framework\TestCase;

final class ApiKeyEncryptorTest extends TestCase
{
    public function testRoundTripAndCiphertextDiffersFromPlaintext(): void
    {
        $encryptor = new ApiKeyEncryptor(sodium_crypto_secretbox_keygen());

        $encrypted = $encryptor->encrypt('sk-test-1234567890');

        self::assertStringNotContainsString('sk-test', $encrypted);
        self::assertSame('sk-test-1234567890', $encryptor->decrypt($encrypted));
    }

    public function testSamePlaintextProducesDifferentCiphertexts(): void
    {
        $encryptor = new ApiKeyEncryptor(sodium_crypto_secretbox_keygen());

        self::assertNotSame($encryptor->encrypt('sk-test-1234567890'), $encryptor->encrypt('sk-test-1234567890'));
    }

    public function testDecryptWithWrongKeyFails(): void
    {
        $encrypted = new ApiKeyEncryptor(sodium_crypto_secretbox_keygen())->encrypt('sk-test-1234567890');

        $this->expectException(\RuntimeException::class);
        new ApiKeyEncryptor(sodium_crypto_secretbox_keygen())->decrypt($encrypted);
    }

    public function testDecryptRejectsGarbage(): void
    {
        $this->expectException(\RuntimeException::class);
        new ApiKeyEncryptor(sodium_crypto_secretbox_keygen())->decrypt('not-valid');
    }

    public function testRejectsWrongKeyLength(): void
    {
        $this->expectException(\LogicException::class);
        new ApiKeyEncryptor('too-short');
    }
}
