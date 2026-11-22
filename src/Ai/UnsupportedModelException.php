<?php

declare(strict_types=1);

namespace App\Ai;

/** The provider does not know the model or cannot serve it as chat completion. */
final class UnsupportedModelException extends \RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('The provider cannot serve this model as chat completion.', 0, $previous);
    }
}
