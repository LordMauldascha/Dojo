<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;

final class SafeFlush
{
    /**
     * Flushes and runs $after in one transaction. When a concurrent request won (unique or foreign key violation, stale
     * optimistic lock) it returns false; the entity manager is closed then, so the caller must stop using it.
     */
    public static function run(EntityManagerInterface $entityManager, ?\Closure $after = null): bool
    {
        try {
            $entityManager->wrapInTransaction(static function () use ($entityManager, $after): void {
                $entityManager->flush();
                if (null !== $after) {
                    $after();
                }
            });
        } catch (UniqueConstraintViolationException|ForeignKeyConstraintViolationException|OptimisticLockException) {
            return false;
        }

        return true;
    }
}
