<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\AskClaudeCommand;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Tester\CommandTester;

final class AskClaudeCommandTest extends KernelTestCase
{
    public function testDefaultsToHaikuWithMediumEffort(): void
    {
        $platform = $this->stubPlatform();

        $tester = $this->executeCommand($platform, ['prompt' => 'Hi']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('stub answer', $tester->getDisplay());
        self::assertSame('haiku', $platform->model);
        self::assertSame('Hi', $platform->input);
        self::assertSame(['effort' => 'medium', 'tools' => []], $platform->options);
    }

    public function testModelAndEffortCanBeOverridden(): void
    {
        $platform = $this->stubPlatform();

        $this->executeCommand($platform, ['prompt' => 'Hi', '--model' => 'opus', '--effort' => 'max']);

        self::assertSame('opus', $platform->model);
        self::assertSame('max', $platform->options['effort']);
    }

    public function testEmptyModelIsRejected(): void
    {
        $platform = $this->stubPlatform();

        $tester = $this->executeCommand($platform, ['prompt' => 'Hi', '--model' => '']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertSame('', $platform->model);
    }

    public function testInvalidEffortFails(): void
    {
        $this->expectException(InvalidOptionException::class);

        $this->executeCommand($this->stubPlatform(), ['prompt' => 'Hi', '--effort' => 'turbo']);
    }

    /**
     * @param array<string, string> $input
     */
    private function executeCommand(PlatformInterface $platform, array $input): CommandTester
    {
        $application = new Application(self::bootKernel());
        $application->addCommand(new AskClaudeCommand($platform));

        $tester = new CommandTester($application->find('app:ask-claude'));
        $tester->execute($input);

        return $tester;
    }

    private function stubPlatform(): StubPlatform
    {
        return new StubPlatform();
    }
}
