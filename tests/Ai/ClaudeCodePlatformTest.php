<?php

declare(strict_types=1);

namespace App\Tests\Ai;

use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ClaudeCodePlatformTest extends KernelTestCase
{
    public function testPlatformIsWiredAndSupportsClaudeModels(): void
    {
        $platform = self::getContainer()->get(PlatformInterface::class);

        self::assertInstanceOf(Platform::class, $platform);
        self::assertSame('sonnet', $platform->getModelCatalog()->getModel('sonnet')->getName());
    }
}
