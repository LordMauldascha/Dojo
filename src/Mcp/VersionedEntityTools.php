<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Entity\Project;
use App\Entity\Skill;
use App\Entity\SkillVersion;
use App\Entity\Testcase;
use App\Entity\TestcaseVersion;
use App\Pagination\ListQuery;
use App\Repository\ProjectRepository;
use App\Repository\VersionedEntityRepository;
use App\Run\RunAssignmentPruner;
use App\Versioning\VersionWorkflow;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/** What the skill and testcase tools share: loading with the voter check, lists, details, projects, version workflow and delete. */
final readonly class VersionedEntityTools
{
    public function __construct(
        private ToolSupport $support,
        private ProjectRepository $projects,
        private RunAssignmentPruner $pruner,
        private VersionWorkflow $workflow,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Deleted entities are invisible, like the 404 of the UI.
     *
     * @template T of Skill|Testcase
     *
     * @param VersionedEntityRepository<T> $repository
     *
     * @return T
     */
    public function load(VersionedEntityRepository $repository, string $uuid, string $attribute): Skill|Testcase
    {
        $what = (new \ReflectionClass($repository->getClassName()))->getShortName();
        $this->support->requireUuid($uuid, $what);
        $entity = $repository->findActiveByUuid($uuid) ?? throw new ToolCallException(\sprintf('%s not found.', $what));
        $this->support->grant($attribute, $entity);

        return $entity;
    }

    /**
     * @template T of Skill|Testcase
     *
     * @param VersionedEntityRepository<T> $repository
     *
     * @return array<string, mixed>
     */
    public function list(VersionedEntityRepository $repository, int $page, int $perPage, string $query, ?string $sort, string $dir, ?string $projectUuid, bool $withRunCounts): array
    {
        $listQuery = new ListQuery(page: $page, perPage: $perPage, sort: $sort, dir: $dir, q: $query, project: $projectUuid);
        $this->support->validate($listQuery);
        $user = $this->support->user();
        $result = $repository->paginate($listQuery, $perPage, $user);
        $draftNumbers = $repository->draftNumbers($result->items);
        $runCounts = $withRunCounts ? $repository->runCounts($result->items, $user) : [];
        $accessible = $this->projects->findAccessibleBy($user);

        $items = [];
        foreach ($result->items as $entity) {
            \assert($entity instanceof Skill || $entity instanceof Testcase);
            $id = $entity->getId() ?? 0;
            $items[] = $this->summary($entity, $accessible)
                + ['draftVersion' => $draftNumbers[$id] ?? null]
                + ($withRunCounts ? ['runCount' => $runCounts[$id] ?? 0] : []);
        }

        return ['items' => $items, 'total' => $result->total, 'page' => $result->page, 'pageCount' => $result->pageCount];
    }

    /**
     * @template T of Skill|Testcase
     *
     * @param VersionedEntityRepository<T> $repository
     * @param T                            $entity
     *
     * @return array<string, mixed>
     */
    public function detail(VersionedEntityRepository $repository, Skill|Testcase $entity): array
    {
        $user = $this->support->user();
        $accessible = $this->projects->findAccessibleBy($user);
        $summary = $this->summary($entity, $accessible);
        $draft = $entity->getDraft();

        $versions = [];
        foreach ($entity->getVersions() as $version) {
            $versions[] = ['number' => $version->getNumber(), 'draft' => $version->isDraft(), 'title' => $version->getTitle(), 'savedAt' => $this->support->iso($version->getSavedAt())];
        }

        return $summary + [
            'hiddenProjectCount' => $entity->getProjects()->count() - \count($this->listAssigned($entity, $accessible)),
            'runCount' => $repository->runCounts([$entity], $user)[$entity->getId() ?? 0] ?? 0,
            'current' => $this->version($entity->getActiveVersion()),
            'draft' => null === $draft ? null : $this->version($draft),
            'versions' => $versions,
        ];
    }

    /** @return array<string, mixed> */
    public function version(SkillVersion|TestcaseVersion $version): array
    {
        return [
            'number' => $version->getNumber(),
            'draft' => $version->isDraft(),
            'title' => $version->getTitle(),
            'description' => $version->getDescription(),
            'savedAt' => $this->support->iso($version->getSavedAt()),
        ] + ($version instanceof SkillVersion
            ? ['content' => $version->getContent()]
            : ['prompt' => $version->getPrompt(), 'outputCriteria' => $version->getOutputCriteria()]);
    }

    /**
     * @template T of Skill|Testcase
     *
     * @param VersionedEntityRepository<T> $repository
     *
     * @return array<string, mixed>
     */
    public function versionByNumber(VersionedEntityRepository $repository, string $uuid, string $viewAttribute, int $number): array
    {
        $version = $this->load($repository, $uuid, $viewAttribute)->findVersion($number) ?? throw new ToolCallException('Version not found.');

        return $this->version($version);
    }

    /**
     * The projects of a new entity: only projects the user may see; non-admins need at least one, like the form.
     *
     * @param array<string> $uuids
     *
     * @return list<Project>
     */
    public function projectsForCreate(array $uuids): array
    {
        $user = $this->support->user();
        $chosen = $this->chosen($uuids, $this->projects->findAccessibleBy($user));
        if ([] === $chosen && !$user->isAdmin()) {
            throw new ToolCallException('Select at least one project.');
        }

        return $chosen;
    }

    /**
     * @template T of Skill|Testcase
     *
     * @param VersionedEntityRepository<T> $repository
     * @param T                            $entity
     * @param list<Project>                $projects
     *
     * @return array<string, mixed>
     */
    public function store(VersionedEntityRepository $repository, Skill|Testcase $entity, array $projects): array
    {
        foreach ($projects as $project) {
            $entity->addProject($project);
        }
        $this->entityManager->persist($entity);
        $this->support->flush();

        return $this->detail($repository, $entity);
    }

    /**
     * Flushes a version just saved on the entity.
     *
     * @template T of Skill|Testcase
     *
     * @param VersionedEntityRepository<T> $repository
     * @param T                            $entity
     *
     * @return array<string, mixed>
     */
    public function saved(VersionedEntityRepository $repository, Skill|Testcase $entity, SkillVersion|TestcaseVersion $version): array
    {
        $this->support->flush();

        return $this->detail($repository, $entity) + ['saved' => $version->getLabel()];
    }

    /**
     * @template T of Skill|Testcase
     *
     * @param VersionedEntityRepository<T> $repository
     * @param array<string>                $projectUuids
     *
     * @return array<string, mixed>
     */
    public function setProjects(VersionedEntityRepository $repository, string $uuid, string $editAttribute, array $projectUuids): array
    {
        $entity = $this->load($repository, $uuid, $editAttribute);
        $user = $this->support->user();
        $editable = $this->projects->findAccessibleBy($user);

        if (!$entity->replaceProjects($editable, $this->chosen($projectUuids, $editable), $user->isAdmin())) {
            throw new ToolCallException(\sprintf('A %s must keep at least one project.', strtolower((new \ReflectionClass($entity))->getShortName())));
        }
        $this->support->flush(fn () => $this->prune($entity));

        if (!$repository->isAccessibleBy($entity, $user)) {
            return ['uuid' => $entity->getUuid()->toRfc4122(), 'accessLost' => true, 'message' => 'You no longer have access to this item.'];
        }

        return $this->detail($repository, $entity);
    }

    /**
     * @template T of Skill|Testcase
     *
     * @param VersionedEntityRepository<T> $repository
     *
     * @return array<string, mixed>
     */
    public function restore(VersionedEntityRepository $repository, string $uuid, string $editAttribute, int $number): array
    {
        $entity = $this->load($repository, $uuid, $editAttribute);
        try {
            [$type, $message] = $this->workflow->restore($entity, $number);
        } catch (NotFoundHttpException) {
            throw new ToolCallException('Version not found.');
        }

        return $this->finishWorkflow($repository, $entity, $type, $message);
    }

    /**
     * @template T of Skill|Testcase
     *
     * @param VersionedEntityRepository<T> $repository
     *
     * @return array<string, mixed>
     */
    public function discardDraft(VersionedEntityRepository $repository, string $uuid, string $editAttribute): array
    {
        $entity = $this->load($repository, $uuid, $editAttribute);
        [$type, $message] = $this->workflow->discardDraft($entity);

        return $this->finishWorkflow($repository, $entity, $type, $message);
    }

    /**
     * @template T of Skill|Testcase
     *
     * @param VersionedEntityRepository<T> $repository
     *
     * @return array<string, mixed>
     */
    public function delete(VersionedEntityRepository $repository, string $uuid, string $deleteAttribute): array
    {
        $entity = $this->load($repository, $uuid, $deleteAttribute);
        $title = $entity->getActiveVersion()->getTitle();
        $entity->softDelete();
        $this->support->flush(fn () => $this->prune($entity));

        return ['deleted' => true, 'title' => $title];
    }

    /**
     * @template T of Skill|Testcase
     *
     * @param VersionedEntityRepository<T> $repository
     * @param T                            $entity
     * @param 'success'|'error'            $type
     *
     * @return array<string, mixed>
     */
    private function finishWorkflow(VersionedEntityRepository $repository, Skill|Testcase $entity, string $type, string $message): array
    {
        if ('error' === $type) {
            throw new ToolCallException($message);
        }
        $this->support->flush();

        return $this->detail($repository, $entity) + ['message' => $message];
    }

    private function prune(Skill|Testcase $entity): void
    {
        if ($entity instanceof Skill) {
            $this->pruner->pruneSkill($entity);

            return;
        }
        $this->pruner->pruneTestcase($entity);
    }

    /**
     * Only projects the user may see can be chosen; an invisible project answers like an unknown one.
     *
     * @param array<string> $uuids
     * @param list<Project> $accessible
     *
     * @return list<Project>
     */
    private function chosen(array $uuids, array $accessible): array
    {
        $chosen = [];
        foreach ($uuids as $uuid) {
            $this->support->requireUuid($uuid, 'Project');
            $wanted = Uuid::fromString($uuid);
            foreach ($accessible as $project) {
                if ($wanted->equals($project->getUuid())) {
                    $chosen[] = $project;
                    continue 2;
                }
            }
            throw new ToolCallException('Project not found.');
        }

        return $chosen;
    }

    /**
     * @param list<Project> $accessible
     *
     * @return list<Project>
     */
    private function listAssigned(Skill|Testcase $entity, array $accessible): array
    {
        return array_values(array_filter($entity->getProjects()->toArray(), static fn (Project $project): bool => \in_array($project, $accessible, true)));
    }

    /**
     * @param list<Project> $accessible
     *
     * @return array<string, mixed>
     */
    private function summary(Skill|Testcase $entity, array $accessible): array
    {
        $active = $entity->getActiveVersion();

        return [
            'uuid' => $entity->getUuid()->toRfc4122(),
            'title' => $active->getTitle(),
            'description' => $active->getDescription(),
            'version' => $active->getNumber(),
            'projects' => array_map(
                static fn (Project $project): array => ['uuid' => $project->getUuid()->toRfc4122(), 'title' => $project->getTitle()],
                $this->listAssigned($entity, $accessible),
            ),
            'createdAt' => $this->support->iso($entity->getCreatedAt()),
            'updatedAt' => $this->support->iso($entity->getUpdatedAt()),
        ];
    }
}
