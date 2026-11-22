<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Ai\Credential;
use App\Ai\LlmConfiguration;
use App\Ai\LlmGateway;
use App\Ai\MissingApiKeyException;
use App\Ai\PlatformProvider;
use App\Ai\Provider;
use App\Entity\RunResult;
use App\EventSubscriber\FailResultAfterLastRetry;
use App\Message\ExecuteRunResult;
use App\Message\JudgeRunResult;
use App\MessageHandler\ExecuteRunResultHandler;
use App\Run\TransientFailure;
use App\Tests\Ai\ScriptedPlatform;
use App\Tests\Support\AppWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\AI\Platform\Exception\BadRequestException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Exception\ServerException;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class ExecuteRunResultHandlerTest extends AppWebTestCase
{
    public function testSuccessStoresOutputAndDispatchesJudge(): void
    {
        $platform = ScriptedPlatform::answering('stub answer');
        $result = $this->pendingResult();

        $this->handler($platform)(new ExecuteRunResult($this->idOf($result)));

        $row = $this->row($result);
        self::assertSame('judging', $row['status']);
        self::assertSame('stub answer', $row['output']);
        self::assertNull($row['error']);
        self::assertIsInt($row['duration_ms']);
        $sent = $this->transport()->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(JudgeRunResult::class, $message);
        self::assertSame($this->idOf($result), $message->resultId);

        self::assertInstanceOf(MessageBag::class, $platform->input);
        self::assertSame('opus', $platform->model);
        self::assertSame('Synthetic prompt', $platform->input->getUserMessage()?->asText());
        self::assertStringEndsWith('Synthetic skill', (string) $platform->input->getSystemMessage()?->getContent());
    }

    public function testNonPendingResultIsSkipped(): void
    {
        $platform = ScriptedPlatform::answering('stub answer');
        $result = $this->pendingResult();
        $this->entityManager->getConnection()->executeStatement("UPDATE run_result SET status = 'done' WHERE id = ?", [$result->getId()]);

        $this->handler($platform)(new ExecuteRunResult($this->idOf($result)));

        self::assertSame(0, $platform->invocations);
        self::assertSame('done', $this->row($result)['status']);
        self::assertSame([], $this->transport()->getSent());
    }

    public function testRedeliveredMessageOfAJudgingResultDispatchesTheJudgeAgain(): void
    {
        $platform = ScriptedPlatform::answering('stub answer');
        $result = $this->pendingResult();
        $this->entityManager->getConnection()->executeStatement("UPDATE run_result SET status = 'judging', output = 'earlier answer' WHERE id = ?", [$result->getId()]);

        $this->handler($platform)(new ExecuteRunResult($this->idOf($result)));

        self::assertSame(0, $platform->invocations);
        $row = $this->row($result);
        self::assertSame(['judging', 'earlier answer'], [$row['status'], $row['output']]);
        $sent = $this->transport()->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(JudgeRunResult::class, $message);
        self::assertSame($this->idOf($result), $message->resultId);
    }

    public function testDeletedResultIsSkipped(): void
    {
        $platform = ScriptedPlatform::answering('stub answer');

        $this->handler($platform)(new ExecuteRunResult(2_000_000_000));

        self::assertSame(0, $platform->invocations);
    }

    public function testTransientErrorResetsToPendingAndRethrows(): void
    {
        $result = $this->pendingResult();
        $handler = $this->handler(ScriptedPlatform::failing(new ServerException(503, 'provider text sk-secret')));

        try {
            $handler(new ExecuteRunResult($this->idOf($result)));
            self::fail('Expected a TransientFailure.');
        } catch (TransientFailure $exception) {
            self::assertSame('ServerException 503', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }

        $row = $this->row($result);
        self::assertSame('pending', $row['status']);
        self::assertNull($row['error']);
        self::assertSame([], $this->transport()->getSent());

        // the retried message is not skipped by the idempotency guard
        $this->handler(ScriptedPlatform::answering('stub answer'))(new ExecuteRunResult($this->idOf($result)));
        self::assertSame('judging', $this->row($result)['status']);
    }

    public function testPermanentErrorFailsAndThrowsUnrecoverable(): void
    {
        $result = $this->pendingResult();
        $handler = $this->handler(ScriptedPlatform::failing(new \RuntimeException('model output must not be stored')));

        try {
            $handler(new ExecuteRunResult($this->idOf($result)));
            self::fail('Expected an UnrecoverableMessageHandlingException.');
        } catch (UnrecoverableMessageHandlingException $exception) {
            self::assertSame('RuntimeException', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }

        $row = $this->row($result);
        self::assertSame('failed', $row['status']);
        self::assertSame('RuntimeException', $row['error']);
        self::assertSame([], $this->transport()->getSent());
    }

    public function testMissingKeyFailsWithNoApiKey(): void
    {
        $result = $this->pendingResult();

        try {
            $this->handler(null)(new ExecuteRunResult($this->idOf($result)));
            self::fail('Expected an UnrecoverableMessageHandlingException.');
        } catch (UnrecoverableMessageHandlingException) {
        }

        self::assertSame(['failed', 'no_api_key'], [$this->row($result)['status'], $this->row($result)['error']]);
    }

    public function testUnsupportedHuggingFaceModelFailsWithUnsupportedModel(): void
    {
        $result = $this->pendingResult(new LlmConfiguration(Provider::HuggingFace, 'org/chat'));
        $badRequest = new BadRequestException('no provider serves this model');

        try {
            $this->handler(ScriptedPlatform::failing($badRequest))(new ExecuteRunResult($this->idOf($result)));
            self::fail('Expected an UnrecoverableMessageHandlingException.');
        } catch (UnrecoverableMessageHandlingException) {
        }

        self::assertSame(['failed', 'unsupported_model'], [$this->row($result)['status'], $this->row($result)['error']]);
    }

    public function testFailedJudgeDispatchThrowsOnlyTheErrorCode(): void
    {
        $result = $this->pendingResult();
        $bus = new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                throw new TransportException('queue down: stub answer');
            }
        };

        try {
            $this->handler(ScriptedPlatform::answering('stub answer'), $bus)(new ExecuteRunResult($this->idOf($result)));
            self::fail('Expected a TransientFailure.');
        } catch (TransientFailure $exception) {
            self::assertSame('TransportException', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
        self::assertSame('judging', $this->row($result)['status']);
    }

    public function testLateFinishDoesNotOverwriteTimedOutResult(): void
    {
        $result = $this->pendingResult();
        $connection = $this->entityManager->getConnection();
        $id = $this->idOf($result);
        // the sweeper times the cell out while the provider is still answering
        $platform = new ScriptedPlatform(static function () use ($connection, $id): ResultInterface {
            $connection->executeStatement("UPDATE run_result SET status = 'failed', error = 'timeout', version = version + 1 WHERE id = ?", [$id]);

            return new TextResult('late answer');
        });

        $this->handler($platform)(new ExecuteRunResult($id));

        $row = $this->row($result);
        self::assertSame(['failed', 'timeout', null], [$row['status'], $row['error'], $row['output']]);
        self::assertSame([], $this->transport()->getSent());
    }

    public function testConcurrentWorkerDoesNotCallTheProviderTwice(): void
    {
        $result = $this->pendingResult();
        $platform = ScriptedPlatform::answering('stub answer');
        $handler = $this->handler($platform);
        // a second worker (own connection, committed) takes the same cell between this worker's read and its write
        $connection = $this->concurrentConnection();
        $this->entityManager->getEventManager()->addEventListener(Events::preUpdate, new class($connection) {
            public function __construct(private readonly Connection $connection)
            {
            }

            public function preUpdate(PreUpdateEventArgs $args): void
            {
                if ($args->getObject() instanceof RunResult) {
                    $this->connection->executeStatement("UPDATE run_result SET status = 'running', version = version + 1 WHERE id = ?", [$args->getObject()->getId()]);
                }
            }
        });

        $handler(new ExecuteRunResult($this->idOf($result)));

        self::assertSame(0, $platform->invocations);
        self::assertSame('running', $this->row($result)['status']);
    }

    public function testLastRetryMarksFailed(): void
    {
        $listeners = static::getContainer()->get(EventDispatcherInterface::class)->getListeners(WorkerMessageFailedEvent::class);
        self::assertContains(FailResultAfterLastRetry::class, array_map(static fn (mixed $listener): string => \is_array($listener) && \is_object($listener[0]) ? $listener[0]::class : '', $listeners));

        $result = $this->pendingResult();
        $listener = new FailResultAfterLastRetry(static::getContainer()->get(ManagerRegistry::class));
        $envelope = new Envelope(new ExecuteRunResult($this->idOf($result)));
        $error = new HandlerFailedException($envelope, [new TransientFailure('RateLimitExceededException')]);

        $retrying = new WorkerMessageFailedEvent($envelope, 'async', $error);
        $retrying->setForRetry();
        $listener($retrying);
        self::assertSame('pending', $this->row($result)['status']);

        $listener(new WorkerMessageFailedEvent($envelope, 'async', $error));
        self::assertSame(['failed', 'RateLimitExceededException'], [$this->row($result)['status'], $this->row($result)['error']]);

        // a finished result stays as it is
        $listener(new WorkerMessageFailedEvent($envelope, 'async', new HandlerFailedException($envelope, [new \LogicException('x')])));
        self::assertSame('RateLimitExceededException', $this->row($result)['error']);
    }

    public function testLastJudgeRetryFailsOnlyAJudgingResult(): void
    {
        $result = $this->pendingResult();
        $listener = new FailResultAfterLastRetry(static::getContainer()->get(ManagerRegistry::class));
        $envelope = new Envelope(new JudgeRunResult($this->idOf($result)));

        $listener(new WorkerMessageFailedEvent($envelope, 'async', new HandlerFailedException($envelope, [new \LogicException('x')])));
        self::assertSame('pending', $this->row($result)['status']);

        $this->entityManager->getConnection()->executeStatement("UPDATE run_result SET status = 'judging' WHERE id = ?", [$result->getId()]);
        $listener(new WorkerMessageFailedEvent($envelope, 'async', new HandlerFailedException($envelope, [new \LogicException('x')])));
        self::assertSame(['failed', 'LogicException'], [$this->row($result)['status'], $this->row($result)['error']]);
    }

    public function testTransientClassification(): void
    {
        self::assertTrue(TransientFailure::isTransient(new ServerException(502)));
        self::assertTrue(TransientFailure::isTransient(new RateLimitExceededException()));
        self::assertTrue(TransientFailure::isTransient(new ProcessTimedOutException(new Process(['true']), ProcessTimedOutException::TYPE_GENERAL)));
        self::assertTrue(TransientFailure::isTransient(new \Symfony\Component\HttpClient\Exception\TimeoutException('t')));
        self::assertTrue(TransientFailure::isTransient(new \Symfony\Component\HttpClient\Exception\TransportException('t')));
        self::assertFalse(TransientFailure::isTransient(new BadRequestException('x')));
        self::assertFalse(TransientFailure::isTransient(new MissingApiKeyException()));
        self::assertFalse(TransientFailure::isTransient(new \RuntimeException('x')));

        self::assertSame('RateLimitExceededException 429', TransientFailure::errorCode(new RateLimitExceededException()));
        self::assertSame('ServerException', TransientFailure::errorCode(new ServerException()));
        self::assertSame('no_api_key', TransientFailure::errorCode(new MissingApiKeyException()));
        self::assertSame('ServerException 500', TransientFailure::errorCode(new TransientFailure('ServerException 500')));
    }

    private function pendingResult(?LlmConfiguration $configuration = null): RunResult
    {
        $project = $this->persistProject('Security');
        $skill = $this->persistSkill('Style', [$project], content: 'Synthetic skill');
        $testcase = $this->persistTestcase('Case', $project, prompt: 'Synthetic prompt');
        $run = $this->persistRun($project, skills: [$skill], testcases: [$testcase], configured: null === $configuration);
        if (null !== $configuration) {
            $run->addConfiguration($configuration);
        }
        $version = $this->startRun($run);
        $this->entityManager->flush();

        return $version->getResults()->first() ?: self::fail('no result');
    }

    private function handler(?ScriptedPlatform $platform, ?MessageBusInterface $bus = null): ExecuteRunResultHandler
    {
        $this->entityManager->clear();

        return new ExecuteRunResultHandler(
            $this->entityManager,
            new LlmGateway(new class($platform) implements PlatformProvider {
                public function __construct(private readonly ?ScriptedPlatform $platform)
                {
                }

                public function forCredential(Credential $credential): PlatformInterface
                {
                    return $this->platform ?? throw new MissingApiKeyException();
                }
            }),
            $bus ?? static::getContainer()->get(MessageBusInterface::class),
        );
    }

    /**
     * @return array<string, mixed>
     *
     * @phpstan-impure
     */
    private function row(RunResult $result): array
    {
        return $this->entityManager->getConnection()->fetchAssociative('SELECT * FROM run_result WHERE id = ?', [$result->getId()]) ?: self::fail('row missing');
    }

    private function idOf(RunResult $result): int
    {
        return $result->getId() ?? self::fail('not stored');
    }

    private function transport(): InMemoryTransport
    {
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
