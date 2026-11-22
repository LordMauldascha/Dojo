<?php

declare(strict_types=1);

namespace App\Ai;

/** No credential of the provider is stored. */
final class MissingApiKeyException extends \RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('No API key stored for this provider.', 0, $previous);
    }
}
