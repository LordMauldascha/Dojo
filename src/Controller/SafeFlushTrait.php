<?php

declare(strict_types=1);

namespace App\Controller;

use App\Doctrine\SafeFlush;
use Doctrine\ORM\EntityManagerInterface;

/** For controllers extending AbstractController. */
trait SafeFlushTrait
{
    /**
     * Flushes and runs $after in one transaction. When a concurrent request won it adds an error flash and returns
     * false; the entity manager is closed then, so the caller must only redirect.
     */
    private function flushOrConflict(EntityManagerInterface $entityManager, ?\Closure $after = null): bool
    {
        if (SafeFlush::run($entityManager, $after)) {
            return true;
        }

        $this->addFlash('error', ConflictMessage::TEXT);

        return false;
    }
}
