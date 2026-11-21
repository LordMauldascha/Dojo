<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;

/** For controllers extending AbstractController. */
trait SafeFlushTrait
{
    /**
     * Flushes and runs $after in one transaction. When a concurrent request won (unique or foreign key violation, stale optimistic lock) it adds an
     * error flash and returns false; the entity manager is closed then, so the caller must only redirect.
     */
    private function flushOrConflict(EntityManagerInterface $entityManager, ?\Closure $after = null): bool
    {
        try {
            $entityManager->wrapInTransaction(static function () use ($entityManager, $after): void {
                $entityManager->flush();
                if (null !== $after) {
                    $after();
                }
            });
        } catch (UniqueConstraintViolationException|ForeignKeyConstraintViolationException|OptimisticLockException) {
            $this->addFlash('error', ConflictMessage::TEXT);

            return false;
        }

        return true;
    }
}
