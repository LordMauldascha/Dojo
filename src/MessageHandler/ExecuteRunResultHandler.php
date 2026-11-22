<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Ai\LlmGateway;
use App\Entity\RunResult;
use App\Message\ExecuteRunResult;
use App\Message\JudgeRunResult;
use App\Run\RunResultStatus;
use App\Run\TransientFailure;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Asks the configured model for one cell. Every write goes through the result's optimistic lock: when a sweeper or
 * another worker changed the result meanwhile, the handler stops without overwriting it and without a retry.
 */
#[AsMessageHandler]
final readonly class ExecuteRunResultHandler
{
    use GuardsRunResultWrites;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private LlmGateway $gateway,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(ExecuteRunResult $message): void
    {
        $this->guarded(fn () => $this->execute($message));
    }

    private function execute(ExecuteRunResult $message): void
    {
        $result = $this->entityManager->find(RunResult::class, $message->resultId);
        if (null === $result) {
            return;
        }
        $this->entityManager->refresh($result);
        if (RunResultStatus::Judging === $result->getStatus()) {
            // a redelivery after the judge dispatch failed; the judge handler skips duplicates
            $this->messageBus->dispatch(new JudgeRunResult($message->resultId));

            return;
        }
        if (!$result->markRunning() || !$this->flush()) {
            return;
        }

        try {
            $answer = $this->gateway->ask(
                $result->getConfiguration(),
                LlmGateway::systemPrompt($result->getRunVersion()->getSkillVersions()),
                $result->getTestcaseVersion()->getPrompt(),
            );
        } catch (\Throwable $error) {
            $code = TransientFailure::errorCode($error);
            if (TransientFailure::isTransient($error)) {
                if ($result->markPending() && $this->flush()) {
                    throw new TransientFailure($code);
                }

                return;
            }
            if ($result->markFailed($code, $result->getStatus()) && $this->flush()) {
                throw new UnrecoverableMessageHandlingException($code);
            }

            return;
        }

        if ($result->markJudging($answer->text, $answer->inputTokens, $answer->outputTokens, $answer->durationMs) && $this->flush()) {
            $this->messageBus->dispatch(new JudgeRunResult($message->resultId));
        }
    }
}
