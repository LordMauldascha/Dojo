<?php

declare(strict_types=1);

namespace App\Run;

/** Where one cell (testcase x configuration) of a run version stands. */
enum RunResultStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Judging = 'judging';
    case Done = 'done';
    case Failed = 'failed';

    public function isOpen(): bool
    {
        return self::Done !== $this && self::Failed !== $this;
    }
}
