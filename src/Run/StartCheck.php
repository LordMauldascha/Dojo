<?php

declare(strict_types=1);

namespace App\Run;

/** What blocks a start (empty when it may start) and how many calls it would make. */
final readonly class StartCheck
{
    /** @param list<string> $problems in the order of the start rules */
    public function __construct(public array $problems, public int $llmCalls, public int $judgeCalls)
    {
    }

    public function canStart(): bool
    {
        return [] === $this->problems;
    }
}
