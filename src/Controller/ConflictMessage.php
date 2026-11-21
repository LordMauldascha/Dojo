<?php

declare(strict_types=1);

namespace App\Controller;

/** Flash text for a write that lost against a concurrent request. */
final class ConflictMessage
{
    public const TEXT = 'The data was changed by someone else at the same time. Reload the page and try again.';
}
