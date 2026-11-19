<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Ai\Provider;
use App\Entity\ProviderApiKey;
use App\Security\ApiKeyEncryptor;
use App\Security\ApiKeyStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ImportEnvApiKeysCommandTest extends KernelTestCase
{
    /** @var array<string, mixed> */
    private array $savedServer = [];

    protected function setUp(): void
    {
        foreach (Provider::cases() as $provider) {
            $this->savedServer[$provider->envVar()] = $_SERVER[$provider->envVar()] ?? null;
            unset($_SERVER[$provider->envVar()]);
        }

        self::bootKernel();
        self::getContainer()->get(EntityManagerInterface::class)->createQuery('DELETE FROM '.ProviderApiKey::class)->execute();
    }

    protected function tearDown(): void
    {
        foreach ($this->savedServer as $name => $value) {
            if (null === $value) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $value;
            }
        }

        parent::tearDown();
    }

    public function testImportsOnlyMissingKeysAndNeverOverwrites(): void
    {
        $_SERVER['ANTHROPIC_API_KEY'] = 'sk-ant-from-env-9999';
        $_SERVER['CLAUDE_CODE_OAUTH_TOKEN'] = 'oauth-from-env-8888';
        $_SERVER['OPENAI_API_KEY'] = 'sk-new-from-env-7777';
        $_SERVER['GEMINI_API_KEY'] = '';
        self::getContainer()->get(ApiKeyStore::class)->save(Provider::OpenAi, 'sk-already-in-db-1111');

        $tester = new CommandTester(new Application(self::bootKernel())->find('app:api-keys:import-env'));
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringNotContainsString('from-env', $tester->getDisplay());
        self::assertSame('sk-ant-from-env-9999', $this->storedPlaintext(Provider::Anthropic));
        self::assertSame('oauth-from-env-8888', $this->storedPlaintext(Provider::ClaudeOAuth));
        self::assertSame('sk-already-in-db-1111', $this->storedPlaintext(Provider::OpenAi));
        self::assertNull($this->storedPlaintext(Provider::Gemini));
        self::assertNull($this->storedPlaintext(Provider::DeepSeek));
    }

    private function storedPlaintext(Provider $provider): ?string
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $stored = $entityManager->getRepository(ProviderApiKey::class)->findOneBy(['provider' => $provider]);

        return $stored instanceof ProviderApiKey ? self::getContainer()->get(ApiKeyEncryptor::class)->decrypt($stored->getEncryptedKey()) : null;
    }
}
