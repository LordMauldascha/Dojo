<?php

declare(strict_types=1);

namespace App\Ai;

use Symfony\AI\Platform\Bridge\ClaudeCode\RawProcessResult;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Exception\ServerException;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * The bridge reports a rejected key only as the result subtype ("success"); the final `type=result` line carries
 * `api_error_status` 401 (observed with an invalid key on 21.11.2026); 429 and 5xx become the retryable exceptions of
 * the HTTP bridges. Removes the system prompt file once the process has ended, in every case.
 */
final readonly class ClaudeCodeRawResult implements RawResultInterface
{
    public function __construct(
        private RawProcessResult $result,
        private Process $process,
        private ?string $systemPromptFile = null,
    ) {
    }

    public function __destruct()
    {
        // the result may be dropped unread; stop the child before its input file disappears
        $this->process->stop(0);
        $this->removeSystemPromptFile();
    }

    public function getData(): array
    {
        try {
            $this->process->wait();
        } finally {
            $this->removeSystemPromptFile();
        }

        $status = $this->lastResultLine()['api_error_status'] ?? null;
        if (401 === $status) {
            throw new InvalidApiKeyException();
        }
        // the same exceptions the HTTP bridges throw, so rate limits and server errors are retried
        if (429 === $status) {
            throw new RateLimitExceededException();
        }
        if (\is_int($status) && 500 <= $status) {
            throw new ServerException($status);
        }

        return $this->result->getData();
    }

    public function getDataStream(): \Generator
    {
        try {
            yield from $this->result->getDataStream();
        } finally {
            $this->removeSystemPromptFile();
        }
    }

    public function getObject(): Process
    {
        return $this->process;
    }

    /** @return array<mixed> */
    private function lastResultLine(): array
    {
        foreach (array_reverse(explode(\PHP_EOL, $this->process->getOutput())) as $line) {
            $decoded = json_decode(trim($line), true);
            if (\is_array($decoded) && 'result' === ($decoded['type'] ?? null)) {
                return $decoded;
            }
        }

        return [];
    }

    private function removeSystemPromptFile(): void
    {
        if (null !== $this->systemPromptFile) {
            (new Filesystem())->remove($this->systemPromptFile);
        }
    }
}
