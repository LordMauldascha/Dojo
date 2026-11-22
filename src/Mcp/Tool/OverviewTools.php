<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\Project;
use App\Entity\SkillVersion;
use App\Entity\TestcaseVersion;
use App\Mcp\ToolSupport;
use App\Repository\ProjectRepository;
use App\Repository\RunRepository;
use App\Repository\SkillRepository;
use App\Repository\TestcaseRepository;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;

/** The start page of the web UI: own projects, latest run versions and open drafts. */
final readonly class OverviewTools
{
    private const RECENT_LIMIT = 5;

    public function __construct(
        private ToolSupport $support,
        private ProjectRepository $projects,
        private RunRepository $runs,
        private SkillRepository $skills,
        private TestcaseRepository $testcases,
    ) {
    }

    /** @return array<string, mixed> */
    #[McpTool(
        name: 'get_overview',
        description: 'Start here: whether you act as an administrator, your projects (with counts and last run), the latest run versions and your open skill and testcase drafts.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    public function overview(): array
    {
        $user = $this->support->user();

        $projects = [];
        foreach ($this->projects->findOverview($user, ProjectRepository::OVERVIEW_LIMIT) as $row) {
            \assert($row[0] instanceof Project);
            $lastRun = $row['lastRun'] ?? null;
            $projects[] = [
                'uuid' => $row[0]->getUuid()->toRfc4122(),
                'title' => $row[0]->getTitle(),
                'skillCount' => $this->support->count($row['skillCount'] ?? null),
                'testcaseCount' => $this->support->count($row['testcaseCount'] ?? null),
                'runCount' => $this->support->count($row['runCount'] ?? null),
                'lastRunAt' => $lastRun instanceof \DateTimeImmutable ? $this->support->iso($lastRun) : null,
            ];
        }

        $versions = [];
        foreach ($this->runs->findRecentVersions($user, self::RECENT_LIMIT) as $version) {
            $versions[] = [
                'runUuid' => $version->getRun()->getUuid()->toRfc4122(),
                'runTitle' => $version->getRun()->getTitle(),
                'projectTitle' => $version->getRun()->getProject()->getTitle(),
                'version' => $version->getNumber(),
                'status' => $version->getStatusLabel(),
                'createdAt' => $this->support->iso($version->getCreatedAt()),
            ];
        }

        $skillDrafts = [];
        foreach ($this->skills->findRecentDrafts($user, self::RECENT_LIMIT) as $draft) {
            \assert($draft instanceof SkillVersion);
            $skillDrafts[] = ['skillUuid' => $draft->getSkill()->getUuid()->toRfc4122(), 'title' => $draft->getTitle(), 'savedAt' => $this->support->iso($draft->getSavedAt())];
        }

        $testcaseDrafts = [];
        foreach ($this->testcases->findRecentDrafts($user, self::RECENT_LIMIT) as $draft) {
            \assert($draft instanceof TestcaseVersion);
            $testcaseDrafts[] = ['testcaseUuid' => $draft->getTestcase()->getUuid()->toRfc4122(), 'title' => $draft->getTitle(), 'savedAt' => $this->support->iso($draft->getSavedAt())];
        }

        return [
            'isAdmin' => $user->isAdmin(),
            'projects' => $projects,
            'recentRunVersions' => $versions,
            'skillDrafts' => $skillDrafts,
            'testcaseDrafts' => $testcaseDrafts,
        ];
    }
}
