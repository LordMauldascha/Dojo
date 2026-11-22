<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\Skill;
use App\Form\Model\SkillData;
use App\Mcp\ToolSupport;
use App\Mcp\VersionedEntityTools;
use App\Repository\SkillRepository;
use App\Security\Voter\SkillVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;

/** Skills as in the web UI: versioned instructions with a draft, assigned to projects. Rights are those of the signed-in user. */
final readonly class SkillTools
{
    public function __construct(
        private ToolSupport $support,
        private VersionedEntityTools $tools,
        private SkillRepository $skills,
    ) {
    }

    /** @return array<string, mixed> */
    #[McpTool(
        name: 'list_skills',
        description: 'Lists the skills you may see (administrators: all), searched and sorted by their current version. Sort fields: title, updatedAt, createdAt. perPage is 10, 20, 50 or 100. projectUuid filters by project.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    public function list(int $page = 1, int $perPage = 20, string $query = '', ?string $sort = null, string $dir = 'asc', ?string $projectUuid = null): array
    {
        return $this->tools->list($this->skills, $page, $perPage, $query, $sort, $dir, $projectUuid, true);
    }

    /** @return array<string, mixed> */
    #[McpTool(
        name: 'get_skill',
        description: 'Shows a skill: current version with content, the open draft if any, the version history and the projects you may see.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    public function get(string $skillUuid): array
    {
        return $this->tools->detail($this->skills, $this->tools->load($this->skills, $skillUuid, SkillVoter::VIEW));
    }

    /** @return array<string, mixed> */
    #[McpTool(
        name: 'get_skill_version',
        description: 'Shows one version of a skill (also the draft) by its number, with content.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    public function version(string $skillUuid, int $number): array
    {
        return $this->tools->versionByNumber($this->skills, $skillUuid, SkillVoter::VIEW, $number);
    }

    /**
     * @param array<string> $projectUuids
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'create_skill',
        description: 'Creates a skill as version 1. The title may only contain letters, digits, spaces and hyphens (it becomes the skill name). Non-administrators must give at least one project (projectUuids, only projects you may see); administrators may omit it, then only administrators see the skill.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false),
    )]
    public function create(string $title, string $content, ?string $description = null, array $projectUuids = []): array
    {
        $data = new SkillData();
        $data->title = trim($title);
        $data->description = $description;
        $data->content = $content;
        $this->support->validate($data);
        $projects = $this->tools->projectsForCreate($projectUuids);
        $skill = new Skill($data->normalizedTitle(), $data->normalizedDescription(), $data->normalizedContent(), $this->support->user());

        return $this->tools->store($this->skills, $skill, $projects);
    }

    /** @return array<string, mixed> */
    #[McpTool(
        name: 'save_skill',
        description: 'Saves the skill as a draft (asDraft true; one draft per skill, overwritten in place) or as a final version (the draft becomes the next version, without a draft a new version is created). Omitted fields keep the values of the draft, else of the current version; an empty description clears it.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false),
    )]
    public function save(string $skillUuid, bool $asDraft, ?string $title = null, ?string $description = null, ?string $content = null): array
    {
        $skill = $this->tools->load($this->skills, $skillUuid, SkillVoter::EDIT);
        $data = SkillData::fromVersion($skill->getEditableVersion());
        $data->title = trim($title ?? $data->title ?? '');
        $data->description = $description ?? $data->description;
        $data->content = $content ?? $data->content;
        $this->support->validate($data);

        $args = [$data->normalizedTitle(), $data->normalizedDescription(), $data->normalizedContent()];
        $version = $asDraft ? $skill->saveDraft(...$args) : $skill->saveFinal(...$args);

        return $this->tools->saved($this->skills, $skill, $version);
    }

    /**
     * @param array<string> $projectUuids
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'set_skill_projects',
        description: 'Sets the projects of a skill to exactly projectUuids, among the projects you may see; assignments to projects you cannot see stay. Non-administrators must keep at least one project. Runs of removed projects lose the skill.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true),
    )]
    public function setProjects(string $skillUuid, array $projectUuids): array
    {
        return $this->tools->setProjects($this->skills, $skillUuid, SkillVoter::EDIT, $projectUuids);
    }

    /** @return array<string, mixed> */
    #[McpTool(
        name: 'restore_skill_version',
        description: 'Copies an old final version as a new final version; the history is kept. Refused while a draft exists.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false),
    )]
    public function restore(string $skillUuid, int $number): array
    {
        return $this->tools->restore($this->skills, $skillUuid, SkillVoter::EDIT, $number);
    }

    /** @return array<string, mixed> */
    #[McpTool(
        name: 'discard_skill_draft',
        description: 'Deletes the open draft of a skill; the final versions stay.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true),
    )]
    public function discardDraft(string $skillUuid): array
    {
        return $this->tools->discardDraft($this->skills, $skillUuid, SkillVoter::EDIT);
    }

    /** @return array<string, mixed> */
    #[McpTool(
        name: 'delete_skill',
        description: 'Deletes a skill and removes it from all runs. Only its creator and administrators may do this.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true),
    )]
    public function delete(string $skillUuid): array
    {
        return $this->tools->delete($this->skills, $skillUuid, SkillVoter::DELETE);
    }
}
