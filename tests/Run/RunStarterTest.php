<?php

declare(strict_types=1);

namespace App\Tests\Run;

use App\Ai\Credential;
use App\Ai\JudgeSettingStore;
use App\Ai\LlmConfiguration;
use App\Ai\ModelCatalog;
use App\Ai\Provider;
use App\Entity\HuggingFaceModel;
use App\Entity\ProviderApiKey;
use App\Entity\Run;
use App\Entity\RunResult;
use App\Message\ExecuteRunResult;
use App\Run\RunResultStatus;
use App\Run\RunStarter;
use App\Run\RunVersionStatus;
use App\Run\StaleResultSweeper;
use App\Tests\Support\AppWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class RunStarterTest extends AppWebTestCase
{
    public function testCheckCollectsEveryProblemInRuleOrder(): void
    {
        $run = $this->persistRun($this->persistProject('Security'));

        $check = $this->starter()->check($run);

        self::assertSame([RunStarter::NO_TESTCASE, RunStarter::NO_CONFIGURATION, RunStarter::NO_JUDGE], $check->problems);
        self::assertFalse($check->canStart());
        self::assertSame([0, 0], [$check->llmCalls, $check->judgeCalls]);
    }

    public function testCheckReportsMissingKeysOfConfigurationsAndJudge(): void
    {
        $run = $this->readyRun(3);
        $judges = static::getContainer()->get(JudgeSettingStore::class);
        $judges->save(new LlmConfiguration(Provider::ClaudeCode, 'opus'));

        self::assertSame([RunStarter::NO_USABLE_CONFIGURATION, RunStarter::JUDGE_WITHOUT_KEY], $this->starter()->check($run)->problems);

        $this->storeKey(Credential::Anthropic);
        $check = $this->starter()->check($run);
        self::assertSame([], $check->problems);
        self::assertTrue($check->canStart());
    }

    public function testCheckBlocksAJudgeModelThatIsNoLongerInTheCatalog(): void
    {
        $run = $this->readyRun(1);
        $this->storeKey(Credential::Anthropic);
        $this->storeKey(Credential::HuggingFace);
        $judges = static::getContainer()->get(JudgeSettingStore::class);

        // a model that was removed from the code list after the judge was saved
        $judges->save(new LlmConfiguration(Provider::ClaudeCode, 'retired-model'));
        self::assertSame([RunStarter::JUDGE_MODEL_UNAVAILABLE], $this->starter()->check($run)->problems);

        // a Hugging Face model the admin has not added (or deleted)
        $judges->save(new LlmConfiguration(Provider::HuggingFace, 'acme/judge-8b'));
        self::assertSame([RunStarter::JUDGE_MODEL_UNAVAILABLE], $this->starter()->check($run)->problems);

        $this->entityManager->persist(new HuggingFaceModel('acme/judge-8b'));
        $this->entityManager->flush();
        self::assertSame([], $this->starter()->check($run)->problems);
    }

    public function testCheckCountsCellsOfAvailableConfigurationsOnly(): void
    {
        $run = $this->readyRun(3);
        $run->addConfiguration(new LlmConfiguration(Provider::OpenAi, 'gpt-5.4'));
        $run->addConfiguration(new LlmConfiguration(Provider::Gemini, Provider::Gemini->models()[0]));
        $this->entityManager->flush();
        $this->storeJudge();
        $this->storeKey(Credential::OpenAi);

        $check = $this->starter()->check($run);

        self::assertSame([6, 6], [$check->llmCalls, $check->judgeCalls]);
    }

    public function testStartCreatesTheVersionAndDispatchesOneMessagePerResult(): void
    {
        $run = $this->readyRun(3);
        $this->storeJudge();

        $version = $this->starter()->start($run);

        $this->entityManager->clear();
        $stored = $this->entityManager->find(Run::class, $run->getId())?->findVersion($version->getNumber());
        self::assertSame(3, $stored?->getResults()->count());
        $expected = array_map(static fn (RunResult $result): ?int => $result->getId(), $stored->getResults()->toArray());
        $sent = [];
        foreach ($this->transport()->getSent() as $envelope) {
            $message = $envelope->getMessage();
            self::assertInstanceOf(ExecuteRunResult::class, $message);
            $sent[] = $message->resultId;
        }
        sort($expected);
        sort($sent);
        self::assertSame($expected, $sent);
    }

    public function testStartThrowsTheFirstProblemAndCreatesNothing(): void
    {
        $run = $this->readyRun(1);
        $this->storeKey(Credential::Anthropic);

        try {
            $this->starter()->start($run);
            self::fail('Expected a LogicException.');
        } catch (\LogicException $exception) {
            self::assertSame(RunStarter::NO_JUDGE, $exception->getMessage());
        }
        self::assertCount(0, $run->getVersions());
        self::assertSame([], $this->transport()->getSent());
    }

    public function testStartIsBlockedWhileAVersionIsOpenAndAllowedAfterwards(): void
    {
        $run = $this->readyRun(1);
        $this->storeJudge();
        $version = $this->starter()->start($run);

        self::assertSame([RunStarter::STILL_RUNNING], $this->starter()->check($run)->problems);
        try {
            $this->starter()->start($run);
            self::fail('Expected a LogicException.');
        } catch (\LogicException $exception) {
            self::assertSame(RunStarter::STILL_RUNNING, $exception->getMessage());
        }

        foreach ($version->getResults() as $result) {
            $result->markFailed('timeout', RunResultStatus::Pending);
        }
        self::assertSame(2, $this->starter()->start($run)->getNumber());
    }

    public function testStartSeesVersionsCreatedByAnotherRequestSinceTheRunWasLoaded(): void
    {
        $run = $this->readyRun(1);
        $this->storeJudge();
        self::assertCount(0, $run->getVersions());
        $connection = $this->entityManager->getConnection();
        $versionId = $connection->fetchOne(
            "INSERT INTO run_version (run_id, number, title, configurations, created_at, judge_provider, judge_model) VALUES (?, 1, 'x', '[]', now(), 'claude_code', 'opus') RETURNING id",
            [$run->getId()],
        );
        $connection->executeStatement(
            "INSERT INTO run_result (run_version_id, testcase_version_id, configuration, config_key, status, status_changed_at) SELECT ?, id, '{}', 'k', 'pending', now() FROM testcase_version LIMIT 1",
            [$versionId],
        );

        try {
            $this->starter()->start($run);
            self::fail('Expected a LogicException.');
        } catch (\LogicException $exception) {
            self::assertSame(RunStarter::STILL_RUNNING, $exception->getMessage());
        }
    }

    public function testSweeperFailsOnlyResultsStuckForMoreThanThirtyMinutes(): void
    {
        $run = $this->readyRun(3);
        $this->storeJudge();
        $version = $this->starter()->start($run);
        [$stuck, $judging, $recent] = array_values($version->getResults()->toArray());
        $stuck->markRunning();
        $judging->markRunning();
        $judging->markJudging('answer', null, null, 1);
        $recent->markRunning();
        $this->entityManager->flush();
        $this->ageStatus($stuck, 31);
        $this->ageStatus($judging, 45);
        $this->ageStatus($recent, 29);
        $this->entityManager->clear();

        $reloaded = $this->entityManager->find(Run::class, $run->getId());
        self::assertInstanceOf(Run::class, $reloaded);
        $sweeper = static::getContainer()->get(StaleResultSweeper::class);

        self::assertSame(2, $sweeper->sweep($reloaded));
        self::assertSame(0, $sweeper->sweep($reloaded));
        $this->entityManager->clear();
        $statuses = array_map(
            static fn (RunResult $result): string => $result->getStatus()->value.':'.$result->getError(),
            $this->entityManager->find(Run::class, $run->getId())?->findVersion(1)?->getResults()->toArray() ?? [],
        );
        sort($statuses);
        self::assertSame(['failed:timeout', 'failed:timeout', 'running:'], $statuses);
    }

    public function testSweeperAcceptsASingleVersion(): void
    {
        $run = $this->readyRun(1);
        $this->storeJudge();
        $version = $this->starter()->start($run);
        $result = $version->getResults()->first() ?: self::fail('no result');
        $result->markRunning();
        $this->entityManager->flush();
        $this->ageStatus($result, 31);
        $this->entityManager->clear();

        $reloaded = $this->entityManager->find(Run::class, $run->getId())?->findVersion(1);

        self::assertSame(1, static::getContainer()->get(StaleResultSweeper::class)->sweep($reloaded ?? self::fail('no version')));
        self::assertSame(RunVersionStatus::CompletedWithErrors, $reloaded->getStatus());
    }

    public function testDispatchFailureFailsOnlyTheUnqueuedCellsSoTheRunCanStartAgain(): void
    {
        $run = $this->readyRun(3);
        $this->storeJudge();
        $container = static::getContainer();
        // the first cell is queued and a worker (own connection, committed) takes it before the queue goes down
        $bus = new class($this->concurrentConnection()) implements MessageBusInterface {
            public ?int $taken = null;

            public function __construct(private readonly Connection $worker)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                \assert($message instanceof ExecuteRunResult);
                if (null !== $this->taken) {
                    throw new TransportException('queue down');
                }
                $this->taken = $message->resultId;
                $this->worker->executeStatement("UPDATE run_result SET status = 'running', version = version + 1 WHERE id = ?", [$message->resultId]);

                return new Envelope($message);
            }
        };
        $starter = new RunStarter(
            $container->get(ModelCatalog::class),
            $container->get(JudgeSettingStore::class),
            $container->get(StaleResultSweeper::class),
            $container->get(LockFactory::class),
            $bus,
            $this->entityManager,
        );

        try {
            $starter->start($run);
            self::fail('Expected a TransportException.');
        } catch (TransportException) {
        }

        self::assertTrue($this->entityManager->isOpen());
        $rows = $this->entityManager->getConnection()->fetchAllAssociative('SELECT id, status, error FROM run_result ORDER BY id');
        self::assertCount(3, $rows);
        foreach ($rows as $row) {
            self::assertSame($row['id'] === $bus->taken ? ['running', null] : ['failed', 'dispatch_failed'], [$row['status'], $row['error']]);
        }
        foreach ($run->getVersions()[0]?->getResults() ?? [] as $result) {
            self::assertSame($result->getId() === $bus->taken ? RunResultStatus::Running : RunResultStatus::Failed, $result->getStatus());
        }
    }

    public function testAnyDispatchErrorFailsTheUnqueuedCellsAndIsRethrown(): void
    {
        $run = $this->readyRun(2);
        $this->storeJudge();
        $container = static::getContainer();
        $bus = new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                throw new \RuntimeException('serializer failed');
            }
        };
        $starter = new RunStarter(
            $container->get(ModelCatalog::class),
            $container->get(JudgeSettingStore::class),
            $container->get(StaleResultSweeper::class),
            $container->get(LockFactory::class),
            $bus,
            $this->entityManager,
        );

        try {
            $starter->start($run);
            self::fail('Expected a RuntimeException.');
        } catch (\RuntimeException $error) {
            self::assertSame('serializer failed', $error->getMessage());
        }

        $rows = $this->entityManager->getConnection()->fetchAllAssociative('SELECT status, error FROM run_result');
        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertSame(['failed', 'dispatch_failed'], [$row['status'], $row['error']]);
        }
    }

    public function testSweeperDoesNotOverwriteAResultAWorkerFinishedMeanwhile(): void
    {
        $run = $this->readyRun(1);
        $this->storeJudge();
        $result = $this->starter()->start($run)->getResults()->first() ?: self::fail('no result');
        $result->markRunning();
        $this->entityManager->flush();
        $this->ageStatus($result, 31);
        $this->entityManager->clear();
        // the worker (own connection, committed) finishes the cell between the sweeper's read and its write
        $worker = $this->concurrentConnection();
        $this->entityManager->getEventManager()->addEventListener(Events::postLoad, new class($worker) {
            public function __construct(private readonly Connection $worker)
            {
            }

            public function postLoad(PostLoadEventArgs $args): void
            {
                if ($args->getObject() instanceof RunResult) {
                    $this->worker->executeStatement("UPDATE run_result SET status = 'done', score = 100, status_changed_at = now(), version = version + 1 WHERE id = ?", [$args->getObject()->getId()]);
                }
            }
        });
        $reloaded = $this->entityManager->find(Run::class, $run->getId()) ?? self::fail('run missing');

        self::assertSame(0, static::getContainer()->get(StaleResultSweeper::class)->sweep($reloaded));

        self::assertTrue($this->entityManager->isOpen());
        self::assertSame(['done', null], array_values($worker->fetchAssociative('SELECT status, error FROM run_result') ?: []));
        $swept = $reloaded->findVersion(1)?->getResults()->first();
        self::assertSame(RunResultStatus::Done, $swept ? $swept->getStatus() : null);
    }

    private function starter(): RunStarter
    {
        return static::getContainer()->get(RunStarter::class);
    }

    private function transport(): InMemoryTransport
    {
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function readyRun(int $testcases): Run
    {
        $project = $this->persistProject('Security');
        $cases = [];
        for ($index = 1; $index <= $testcases; ++$index) {
            $cases[] = $this->persistTestcase('Case '.$index, $project);
        }
        $this->entityManager->createQuery('DELETE FROM '.ProviderApiKey::class)->execute();

        return $this->persistRun($project, testcases: $cases, configured: true);
    }

    private function ageStatus(RunResult $result, int $minutes): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE run_result SET status_changed_at = ? WHERE id = ?',
            [(new \DateTimeImmutable())->modify(\sprintf('-%d minutes', $minutes))->format('Y-m-d H:i:s'), $result->getId()],
        );
    }
}
