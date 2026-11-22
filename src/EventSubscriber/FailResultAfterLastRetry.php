<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\RunResult;
use App\Message\ExecuteRunResult;
use App\Message\JudgeRunResult;
use App\Run\RunResultStatus;
use App\Run\TransientFailure;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

/** Fails the result once Messenger gives up on its message, so the version does not stay running forever. */
#[AsEventListener]
final readonly class FailResultAfterLastRetry
{
    public function __construct(private ManagerRegistry $doctrine)
    {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        $open = match (true) {
            $message instanceof ExecuteRunResult => [RunResultStatus::Pending, RunResultStatus::Running],
            $message instanceof JudgeRunResult => [RunResultStatus::Judging],
            default => [],
        };
        if ([] === $open || $event->willRetry()) {
            return;
        }

        $entityManager = $this->doctrine->getManagerForClass(RunResult::class);
        \assert($entityManager instanceof EntityManagerInterface);
        // a failed flush in the handler closes the entity manager; the worker only resets it after this event
        if (!$entityManager->isOpen()) {
            $entityManager = $this->doctrine->resetManager();
            \assert($entityManager instanceof EntityManagerInterface);
        }

        $result = $entityManager->find(RunResult::class, $message->resultId);
        if (null === $result) {
            return;
        }
        $entityManager->refresh($result);
        if (!\in_array($result->getStatus(), $open, true)) {
            return;
        }

        $error = $event->getThrowable();
        $error = $error instanceof HandlerFailedException ? ($error->getWrappedExceptions()[0] ?? $error) : $error;
        $result->markFailed(TransientFailure::errorCode($error), $result->getStatus());
        try {
            $entityManager->flush();
        } catch (OptimisticLockException) {
            // the sweeper or a handler finished the result meanwhile
        }
    }
}
