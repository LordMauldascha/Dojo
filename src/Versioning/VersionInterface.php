<?php

declare(strict_types=1);

namespace App\Versioning;

interface VersionInterface
{
    public function getNumber(): int;

    public function isDraft(): bool;
}
