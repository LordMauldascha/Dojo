<?php

declare(strict_types=1);

namespace App\Run;

/** Derived from the results of a run version, never stored. */
enum RunVersionStatus: string
{
    case NotExecuted = 'not_executed';
    case Running = 'running';
    case Completed = 'completed';
    case CompletedWithErrors = 'completed_with_errors';
}
