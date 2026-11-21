<?php

declare(strict_types=1);

namespace App\Run;

use App\Entity\Skill;
use App\Entity\Testcase;
use Doctrine\DBAL\Connection;

/**
 * Keeps the invariant "the skills and testcases of a run belong to the run's project and are not deleted".
 * Call after the change has been flushed. Old run versions are snapshots and stay untouched.
 */
final readonly class RunAssignmentPruner
{
    public function __construct(private Connection $connection)
    {
    }

    public function pruneSkill(Skill $skill): void
    {
        $this->prune($skill, 'run_skill', 'skill_project', 'skill_id');
    }

    public function pruneTestcase(Testcase $testcase): void
    {
        $this->prune($testcase, 'run_testcase', 'testcase_project', 'testcase_id');
    }

    // table and column names come from the two callers above, never from user input
    private function prune(Skill|Testcase $entity, string $runTable, string $projectTable, string $column): void
    {
        if ($entity->isDeleted()) {
            $this->connection->executeStatement(\sprintf('DELETE FROM %s WHERE %s = :id', $runTable, $column), ['id' => $entity->getId()]);

            return;
        }

        $this->connection->executeStatement(
            \sprintf(
                'DELETE FROM %1$s WHERE %3$s = :id AND run_id IN ('
                .'SELECT r.id FROM run r WHERE r.project_id NOT IN (SELECT p.project_id FROM %2$s p WHERE p.%3$s = :id))',
                $runTable,
                $projectTable,
                $column,
            ),
            ['id' => $entity->getId()],
        );
    }
}
