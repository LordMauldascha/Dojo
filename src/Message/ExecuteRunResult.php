<?php

declare(strict_types=1);

namespace App\Message;

/** Carries only the id: prompts, skill content and outputs must never travel through the queue. */
final readonly class ExecuteRunResult
{
    public function __construct(public int $resultId)
    {
    }
}
