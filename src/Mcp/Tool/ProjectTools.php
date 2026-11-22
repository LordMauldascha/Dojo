<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\Project;
use App\Mcp\ToolSupport;
use App\Pagination\ListQuery;
use App\Repository\GroupRepository;
use App\Repository\ProjectRepository;
use App\Repository\RunRepository;
use App\Repository\SkillRepository;
use App\Repository\TestcaseRepository;
use App\Repository\UserRepository;
use App\Security\Voter\ProjectVoter;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

/** Projects as in the web UI: everyone lists and views what they have access to, administrators create and manage. */
final readonly class ProjectTools
{
    public function __construct(
        private ToolSupport $support,
        private ProjectRepository $projects,
        private GroupRepository $groups,
        private UserRepository $users,
        private SkillRepository $skills,
        private TestcaseRepository $testcases,
        private RunRepository $runs,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** @return array<string, mixed> */
    #[McpTool(
        name: 'list_projects',
        description: 'Lists the projects you have access to (administrators: all projects), with counts. Sort fields: title, users, groups, skills, testcases, updatedAt, createdAt. per_page is 10, 20, 50 or 100.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    public function list(int $page = 1, int $perPage = 20, string $query = '', ?string $sort = null, string $dir = 'asc'): array
    {
        $listQuery = new ListQuery(page: $page, perPage: $perPage, sort: $sort, dir: $dir, q: $query);
        $this->support->validate($listQuery);
        $result = $this->projects->paginate($listQuery, $perPage, $this->support->user());

        $items = [];
        foreach ($result->items as $row) {
            \assert(\is_array($row) && $row[0] instanceof Project);
            $items[] = $this->summary($row[0]) + [
                'userCount' => $this->support->count($row['userCount']),
                'groupCount' => $this->support->count($row['groupCount']),
                'skillCount' => $this->support->count($row['skillCount']),
                'testcaseCount' => $this->support->count($row['testcaseCount']),
            ];
        }

        return ['items' => $items, 'total' => $result->total, 'page' => $result->page, 'pageCount' => $result->pageCount];
    }

    /** @return array<string, mixed> */
    #[McpTool(
        name: 'get_project',
        description: 'Shows one project with its skill, testcase and run counts. Administrators also get its groups and users and the groups and users that can still be added.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    public function get(string $projectUuid): array
    {
        return $this->detail($this->loadViewable($projectUuid));
    }

    /** @return array<string, mixed> */
    #[McpTool(
        name: 'create_project',
        description: 'Creates a project. Administrators only. The title must be unique (case-insensitive).',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false),
    )]
    public function create(string $title, ?string $description = null): array
    {
        $this->support->requireAdmin();
        $project = new Project();
        $project->setTitle($title);
        $project->setDescription($description);
        $this->support->validate($project);
        $this->entityManager->persist($project);
        $this->support->flush();

        return $this->detail($project);
    }

    /** @return array<string, mixed> */
    #[McpTool(
        name: 'update_project',
        description: 'Changes the title and/or description of a project. Administrators only. Omit a field to keep it; an empty description clears it.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true),
    )]
    public function update(string $projectUuid, ?string $title = null, ?string $description = null): array
    {
        $project = $this->loadManageable($projectUuid);
        if (null !== $title) {
            $project->setTitle($title);
        }
        if (null !== $description) {
            $project->setDescription($description);
        }
        $this->support->validate($project);
        $this->support->flush();

        return $this->detail($project);
    }

    /** @return array<string, mixed> */
    #[McpTool(
        name: 'delete_project',
        description: 'Deletes a project for good, including its runs and run versions. Skills and testcases stay but lose this project. Administrators only. Cannot be undone.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true),
    )]
    public function delete(string $projectUuid): array
    {
        $project = $this->loadManageable($projectUuid);
        $title = $project->getTitle();
        $this->entityManager->remove($project);
        $this->support->flush();

        return ['deleted' => true, 'title' => $title];
    }

    /** @return array<string, mixed> */
    #[McpTool(
        name: 'add_project_group',
        description: 'Gives all members of a group access to a project. Administrators only.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true),
    )]
    public function addGroup(string $projectUuid, string $groupUuid): array
    {
        $project = $this->loadManageable($projectUuid);
        $project->addGroup($this->support->find($this->groups, $groupUuid, 'Group'));
        $this->support->flush();

        return $this->detail($project);
    }

    /** @return array<string, mixed> */
    #[McpTool(
        name: 'remove_project_group',
        description: 'Removes a group from a project. Administrators only.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true),
    )]
    public function removeGroup(string $projectUuid, string $groupUuid): array
    {
        $project = $this->loadManageable($projectUuid);
        $project->removeGroup($this->support->find($this->groups, $groupUuid, 'Group'));
        $this->support->flush();

        return $this->detail($project);
    }

    /** @return array<string, mixed> */
    #[McpTool(
        name: 'add_project_user',
        description: 'Gives a user (by username) direct access to a project. Administrators only.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true),
    )]
    public function addUser(string $projectUuid, string $username): array
    {
        $project = $this->loadManageable($projectUuid);
        $project->addUser($this->users->findOneBy(['username' => $username]) ?? throw new ToolCallException('User not found.'));
        $this->support->flush();

        return $this->detail($project);
    }

    /** @return array<string, mixed> */
    #[McpTool(
        name: 'remove_project_user',
        description: 'Removes the direct access of a user (by username) from a project. Administrators only.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true),
    )]
    public function removeUser(string $projectUuid, string $username): array
    {
        $project = $this->loadManageable($projectUuid);
        $project->removeUser($this->users->findOneBy(['username' => $username]) ?? throw new ToolCallException('User not found.'));
        $this->support->flush();

        return $this->detail($project);
    }

    private function loadViewable(string $uuid): Project
    {
        $project = $this->support->find($this->projects, $uuid, 'Project');
        $this->support->grant(ProjectVoter::VIEW, $project);

        return $project;
    }

    private function loadManageable(string $uuid): Project
    {
        $project = $this->support->find($this->projects, $uuid, 'Project');
        $this->support->grant(ProjectVoter::MANAGE, $project);

        return $project;
    }

    /** @return array<string, mixed> */
    private function summary(Project $project): array
    {
        return [
            'uuid' => $project->getUuid()->toRfc4122(),
            'title' => $project->getTitle(),
            'description' => $project->getDescription(),
            'createdAt' => $this->support->iso($project->getCreatedAt()),
            'updatedAt' => $this->support->iso($project->getUpdatedAt()),
        ];
    }

    /** @return array<string, mixed> */
    private function detail(Project $project): array
    {
        $detail = $this->summary($project) + [
            'skillCount' => $this->skills->countByProject($project),
            'testcaseCount' => $this->testcases->countByProject($project),
            'runCount' => $this->runs->countByProject($project),
        ];
        if (!$this->support->user()->isAdmin()) {
            return $detail;
        }

        return $detail + [
            'groups' => $this->groupList($project->getGroups()),
            'users' => array_values($project->getUsers()->map(static fn ($user): string => $user->getUserIdentifier())->toArray()),
            'assignableGroups' => $this->groupList($this->projects->findAssignableGroups($project)),
            'assignableUsers' => array_map(static fn ($user): string => $user->getUserIdentifier(), $this->projects->findAssignableUsers($project)),
        ];
    }

    /**
     * @param iterable<\App\Entity\Group> $groups
     *
     * @return list<array{uuid: string, title: string}>
     */
    private function groupList(iterable $groups): array
    {
        $list = [];
        foreach ($groups as $group) {
            $list[] = ['uuid' => $group->getUuid()->toRfc4122(), 'title' => $group->getTitle()];
        }

        return $list;
    }
}
