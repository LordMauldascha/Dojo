<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Controller\SafeFlushTrait;
use Doctrine\ORM\EntityManagerInterface;

/** Stands in for a controller to test SafeFlushTrait on its own. */
final class SafeFlushDouble
{
    use SafeFlushTrait;

    /** @var list<array{string, string}> */
    public array $flashes = [];

    public function addFlash(string $type, string $message): void
    {
        $this->flashes[] = [$type, $message];
    }

    public function run(EntityManagerInterface $entityManager, ?\Closure $after = null): bool
    {
        return $this->flushOrConflict($entityManager, $after);
    }
}
