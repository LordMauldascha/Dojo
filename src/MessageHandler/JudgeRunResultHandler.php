<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Ai\LlmGateway;
use App\Entity\RunResult;
use App\Message\JudgeRunResult;
use App\Run\Judge\CriteriaSplitter;
use App\Run\Judge\CriterionVerdict;
use App\Run\Judge\InvalidJudgeAnswer;
use App\Run\Judge\JudgeAnswerParser;
use App\Run\Judge\JudgePrompt;
use App\Run\RunResultStatus;
use App\Run\TransientFailure;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Rates the stored output of one cell with the judge of its run version. Like ExecuteRunResultHandler every write goes
 * through the result's optimistic lock, and only error codes leave the handler.
 */
#[AsMessageHandler]
final readonly class JudgeRunResultHandler
{
    use GuardsRunResultWrites;

    private const ATTEMPTS = 2;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private LlmGateway $gateway,
        private JudgeAnswerParser $parser,
    ) {
    }

    public function __invoke(JudgeRunResult $message): void
    {
        $this->guarded(fn () => $this->judge($message));
    }

    private function judge(JudgeRunResult $message): void
    {
        $result = $this->entityManager->find(RunResult::class, $message->resultId);
        if (null === $result) {
            return;
        }
        $this->entityManager->refresh($result);
        if (RunResultStatus::Judging !== $result->getStatus()) {
            return;
        }

        $judge = $result->getRunVersion()->getJudge();
        if (null === $judge) {
            $this->fail($result, 'no_judge');

            return;
        }
        $criteria = CriteriaSplitter::split($result->getTestcaseVersion()->getOutputCriteria());
        if ([] === $criteria) {
            $this->fail($result, 'no_criteria');

            return;
        }

        $prompt = JudgePrompt::build($criteria, $result->getOutput() ?? '');
        $verdicts = null;
        for ($attempt = 1; null === $verdicts && self::ATTEMPTS >= $attempt; ++$attempt) {
            try {
                $answer = $this->gateway->ask($judge, null, $prompt);
            } catch (\Throwable $error) {
                $code = TransientFailure::errorCode($error);
                if (TransientFailure::isTransient($error)) {
                    // the result stays judging, so the retried message is not skipped
                    throw new TransientFailure($code);
                }
                $this->fail($result, $code);

                return;
            }

            try {
                $verdicts = $this->parser->parse($answer->text, \count($criteria));
            } catch (InvalidJudgeAnswer) {
            }
        }
        if (null === $verdicts) {
            $this->fail($result, 'invalid_judge_answer');

            return;
        }

        $result->markDone(array_map(
            static fn (CriterionVerdict $verdict): array => ['criterion' => $criteria[$verdict->index - 1], 'met' => $verdict->met, 'reason' => $verdict->reason],
            $verdicts,
        ));
        $this->flush();
    }

    private function fail(RunResult $result, string $code): void
    {
        if ($result->markFailed($code, RunResultStatus::Judging) && $this->flush()) {
            throw new UnrecoverableMessageHandlingException($code);
        }
    }
}
