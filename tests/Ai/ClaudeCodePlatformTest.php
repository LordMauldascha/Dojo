<?php

declare(strict_types=1);

namespace App\Tests\Ai;

use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class ClaudeCodePlatformTest extends KernelTestCase
{
    public function testPlatformIsWiredAndSupportsClaudeModels(): void
    {
        $platform = self::getContainer()->get(PlatformInterface::class);

        self::assertInstanceOf(Platform::class, $platform);
        self::assertSame('sonnet', $platform->getModelCatalog()->getModel('sonnet')->getName());
    }

    public function testCliChildDoesNotInheritAppSecretsOrOtherProviderKeys(): void
    {
        $container = new ContainerBuilder();
        (new YamlFileLoader($container, new FileLocator(\dirname(__DIR__, 2).'/config')))->load('services.yaml');
        $environment = $container->getDefinition(PlatformInterface::class)->getArgument('$environment');
        self::assertIsArray($environment);

        // Symfony Process drops a variable whose value is false from the inherited environment
        foreach (['DATABASE_URL', 'APP_SECRET', 'API_KEY_ENCRYPTION_KEY', 'POSTGRES_PASSWORD', 'MESSENGER_TRANSPORT_DSN', 'MAILER_DSN'] as $name) {
            self::assertFalse($environment[$name], $name);
        }
        foreach (['ANTHROPIC_API_KEY', 'CLAUDE_CODE_OAUTH_TOKEN'] as $name) {
            self::assertIsString($environment[$name], $name);
        }

        preg_match_all('~%env\((\w+)\)%~', (string) file_get_contents(\dirname(__DIR__, 2).'/config/packages/ai.yaml'), $matches);
        self::assertNotSame([], $matches[1]);
        foreach ($matches[1] as $name) {
            self::assertFalse($environment[$name], $name);
        }
    }
}
