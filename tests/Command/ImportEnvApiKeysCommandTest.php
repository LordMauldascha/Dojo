<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Ai\Credential;
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
        foreach (Credential::cases() as $credential) {
            $this->savedServer[$credential->envVar()] = $_SERVER[$credential->envVar()] ?? null;
            unset($_SERVER[$credential->envVar()]);
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
        self::getContainer()->get(ApiKeyStore::class)->save(Credential::OpenAi, 'sk-already-in-db-1111');

        $tester = new CommandTester(new Application(self::bootKernel())->find('app:api-keys:import-env'));
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringNotContainsString('from-env', $tester->getDisplay());
        self::assertSame('sk-ant-from-env-9999', $this->storedPlaintext(Credential::Anthropic));
        self::assertSame('oauth-from-env-8888', $this->storedPlaintext(Credential::ClaudeOAuth));
        self::assertSame('sk-already-in-db-1111', $this->storedPlaintext(Credential::OpenAi));
        self::assertNull($this->storedPlaintext(Credential::Gemini));
        self::assertNull($this->storedPlaintext(Credential::DeepSeek));
    }

    private function storedPlaintext(Credential $credential): ?string
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $stored = $entityManager->getRepository(ProviderApiKey::class)->findOneBy(['credential' => $credential]);

        return $stored instanceof ProviderApiKey ? self::getContainer()->get(ApiKeyEncryptor::class)->decrypt($stored->getEncryptedKey()) : null;
    }
}
