<?php

declare(strict_types=1);

namespace App\Ai;

/** The provider rejected the stored credential (HTTP or CLI status 401). */
final class InvalidApiKeyException extends \RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('The provider rejected the stored API key.', 0, $previous);
    }
}
