<?php

declare(strict_types=1);

namespace App\Versioning;

/** What the shared version workflow needs from a versioned entity (skills and testcases). */
interface VersionedInterface
{
    public function getDraft(): ?VersionInterface;

    public function findVersion(int $number): ?VersionInterface;

    /** Copies an old final version as a new final version. */
    public function restore(VersionInterface $version): VersionInterface;

    public function discardDraft(): void;
}
