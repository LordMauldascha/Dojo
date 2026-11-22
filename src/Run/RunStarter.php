<?php

declare(strict_types=1);

namespace App\Run;

use App\Ai\JudgeSettingStore;
use App\Ai\LlmConfiguration;
use App\Ai\ModelCatalog;
use App\Entity\Run;
use App\Entity\RunResult;
use App\Entity\RunVersion;
use App\Message\ExecuteRunResult;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\MessageBusInterface;

/** Applies the start rules, creates the version and queues its cells. */
final readonly class RunStarter
{
    public const NO_TESTCASE = 'A run needs at least one testcase to start.';
    public const NO_CONFIGURATION = 'A run needs at least one LLM configuration to start.';
    public const NO_USABLE_CONFIGURATION = 'No LLM configuration has an API key.';
    public const NO_JUDGE = 'No LLM judge is set.';
    public const JUDGE_WITHOUT_KEY = 'The LLM judge has no API key.';
    public const JUDGE_MODEL_UNAVAILABLE = 'The LLM judge model is no longer available.';
    public const STILL_RUNNING = 'A version of this run is still running.';
    public const BASELINE_NOT_AVAILABLE = 'A baseline is only possible for the first version of a run with skills.';
    public const DISPATCH_FAILED = 'The run could not be queued. Please start it again.';

    public function __construct(
        private ModelCatalog $catalog,
        private JudgeSettingStore $judgeSetting,
        private StaleResultSweeper $sweeper,
        private LockFactory $lockFactory,
        private MessageBusInterface $messageBus,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** Sweeps stuck results first, so a crashed worker cannot block the run forever. */
    public function check(Run $run): StartCheck
    {
        $this->sweeper->sweep($run);

        $problems = [];
        $testcases = \count($run->getStartableTestcases());
        $usable = array_filter($run->getConfigurations(), fn (LlmConfiguration $configuration): bool => $this->catalog->isAvailable($configuration->provider));
        if (0 === $testcases) {
            $problems[] = self::NO_TESTCASE;
        }
        if ([] === $run->getConfigurations()) {
            $problems[] = self::NO_CONFIGURATION;
        } elseif ([] === $usable) {
            $problems[] = self::NO_USABLE_CONFIGURATION;
        }
        $judge = $this->judgeSetting->get();
        if (null === $judge) {
            $problems[] = self::NO_JUDGE;
        } elseif (!$this->catalog->isAvailable($judge->provider)) {
            $problems[] = self::JUDGE_WITHOUT_KEY;
        } elseif (!\in_array($judge->model, $this->catalog->models($judge->provider), true)) {
            // otherwise every cell pays for its LLM call and then fails at the judge with unsupported_model
            $problems[] = self::JUDGE_MODEL_UNAVAILABLE;
        }
        foreach ($run->getVersions() as $version) {
            if (RunVersionStatus::Running === $version->getStatus()) {
                $problems[] = self::STILL_RUNNING;
                break;
            }
        }

        $cells = $testcases * \count($usable);

        $baselineAvailable = $run->getVersions()->isEmpty() && [] !== $run->getStartableSkills();

        return new StartCheck($problems, $cells, $cells, $baselineAvailable);
    }

    /**
     * With $withBaseline version N is a baseline without skills and version N + 1, the one returned, runs with them; both are queued by this one start.
     *
     * @throws \LogicException                                                                                   with the first blocking problem
     * @throws UniqueConstraintViolationException|ForeignKeyConstraintViolationException|OptimisticLockException when a concurrent request changed the run meanwhile
     * @throws \Throwable                                                                                        when queueing failed (any error); the unqueued cells are failed with `dispatch_failed`
     */
    public function start(Run $run, bool $withBaseline = false): RunVersion
    {
        $lock = $this->lockFactory->createLock('run-start-'.$run->getId());
        $lock->acquire(true);
        try {
            // A request that waited for the lock must see the version the winner created.
            $this->entityManager->refresh($run);
            $check = $this->check($run);
            if (!$check->canStart()) {
                throw new \LogicException($check->problems[0]);
            }
            if ($withBaseline && !$check->baselineAvailable) {
                throw new \LogicException(self::BASELINE_NOT_AVAILABLE);
            }

            $judge = $this->judgeSetting->get() ?? throw new \LogicException(self::NO_JUDGE);
            $baseline = $withBaseline ? $run->start($this->catalog->isAvailable(...), $judge, false) : null;
            $version = $run->start($this->catalog->isAvailable(...), $judge);
            $this->entityManager->flush();
        } finally {
            $lock->release();
        }

        $versions = null === $baseline ? [$version] : [$baseline, $version];
        $results = array_merge(...array_map(static fn (RunVersion $created): array => $created->getResults()->toArray(), $versions));
        $undispatched = array_map(static fn (RunResult $result): int => $result->getId() ?? throw new \LogicException('Result was not stored.'), $results);
        try {
            while ([] !== $undispatched) {
                $this->messageBus->dispatch(new ExecuteRunResult($undispatched[0]));
                array_shift($undispatched);
            }
        } catch (\Throwable $error) {
            $this->failUndispatched($undispatched);
            foreach ($results as $result) {
                $this->entityManager->refresh($result);
            }

            throw $error;
        }

        return $version;
    }

    /**
     * Otherwise the pending cells would block the next start forever (pending never times out). A bulk update instead
     * of a flush: a worker may already hold a dispatched cell, and a lost optimistic lock would close the entity manager.
     *
     * @param list<int> $ids
     */
    private function failUndispatched(array $ids): void
    {
        $now = new \DateTimeImmutable();
        $this->entityManager->createQuery(\sprintf(
            "UPDATE %s r SET r.status = :failed, r.error = 'dispatch_failed', r.finishedAt = :now, r.statusChangedAt = :now, r.version = r.version + 1 WHERE r.status = :pending AND r.id IN (:ids)",
            RunResult::class,
        ))
            ->setParameter('failed', RunResultStatus::Failed->value)
            ->setParameter('pending', RunResultStatus::Pending->value)
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->setParameter('ids', $ids)
            ->execute();
    }
}
