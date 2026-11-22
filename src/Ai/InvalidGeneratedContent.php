<?php

declare(strict_types=1);

namespace App\Ai;

/** Carries no message and no previous exception: both could hold the model's answer. */
final class InvalidGeneratedContent extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The AI helper answer is not usable.');
    }
}
