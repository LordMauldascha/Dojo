<?php

declare(strict_types=1);

namespace App\Command;

use App\Ai\Effort;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:ask-claude', description: 'Send a prompt to the Claude Code platform')]
final readonly class AskClaudeCommand
{
    public function __construct(private PlatformInterface $platform)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The prompt to send')]
        string $prompt,
        #[Option('Model alias', shortcut: 'm', suggestedValues: ['haiku', 'sonnet', 'opus'])]
        string $model = 'haiku',
        #[Option('Effort level')]
        Effort $effort = Effort::Medium,
    ): int {
        // an empty tool list disables the CLI's built-in tools, so it only generates text
        $result = $this->platform->invoke($model, $prompt, ['effort' => $effort->value, 'tools' => []]);

        $io->writeln($result->asText());

        return Command::SUCCESS;
    }
}
