<?php

declare(strict_types=1);

namespace App\Run;

final readonly class StartRunPayload
{
    public function __construct(public bool $baseline = false)
    {
    }
}
