<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Run\TransientFailure;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * What the result handlers share: a flush that reports a lost optimistic lock instead of throwing, and a wrapper that
 * lets only sanitized exceptions leave the handler. The using class needs an `$entityManager` property.
 *
 * @property EntityManagerInterface $entityManager
 */
trait GuardsRunResultWrites
{
    /** @param \Closure(): void $work */
    private function guarded(\Closure $work): void
    {
        try {
            $work();
        } catch (TransientFailure|UnrecoverableMessageHandlingException $failure) {
            throw $failure;
        } catch (\Throwable $error) {
            // e.g. a database error or a failed dispatch: its message and trace arguments may hold the output
            throw new TransientFailure(TransientFailure::errorCode($error));
        }
    }

    /**
     * @return bool false if someone else changed the result since it was read (the entity manager is closed then)
     *
     * @phpstan-impure
     */
    private function flush(): bool
    {
        try {
            $this->entityManager->flush();
        } catch (OptimisticLockException) {
            return false;
        }

        return true;
    }
}
