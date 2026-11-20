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
        if ($skill->isDeleted()) {
            $this->connection->executeStatement('DELETE FROM run_skill WHERE skill_id = :id', ['id' => $skill->getId()]);

            return;
        }

        $this->connection->executeStatement(
            'DELETE FROM run_skill WHERE skill_id = :id AND run_id IN ('
            .'SELECT r.id FROM run r WHERE r.project_id NOT IN (SELECT sp.project_id FROM skill_project sp WHERE sp.skill_id = :id))',
            ['id' => $skill->getId()],
        );
    }

    public function pruneTestcase(Testcase $testcase): void
    {
        if ($testcase->isDeleted()) {
            $this->connection->executeStatement('DELETE FROM run_testcase WHERE testcase_id = :id', ['id' => $testcase->getId()]);

            return;
        }

        $this->connection->executeStatement(
            'DELETE FROM run_testcase WHERE testcase_id = :id AND run_id IN ('
            .'SELECT r.id FROM run r WHERE r.project_id IS DISTINCT FROM (SELECT t.project_id FROM testcase t WHERE t.id = :id))',
            ['id' => $testcase->getId()],
        );
    }
}
