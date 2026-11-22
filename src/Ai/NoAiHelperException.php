<?php

declare(strict_types=1);

namespace App\Ai;

/** No AI helper is set, or its model is no longer offered. */
final class NoAiHelperException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('No AI helper is set.');
    }
}
