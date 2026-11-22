<?php

declare(strict_types=1);

namespace App\Ai;

use Symfony\AI\Platform\Bridge\ClaudeCode\ClaudeCode;
use Symfony\AI\Platform\Bridge\ClaudeCode\ModelClient;
use Symfony\AI\Platform\Bridge\ClaudeCode\RawProcessResult;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * The bridge's model client (final), changed so no prompt reaches the process list: the prompt goes to stdin
 * (`-p` without an argument) and the system prompt to a 0600 temp file (`--system-prompt-file`). Nothing is logged,
 * and the CLI keeps no session on disk (`--no-session-persistence`) and loads no MCP servers (`--strict-mcp-config`).
 * A rejected credential surfaces as InvalidApiKeyException (see ClaudeCodeRawResult).
 */
final readonly class ClaudeCodeModelClient implements ModelClientInterface
{
    private ModelClient $commands;

    /** @param array<string, string|false> $environment false drops an inherited variable (Symfony Process) */
    public function __construct(
        private string $workingDirectory,
        private array $environment,
        private ?float $timeout = 300,
        ?string $cliBinary = null,
    ) {
        $this->commands = new ModelClient($cliBinary);
    }

    public function supports(Model $model): bool
    {
        return $model instanceof ClaudeCode;
    }

    public function request(Model $model, array|string $payload, array $options = []): RawResultInterface
    {
        $prompt = \is_string($payload) ? $payload : $payload['prompt'] ?? '';
        $systemPrompt = \is_array($payload) ? $payload['system_prompt'] ?? null : null;
        \assert(\is_string($prompt) && (null === $systemPrompt || \is_string($systemPrompt)));

        $systemPromptFile = null === $systemPrompt ? null : $this->writeTempFile($systemPrompt);
        try {
            $process = new Process($this->buildCommand($model, $options, $systemPromptFile), $this->workingDirectory, $this->environment, $prompt, $this->timeout);
            $process->start();
        } catch (\Throwable $e) {
            if (null !== $systemPromptFile) {
                (new Filesystem())->remove($systemPromptFile);
            }

            throw $e;
        }

        return new ClaudeCodeRawResult(new RawProcessResult($process), $process, $systemPromptFile);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return string[]
     */
    private function buildCommand(Model $model, array $options, ?string $systemPromptFile): array
    {
        unset($options['cwd'], $options['stream'], $options['system_prompt'], $options['system_prompt_file']);
        $options['model'] ??= $model->getName();
        // no transcript under $HOME/.claude/projects and no MCP servers from user or project settings
        $options['no_session_persistence'] = true;
        $options['strict_mcp_config'] = true;
        if (null !== $systemPromptFile) {
            $options['system_prompt_file'] = $systemPromptFile;
        }

        // the bridge ends the command with '-p', <prompt>; without the argument the CLI reads the prompt from stdin
        return [...\array_slice($this->commands->buildCommand('', $options), 0, -1)];
    }

    private function writeTempFile(string $content): string
    {
        // tempnam() creates the file with mode 0600; outside the working directory, which must stay empty
        $path = tempnam(sys_get_temp_dir(), 'claude-system-prompt-');
        if (false === $path || false === file_put_contents($path, $content)) {
            throw new \RuntimeException('Could not write the system prompt file.');
        }

        return $path;
    }
}
