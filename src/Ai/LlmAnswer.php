<?php

declare(strict_types=1);

namespace App\Ai;

final readonly class LlmAnswer
{
    public function __construct(
        public string $text,
        public ?int $inputTokens,
        public ?int $outputTokens,
        public int $durationMs,
    ) {
    }
}
