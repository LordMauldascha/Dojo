<?php

declare(strict_types=1);

namespace App\Run;

/** What blocks a start (empty when it may start) and how many calls it would make. */
final readonly class StartCheck
{
    /**
     * @param list<string> $problems          in the order of the start rules
     * @param bool         $baselineAvailable the run has no version yet and skills to start with, so a baseline without skills can run first
     */
    public function __construct(public array $problems, public int $llmCalls, public int $judgeCalls, public bool $baselineAvailable = false)
    {
    }

    public function canStart(): bool
    {
        return [] === $this->problems;
    }
}
