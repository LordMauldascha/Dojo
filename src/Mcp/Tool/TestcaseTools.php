<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\Testcase;
use App\Form\Model\TestcaseData;
use App\Mcp\ToolSupport;
use App\Mcp\VersionedEntityTools;
use App\Repository\TestcaseRepository;
use App\Security\Voter\TestcaseVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;

/** Testcases as in the web UI: a versioned prompt with output criteria and a draft, assigned to projects. Rights are those of the signed-in user. */
final readonly class TestcaseTools
{
    public function __construct(
        private ToolSupport $support,
        private VersionedEntityTools $tools,
        private TestcaseRepository $testcases,
    ) {
    }

    /** @return array<string, mixed> */
    #[McpTool(
        name: 'list_testcases',
        description: 'Lists the testcases you may see (administrators: all), searched and sorted by their current version. Sort fields: title, updatedAt, createdAt. perPage is 10, 20, 50 or 100. projectUuid filters by project.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    public function list(int $page = 1, int $perPage = 20, string $query = '', ?string $sort = null, string $dir = 'asc', ?string $projectUuid = null): array
    {
        return $this->tools->list($this->testcases, $page, $perPage, $query, $sort, $dir, $projectUuid, false);
    }

    /** @return array<string, mixed> */
    #[McpTool(
        name: 'get_testcase',
        description: 'Shows a testcase: current version with prompt and output criteria, the open draft if any, the version history and the projects you may see.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    public function get(string $testcaseUuid): array
    {
        return $this->tools->detail($this->testcases, $this->tools->load($this->testcases, $testcaseUuid, TestcaseVoter::VIEW));
    }

    /** @return array<string, mixed> */
    #[McpTool(
        name: 'get_testcase_version',
        description: 'Shows one version of a testcase (also the draft) by its number, with prompt and output criteria.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    public function version(string $testcaseUuid, int $number): array
    {
        return $this->tools->versionByNumber($this->testcases, $testcaseUuid, TestcaseVoter::VIEW, $number);
    }

    /**
     * @param array<string> $projectUuids
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'create_testcase',
        description: 'Creates a testcase as version 1. Non-administrators must give at least one project (projectUuids, only projects you may see); administrators may omit it, then only administrators see the testcase.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false),
    )]
    public function create(string $title, string $prompt, string $outputCriteria, ?string $description = null, array $projectUuids = []): array
    {
        $data = new TestcaseData();
        $data->title = trim($title);
        $data->description = $description;
        $data->prompt = $prompt;
        $data->outputCriteria = $outputCriteria;
        $this->support->validate($data);
        $projects = $this->tools->projectsForCreate($projectUuids);
        $testcase = new Testcase($data->normalizedTitle(), $data->normalizedDescription(), $data->normalizedPrompt(), $data->normalizedOutputCriteria(), $this->support->user());

        return $this->tools->store($this->testcases, $testcase, $projects);
    }

    /** @return array<string, mixed> */
    #[McpTool(
        name: 'save_testcase',
        description: 'Saves the testcase as a draft (asDraft true; one draft per testcase, overwritten in place) or as a final version (the draft becomes the next version, without a draft a new version is created). Omitted fields keep the values of the draft, else of the current version; an empty description clears it.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false),
    )]
    public function save(string $testcaseUuid, bool $asDraft, ?string $title = null, ?string $description = null, ?string $prompt = null, ?string $outputCriteria = null): array
    {
        $testcase = $this->tools->load($this->testcases, $testcaseUuid, TestcaseVoter::EDIT);
        $data = TestcaseData::fromVersion($testcase->getEditableVersion());
        $data->title = trim($title ?? $data->title ?? '');
        $data->description = $description ?? $data->description;
        $data->prompt = $prompt ?? $data->prompt;
        $data->outputCriteria = $outputCriteria ?? $data->outputCriteria;
        $this->support->validate($data);

        $args = [$data->normalizedTitle(), $data->normalizedDescription(), $data->normalizedPrompt(), $data->normalizedOutputCriteria()];
        $version = $asDraft ? $testcase->saveDraft(...$args) : $testcase->saveFinal(...$args);

        return $this->tools->saved($this->testcases, $testcase, $version);
    }

    /**
     * @param array<string> $projectUuids
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'set_testcase_projects',
        description: 'Sets the projects of a testcase to exactly projectUuids, among the projects you may see; assignments to projects you cannot see stay. Non-administrators must keep at least one project. Runs of removed projects lose the testcase.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true),
    )]
    public function setProjects(string $testcaseUuid, array $projectUuids): array
    {
        return $this->tools->setProjects($this->testcases, $testcaseUuid, TestcaseVoter::EDIT, $projectUuids);
    }

    /** @return array<string, mixed> */
    #[McpTool(
        name: 'restore_testcase_version',
        description: 'Copies an old final version as a new final version; the history is kept. Refused while a draft exists.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false),
    )]
    public function restore(string $testcaseUuid, int $number): array
    {
        return $this->tools->restore($this->testcases, $testcaseUuid, TestcaseVoter::EDIT, $number);
    }

    /** @return array<string, mixed> */
    #[McpTool(
        name: 'discard_testcase_draft',
        description: 'Deletes the open draft of a testcase; the final versions stay.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true),
    )]
    public function discardDraft(string $testcaseUuid): array
    {
        return $this->tools->discardDraft($this->testcases, $testcaseUuid, TestcaseVoter::EDIT);
    }

    /** @return array<string, mixed> */
    #[McpTool(
        name: 'delete_testcase',
        description: 'Deletes a testcase and removes it from all runs. Only its creator and administrators may do this.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true),
    )]
    public function delete(string $testcaseUuid): array
    {
        return $this->tools->delete($this->testcases, $testcaseUuid, TestcaseVoter::DELETE);
    }
}
