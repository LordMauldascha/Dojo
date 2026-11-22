<?php

declare(strict_types=1);

namespace App\Run;

use App\Entity\Run;
use App\Entity\RunResult;
use App\Entity\RunVersion;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

/** Fails results that have been running or judging for too long (worker crash, killed process). No cron: callers sweep on page load and start. */
final readonly class StaleResultSweeper
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @return int number of results set to failed */
    public function sweep(RunVersion|Run $subject): int
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('result')
            ->from(RunResult::class, 'result')
            ->join('result.runVersion', 'version')
            ->where('result.status IN (:statuses)')
            ->setParameter('statuses', [RunResultStatus::Running->value, RunResultStatus::Judging->value]);
        if ($subject instanceof Run) {
            $query->andWhere('version.run = :subject');
        } else {
            $query->andWhere('version = :subject');
        }

        $now = new \DateTimeImmutable();
        /** @var list<RunResult> $results */
        $results = $query->setParameter('subject', $subject)->getQuery()->getResult();
        $stale = array_values(array_filter($results, static fn (RunResult $result): bool => $result->isStale($now)));
        if ([] === $stale) {
            return 0;
        }

        // the conditions repeat isStale() in SQL, so a worker that wrote meanwhile wins; no flush, which would close the entity manager on a lost race
        $count = $this->entityManager->createQuery(\sprintf(
            "UPDATE %s r SET r.status = :failed, r.error = 'timeout', r.finishedAt = :now, r.statusChangedAt = :now, r.version = r.version + 1 WHERE r.id IN (:ids) AND r.status IN (:open) AND r.statusChangedAt < :cutoff",
            RunResult::class,
        ))
            ->setParameter('failed', RunResultStatus::Failed->value)
            ->setParameter('open', [RunResultStatus::Running->value, RunResultStatus::Judging->value])
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->setParameter('cutoff', $now->modify(\sprintf('-%d minutes', RunResult::STALE_AFTER_MINUTES)), Types::DATETIME_IMMUTABLE)
            ->setParameter('ids', array_map(static fn (RunResult $result): ?int => $result->getId(), $stale))
            ->execute();
        foreach ($stale as $result) {
            $this->entityManager->refresh($result);
        }

        return \is_int($count) ? $count : 0;
    }
}
