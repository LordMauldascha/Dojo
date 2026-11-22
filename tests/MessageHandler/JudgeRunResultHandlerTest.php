<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Ai\Credential;
use App\Ai\JudgeSettingStore;
use App\Ai\LlmConfiguration;
use App\Ai\LlmGateway;
use App\Ai\MissingApiKeyException;
use App\Ai\PlatformProvider;
use App\Ai\Provider;
use App\Entity\RunResult;
use App\Message\JudgeRunResult;
use App\MessageHandler\JudgeRunResultHandler;
use App\Run\Judge\JudgeAnswerParser;
use App\Run\TransientFailure;
use App\Tests\Ai\ScriptedPlatform;
use App\Tests\Support\AppWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\AI\Platform\Exception\ServerException;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final class JudgeRunResultHandlerTest extends AppWebTestCase
{
    private const THREE_CRITERIA = "first\nsecond\nthird";

    public function testVerdictsAreStoredAndScoreComputed(): void
    {
        $platform = ScriptedPlatform::answering('[{"index": 1, "met": true, "reason": "ok 1"}, {"index": 2, "met": false, "reason": "bad 2"}, {"index": 3, "met": true, "reason": "ok 3"}]');
        $result = $this->judgingResult(self::THREE_CRITERIA);

        $this->handler($platform)(new JudgeRunResult($this->idOf($result)));

        $row = $this->row($result);
        self::assertSame(['done', 67, null, 'stub output'], [$row['status'], $row['score'], $row['error'], $row['output']]);
        self::assertSame(
            [
                ['criterion' => 'first', 'met' => true, 'reason' => 'ok 1'],
                ['criterion' => 'second', 'met' => false, 'reason' => 'bad 2'],
                ['criterion' => 'third', 'met' => true, 'reason' => 'ok 3'],
            ],
            json_decode(\is_string($row['criteria']) ? $row['criteria'] : '', true),
        );
        self::assertSame(1, $platform->invocations);
        self::assertInstanceOf(MessageBag::class, $platform->input);
        self::assertNull($platform->input->getSystemMessage());
        self::assertArrayNotHasKey('effort', $platform->options);
        self::assertStringContainsString('stub output', (string) $platform->input->getUserMessage()?->asText());
        self::assertStringContainsString("1. first\n2. second\n3. third", (string) $platform->input->getUserMessage()?->asText());
    }

    public function testInvalidAnswerIsRetriedOnceThenFails(): void
    {
        $platform = ScriptedPlatform::answering('not json, mentions stub output');
        $result = $this->judgingResult(self::THREE_CRITERIA);

        try {
            $this->handler($platform)(new JudgeRunResult($this->idOf($result)));
            self::fail('Expected an UnrecoverableMessageHandlingException.');
        } catch (UnrecoverableMessageHandlingException $exception) {
            self::assertSame('invalid_judge_answer', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }

        self::assertSame(2, $platform->invocations);
        $row = $this->row($result);
        self::assertSame(['failed', 'invalid_judge_answer', 'stub output', null], [$row['status'], $row['error'], $row['output'], $row['criteria']]);
    }

    public function testSecondAttemptMayRepairAnInvalidAnswer(): void
    {
        $calls = 0;
        $platform = new ScriptedPlatform(static function () use (&$calls): ResultInterface {
            return new TextResult(1 === ++$calls ? 'oops' : '[{"index": 1, "met": true, "reason": "fine"}]');
        });
        $result = $this->judgingResult('only');

        $this->handler($platform)(new JudgeRunResult($this->idOf($result)));

        self::assertSame(2, $platform->invocations);
        self::assertSame(['done', 100], [$this->row($result)['status'], $this->row($result)['score']]);
    }

    public function testNoCriteriaFailsWithoutJudgeCall(): void
    {
        $platform = ScriptedPlatform::answering('[]');
        $result = $this->judgingResult("\n  \n");

        try {
            $this->handler($platform)(new JudgeRunResult($this->idOf($result)));
            self::fail('Expected an UnrecoverableMessageHandlingException.');
        } catch (UnrecoverableMessageHandlingException $exception) {
            self::assertSame('no_criteria', $exception->getMessage());
        }

        self::assertSame(0, $platform->invocations);
        self::assertSame(['failed', 'no_criteria'], [$this->row($result)['status'], $this->row($result)['error']]);
    }

    public function testVersionWithoutJudgeFailsWithNoJudge(): void
    {
        $platform = ScriptedPlatform::answering('[]');
        $result = $this->judgingResult('one');
        $this->entityManager->getConnection()->executeStatement('UPDATE run_version SET judge_provider = NULL, judge_model = NULL');

        try {
            $this->handler($platform)(new JudgeRunResult($this->idOf($result)));
            self::fail('Expected an UnrecoverableMessageHandlingException.');
        } catch (UnrecoverableMessageHandlingException $exception) {
            self::assertSame('no_judge', $exception->getMessage());
        }

        self::assertSame(0, $platform->invocations);
        self::assertSame(['failed', 'no_judge'], [$this->row($result)['status'], $this->row($result)['error']]);
    }

    public function testUsesJudgeOfTheVersionNotTheCurrentSetting(): void
    {
        $platform = ScriptedPlatform::answering('[{"index": 1, "met": true, "reason": "fine"}]');
        $result = $this->judgingResult('one');
        $this->storeKey(Credential::OpenAi);
        static::getContainer()->get(JudgeSettingStore::class)->save(new LlmConfiguration(Provider::OpenAi, 'gpt-5.5'));

        $this->handler($platform)(new JudgeRunResult($this->idOf($result)));

        self::assertSame('opus', $platform->model);
        self::assertSame('done', $this->row($result)['status']);
    }

    public function testNonJudgingResultIsSkipped(): void
    {
        $platform = ScriptedPlatform::answering('[{"index": 1, "met": true, "reason": "fine"}]');
        $result = $this->judgingResult('one');
        $this->entityManager->getConnection()->executeStatement("UPDATE run_result SET status = 'failed', error = 'timeout' WHERE id = ?", [$result->getId()]);

        $this->handler($platform)(new JudgeRunResult($this->idOf($result)));

        self::assertSame(0, $platform->invocations);
        self::assertSame(['failed', 'timeout'], [$this->row($result)['status'], $this->row($result)['error']]);
    }

    public function testDeletedResultIsSkipped(): void
    {
        $platform = ScriptedPlatform::answering('[]');

        $this->handler($platform)(new JudgeRunResult(2_000_000_000));

        self::assertSame(0, $platform->invocations);
    }

    public function testTransientErrorKeepsJudgingAndRethrowsOnlyTheCode(): void
    {
        $result = $this->judgingResult('one');
        $handler = $this->handler(ScriptedPlatform::failing(new ServerException(503, 'provider text stub output')));

        try {
            $handler(new JudgeRunResult($this->idOf($result)));
            self::fail('Expected a TransientFailure.');
        } catch (TransientFailure $exception) {
            self::assertSame('ServerException 503', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }

        $row = $this->row($result);
        self::assertSame(['judging', null], [$row['status'], $row['error']]);

        // the retried message is not skipped
        $this->handler(ScriptedPlatform::answering('[{"index": 1, "met": true, "reason": "fine"}]'))(new JudgeRunResult($this->idOf($result)));
        self::assertSame('done', $this->row($result)['status']);
    }

    public function testPermanentErrorFailsAndThrowsUnrecoverable(): void
    {
        $result = $this->judgingResult('one');
        $handler = $this->handler(ScriptedPlatform::failing(new \RuntimeException('stub output must not be stored')));

        try {
            $handler(new JudgeRunResult($this->idOf($result)));
            self::fail('Expected an UnrecoverableMessageHandlingException.');
        } catch (UnrecoverableMessageHandlingException $exception) {
            self::assertSame('RuntimeException', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }

        $row = $this->row($result);
        self::assertSame(['failed', 'RuntimeException', 'stub output'], [$row['status'], $row['error'], $row['output']]);
    }

    public function testMissingKeyFailsWithNoApiKey(): void
    {
        $result = $this->judgingResult('one');

        try {
            $this->handler(null)(new JudgeRunResult($this->idOf($result)));
            self::fail('Expected an UnrecoverableMessageHandlingException.');
        } catch (UnrecoverableMessageHandlingException) {
        }

        self::assertSame(['failed', 'no_api_key'], [$this->row($result)['status'], $this->row($result)['error']]);
    }

    public function testLateVerdictDoesNotOverwriteTimedOutResult(): void
    {
        $result = $this->judgingResult('one');
        $connection = $this->entityManager->getConnection();
        $id = $this->idOf($result);
        // the sweeper times the cell out while the judge is still answering
        $platform = new ScriptedPlatform(static function () use ($connection, $id): ResultInterface {
            $connection->executeStatement("UPDATE run_result SET status = 'failed', error = 'timeout', version = version + 1 WHERE id = ?", [$id]);

            return new TextResult('[{"index": 1, "met": true, "reason": "late"}]');
        });

        $this->handler($platform)(new JudgeRunResult($id));

        $row = $this->row($result);
        self::assertSame(['failed', 'timeout', null], [$row['status'], $row['error'], $row['criteria']]);
    }

    public function testConcurrentWorkerDoesNotOverwriteTheVerdict(): void
    {
        $result = $this->judgingResult('one');
        $platform = ScriptedPlatform::answering('[{"index": 1, "met": true, "reason": "mine"}]');
        $handler = $this->handler($platform);
        $connection = $this->concurrentConnection();
        $this->entityManager->getEventManager()->addEventListener(Events::preUpdate, new class($connection) {
            public function __construct(private readonly Connection $connection)
            {
            }

            public function preUpdate(PreUpdateEventArgs $args): void
            {
                if ($args->getObject() instanceof RunResult) {
                    $this->connection->executeStatement('UPDATE run_result SET score = 0, version = version + 1 WHERE id = ?', [$args->getObject()->getId()]);
                }
            }
        });

        $handler(new JudgeRunResult($this->idOf($result)));

        self::assertSame(0, $this->row($result)['score']);
    }

    private function judgingResult(string $outputCriteria): RunResult
    {
        $project = $this->persistProject('Security');
        $testcase = $this->persistTestcase('Case', $project, prompt: 'Synthetic prompt', outputCriteria: $outputCriteria);
        $run = $this->persistRun($project, testcases: [$testcase], configured: true);
        $version = $this->startRun($run);
        $this->entityManager->flush();
        $result = $version->getResults()->first() ?: self::fail('no result');
        $this->entityManager->getConnection()->executeStatement("UPDATE run_result SET status = 'judging', output = 'stub output' WHERE id = ?", [$result->getId()]);

        return $result;
    }

    private function handler(?ScriptedPlatform $platform): JudgeRunResultHandler
    {
        $this->entityManager->clear();

        return new JudgeRunResultHandler(
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
            static::getContainer()->get(JudgeAnswerParser::class),
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
}
