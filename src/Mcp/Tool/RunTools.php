<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Ai\Effort;
use App\Ai\EffortMapper;
use App\Ai\LlmConfiguration;
use App\Ai\ModelCatalog;
use App\Ai\Provider;
use App\Controller\ConflictMessage;
use App\Entity\Project;
use App\Entity\Run;
use App\Entity\Skill;
use App\Entity\Testcase;
use App\Mcp\ToolSupport;
use App\Pagination\ListQuery;
use App\Repository\ProjectRepository;
use App\Repository\RunRepository;
use App\Repository\SkillRepository;
use App\Repository\TestcaseRepository;
use App\Run\RunStarter;
use App\Security\Voter\ProjectVoter;
use App\Security\Voter\RunVoter;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\Exception\ExceptionInterface as MessengerException;
use Symfony\Component\Uid\Uuid;

/** Runs as in the web UI: setup (configurations, skills, testcases), start and delete. Rights are those of the signed-in user. */
final readonly class RunTools
{
    public function __construct(
        private ToolSupport $support,
        private RunVersionTools $versionTools,
        private RunRepository $runs,
        private ProjectRepository $projects,
        private SkillRepository $skills,
        private TestcaseRepository $testcases,
        private ModelCatalog $catalog,
        private RunStarter $starter,
        private EntityManagerInterface $entityManager,
        private Security $security,
    ) {
    }

    /** @return array<string, mixed> */
    #[McpTool(
        name: 'list_runs',
        description: 'Lists the runs of a project you may see, with skill and testcase counts. Sort fields: title, skills, testcases, updatedAt, createdAt. perPage is 10, 20, 50 or 100.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    public function list(string $projectUuid, int $page = 1, int $perPage = 20, string $query = '', ?string $sort = null, string $dir = 'asc'): array
    {
        $project = $this->loadProject($projectUuid);
        $listQuery = new ListQuery(page: $page, perPage: $perPage, sort: $sort, dir: $dir, q: $query);
        $this->support->validate($listQuery);
        $result = $this->runs->paginate($project, $listQuery, $perPage);

        $items = [];
        foreach ($result->items as $row) {
            \assert(\is_array($row) && $row[0] instanceof Run);
            $items[] = $this->summary($row[0]) + [
                'skillCount' => $this->support->count($row['skillCount']),
                'testcaseCount' => $this->support->count($row['testcaseCount']),
            ];
        }

        return ['items' => $items, 'total' => $result->total, 'page' => $result->page, 'pageCount' => $result->pageCount];
    }

    /** @return array<string, mixed> */
    #[McpTool(
        name: 'get_run',
        description: 'Shows a run: details, project, LLM configurations, skills, testcases, its versions with status and progress, and the start check (canStart, problems that block a start, number of LLM and judge calls a start would make, baselineAvailable: a first start could also run a baseline without skills).',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    public function get(string $runUuid): array
    {
        return $this->detail($this->load($runUuid, RunVoter::VIEW));
    }

    /** @return array<string, mixed> */
    #[McpTool(
        name: 'create_run',
        description: 'Creates an empty run in a project you may see. Add configurations, skills and testcases afterwards. The title is required (at most 255 characters), the description optional (at most 2000).',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false),
    )]
    public function create(string $projectUuid, string $title, ?string $description = null): array
    {
        $run = new Run($this->loadProject($projectUuid), $this->support->user());
        $run->setTitle($title);
        $run->setDescription($description);
        $this->support->validate($run);
        $this->entityManager->persist($run);
        $this->support->flush();

        return $this->detail($run);
    }

    /** @return array<string, mixed> */
    #[McpTool(
        name: 'update_run',
        description: 'Changes the title and/or description of a run. Omit a field to keep it; an empty description clears it.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true),
    )]
    public function update(string $runUuid, ?string $title = null, ?string $description = null): array
    {
        $run = $this->load($runUuid, RunVoter::EDIT);
        if (null !== $title) {
            $run->setTitle($title);
        }
        if (null !== $description) {
            $run->setDescription($description);
        }
        $this->support->validate($run);
        $this->support->flush();

        return $this->detail($run);
    }

    /** @return array<string, mixed> */
    #[McpTool(
        name: 'list_models',
        description: 'Lists the providers that have a stored API key with their models, whether a model supports an effort level (and what happens at the highest level), and the effort levels. Use provider and model with add_run_configuration. Never shows key material.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    public function models(): array
    {
        $providers = [];
        foreach (Provider::cases() as $provider) {
            if (!$this->catalog->isAvailable($provider)) {
                continue;
            }
            $models = [];
            foreach ($this->catalog->models($provider) as $model) {
                $note = EffortMapper::map(new LlmConfiguration($provider, $model, Effort::Max))['note'];
                $models[] = ['model' => $model, 'effortSupported' => EffortMapper::NOT_SUPPORTED !== $note, 'maxEffortNote' => $note];
            }
            $providers[] = ['provider' => $provider->value, 'name' => $provider->displayName(), 'models' => $models];
        }

        return ['providers' => $providers, 'efforts' => array_map(static fn (Effort $effort): string => $effort->value, Effort::cases())];
    }

    /** @return array<string, mixed> */
    #[McpTool(
        name: 'add_run_configuration',
        description: 'Adds an LLM configuration (provider, model and an optional effort low, medium, high, xhigh or max) to a run. The provider needs a stored API key, see list_models. The same combination cannot be added twice.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false),
    )]
    public function addConfiguration(string $runUuid, string $provider, string $model, ?string $effort = null): array
    {
        $run = $this->load($runUuid, RunVoter::EDIT);
        $configuration = $this->catalog->fromChoice($provider.':'.$model, $this->effort($effort))
            ?? throw new ToolCallException('The configuration could not be added: unknown provider or model, or the provider has no API key. See list_models.');
        if (!$run->addConfiguration($configuration)) {
            throw new ToolCallException('This configuration already exists.');
        }
        $this->support->flush();

        return $this->detail($run);
    }

    /** @return array<string, mixed> */
    #[McpTool(
        name: 'remove_run_configuration',
        description: 'Removes an LLM configuration from a run. It is identified by provider, model and effort (omit the effort for a configuration without one).',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true),
    )]
    public function removeConfiguration(string $runUuid, string $provider, string $model, ?string $effort = null): array
    {
        $run = $this->load($runUuid, RunVoter::EDIT);
        $resolved = Provider::tryFrom($provider);
        if (null === $resolved || !$run->removeConfiguration((new LlmConfiguration($resolved, $model, $this->effort($effort)))->key())) {
            throw new ToolCallException('There is no such configuration.');
        }
        $this->support->flush();

        return $this->detail($run);
    }

    /**
     * @param array<string> $skillUuids
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'set_run_skills',
        description: 'Replaces the skills of a run by the given ones (an empty list removes all; a run without skills is a baseline). Only skills of the run\'s project that are not deleted can be chosen; any other uuid fails and changes nothing.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true),
    )]
    public function setSkills(string $runUuid, array $skillUuids): array
    {
        $run = $this->load($runUuid, RunVoter::EDIT);
        $run->setSkills($this->pick($this->skills->findByProject($run->getProject()), $skillUuids, 'Skill'));
        $this->support->flush();

        return $this->detail($run);
    }

    /**
     * @param array<string> $testcaseUuids
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'set_run_testcases',
        description: 'Replaces the testcases of a run by the given ones (an empty list removes all). Only testcases of the run\'s project that are not deleted can be chosen; any other uuid fails and changes nothing.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true),
    )]
    public function setTestcases(string $runUuid, array $testcaseUuids): array
    {
        $run = $this->load($runUuid, RunVoter::EDIT);
        $run->setTestcases($this->pick($this->testcases->findByProject($run->getProject()), $testcaseUuids, 'Testcase'));
        $this->support->flush();

        return $this->detail($run);
    }

    /** @return array<string, mixed> */
    #[McpTool(
        name: 'start_run',
        description: 'Starts a new version of a run. This calls the configured LLM providers (one call per testcase and configuration, plus one judge call each) and COSTS MONEY; start only when the user wants it. Check get_run first (canStart, problems, llmCalls, judgeCalls). Execution runs in the background: follow it with get_run or get_run_version. Fails while another version of the run is still running. With withBaseline=true, a first version without skills is started as well (only for the first start of a run with skills, see baselineAvailable in get_run), as a baseline for the version with skills; this doubles the calls and the cost.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: true),
    )]
    public function start(string $runUuid, bool $withBaseline = false): array
    {
        $run = $this->load($runUuid, RunVoter::START);
        try {
            $version = $this->starter->start($run, $withBaseline);
        } catch (\LogicException $exception) {
            throw new ToolCallException($exception->getMessage());
        } catch (UniqueConstraintViolationException|ForeignKeyConstraintViolationException|OptimisticLockException) {
            throw new ToolCallException(ConflictMessage::TEXT);
        } catch (MessengerException) {
            throw new ToolCallException(RunStarter::DISPATCH_FAILED);
        }
        $baseline = $withBaseline ? $run->findVersion($version->getNumber() - 1) : null;
        $cells = $version->getResults()->count() + ($baseline?->getResults()->count() ?? 0);

        return ['started' => true, 'version' => $version->getNumber(), 'baselineVersion' => $baseline?->getNumber(), 'llmCalls' => $cells, 'judgeCalls' => $cells];
    }

    /** @return array<string, mixed> */
    #[McpTool(
        name: 'delete_run',
        description: 'Deletes a run for good, including all its versions and results. Skills and testcases stay. Only the creator of the run (while having access) or an administrator may do this. Cannot be undone.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true),
    )]
    public function delete(string $runUuid): array
    {
        $run = $this->load($runUuid, RunVoter::DELETE);
        $title = $run->getTitle();
        $this->entityManager->remove($run);
        $this->support->flush();

        return ['deleted' => true, 'title' => $title];
    }

    private function load(string $uuid, string $attribute): Run
    {
        $run = $this->support->find($this->runs, $uuid, 'Run');
        $this->support->grant($attribute, $run);

        return $run;
    }

    private function loadProject(string $uuid): Project
    {
        $project = $this->support->find($this->projects, $uuid, 'Project');
        $this->support->grant(ProjectVoter::VIEW, $project);

        return $project;
    }

    private function effort(?string $effort): ?Effort
    {
        $effort = $this->support->text($effort);
        if (null === $effort) {
            return null;
        }

        return Effort::tryFrom($effort) ?? throw new ToolCallException(\sprintf('Unknown effort "%s". Use one of: %s.', $effort, implode(', ', array_map(static fn (Effort $case): string => $case->value, Effort::cases()))));
    }

    /**
     * Resolves all uuids before anything changes, so one bad uuid leaves the run as it was.
     *
     * @template T of Skill|Testcase
     *
     * @param list<T>       $available
     * @param array<string> $uuids
     *
     * @return list<T>
     */
    private function pick(array $available, array $uuids, string $what): array
    {
        $byUuid = [];
        foreach ($available as $item) {
            $byUuid[$item->getUuid()->toRfc4122()] = $item;
        }

        $picked = [];
        foreach ($uuids as $uuid) {
            $this->support->requireUuid($uuid, $what);
            $item = $byUuid[Uuid::fromString($uuid)->toRfc4122()] ?? throw new ToolCallException(\sprintf('%s not found.', $what));
            $picked[spl_object_id($item)] = $item;
        }

        return array_values($picked);
    }

    /** @return array<string, mixed> */
    private function summary(Run $run): array
    {
        return [
            'uuid' => $run->getUuid()->toRfc4122(),
            'title' => $run->getTitle(),
            'description' => $run->getDescription(),
            'createdAt' => $this->support->iso($run->getCreatedAt()),
            'updatedAt' => $this->support->iso($run->getUpdatedAt()),
        ];
    }

    /** @return array<string, mixed> */
    private function detail(Run $run): array
    {
        $check = $this->starter->check($run);
        $project = $run->getProject();

        return $this->summary($run) + [
            'project' => ['uuid' => $project->getUuid()->toRfc4122(), 'title' => $project->getTitle()],
            'canDelete' => $this->security->isGranted(RunVoter::DELETE, $run),
            'configurations' => array_map(
                fn (LlmConfiguration $configuration): array => [
                    'provider' => $configuration->provider->value,
                    'model' => $configuration->model,
                    'effort' => $configuration->effort?->value,
                    'label' => $configuration->label(),
                    'hasApiKey' => $this->catalog->isAvailable($configuration->provider),
                    'effortNote' => EffortMapper::map($configuration)['note'],
                ],
                $run->getConfigurations(),
            ),
            'skills' => array_values(array_map(
                static fn (Skill $skill): array => ['uuid' => $skill->getUuid()->toRfc4122(), 'title' => $skill->getActiveVersion()->getTitle()],
                $run->getSkills()->toArray(),
            )),
            'testcases' => array_values(array_map(
                static fn (Testcase $testcase): array => ['uuid' => $testcase->getUuid()->toRfc4122(), 'title' => $testcase->getActiveVersion()->getTitle()],
                $run->getTestcases()->toArray(),
            )),
            'versions' => array_values(array_map($this->versionTools->summary(...), $run->getVersions()->toArray())),
            'canStart' => $check->canStart(),
            'problems' => $check->problems,
            'llmCalls' => $check->llmCalls,
            'judgeCalls' => $check->judgeCalls,
            'baselineAvailable' => $check->baselineAvailable,
        ];
    }
}
