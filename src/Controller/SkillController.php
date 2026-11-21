<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Skill;
use App\Entity\SkillVersion;
use App\Entity\User;
use App\Form\Model\SkillData;
use App\Form\SkillContentType;
use App\Form\SkillProjectsType;
use App\Pagination\ListQuery;
use App\Pagination\PageSizeResolver;
use App\Repository\ProjectRepository;
use App\Repository\SkillRepository;
use App\Routing\Requirements;
use App\Run\RunAssignmentPruner;
use App\Security\Voter\SkillVoter;
use App\Versioning\VersionWorkflow;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** @extends VersionedEntityController<Skill, SkillData> */
#[IsGranted('ROLE_USER')]
#[Route('/skills')]
final class SkillController extends VersionedEntityController
{
    public const CSRF_RESTORE = 'restore-skill-';
    public const CSRF_DISCARD_DRAFT = 'discard-skill-draft-';
    public const CSRF_DELETE = 'delete-skill-';

    #[Route('', name: 'app_skills', methods: ['GET'])]
    public function index(
        SkillRepository $skills,
        ProjectRepository $projects,
        PageSizeResolver $pageSizes,
        #[CurrentUser] User $viewer,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        ListQuery $query = new ListQuery(),
    ): Response {
        return $this->renderIndex($skills, $projects, $pageSizes, $viewer, $query, true);
    }

    #[Route('/new', name: 'app_skill_new', methods: ['GET', 'POST'])]
    public function new(Request $request, ProjectRepository $projects, EntityManagerInterface $entityManager, #[CurrentUser] User $user): Response
    {
        return $this->handleNew($request, $projects, $entityManager, $user);
    }

    #[Route('/{uuid}', name: 'app_skill_show', requirements: ['uuid' => Requirement::UUID], methods: ['GET', 'POST'])]
    #[IsGranted(SkillVoter::EDIT, subject: 'skill')]
    public function show(
        #[MapEntity(expr: self::FIND_ACTIVE)] Skill $skill,
        Request $request,
        ProjectRepository $projects,
        SkillRepository $skills,
        EntityManagerInterface $entityManager,
        #[CurrentUser] User $user,
    ): Response {
        return $this->handleShow($skill, $request, $skills, $projects, $entityManager, $user);
    }

    #[Route('/{uuid}/projects', name: 'app_skill_projects', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(SkillVoter::EDIT, subject: 'skill')]
    public function projects(
        #[MapEntity(expr: self::FIND_ACTIVE)] Skill $skill,
        Request $request,
        ProjectRepository $projects,
        RunAssignmentPruner $pruner,
        EntityManagerInterface $entityManager,
        #[CurrentUser] User $user,
    ): Response {
        return $this->handleProjects($skill, $request, $projects, $pruner, $entityManager, $user);
    }

    #[Route('/{uuid}/versions/{number}', name: 'app_skill_version', requirements: ['uuid' => Requirement::UUID, 'number' => Requirements::ID], methods: ['GET'])]
    #[IsGranted(SkillVoter::VIEW, subject: 'skill')]
    public function version(#[MapEntity(expr: self::FIND_ACTIVE)] Skill $skill, int $number): Response
    {
        $version = $skill->findVersion($number) ?? throw $this->createNotFoundException();

        return $this->render('skills/version.html.twig', ['skill' => $skill, 'version' => $version]);
    }

    #[Route('/{uuid}/versions/{number}/restore', name: 'app_skill_restore', requirements: ['uuid' => Requirement::UUID, 'number' => Requirements::ID], methods: ['POST'])]
    #[IsGranted(SkillVoter::EDIT, subject: 'skill')]
    #[IsCsrfTokenValid(new Expression('"'.self::CSRF_RESTORE.'" ~ args["skill"].getUuid() ~ "-" ~ args["number"]'), methods: 'POST')]
    public function restore(
        #[MapEntity(expr: self::FIND_ACTIVE)] Skill $skill,
        int $number,
        VersionWorkflow $versions,
        EntityManagerInterface $entityManager,
    ): Response {
        [$type, $message] = $versions->restore($skill, $number);
        if ($this->flushOrConflict($entityManager)) {
            $this->addFlash($type, $message);
        }

        return $this->redirectToShow($skill);
    }

    #[Route('/{uuid}/draft/discard', name: 'app_skill_draft_discard', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(SkillVoter::EDIT, subject: 'skill')]
    #[IsCsrfTokenValid(new Expression('"'.self::CSRF_DISCARD_DRAFT.'" ~ args["skill"].getUuid()'), methods: 'POST')]
    public function discardDraft(
        #[MapEntity(expr: self::FIND_ACTIVE)] Skill $skill,
        VersionWorkflow $versions,
        EntityManagerInterface $entityManager,
    ): Response {
        [$type, $message] = $versions->discardDraft($skill);
        if ($this->flushOrConflict($entityManager)) {
            $this->addFlash($type, $message);
        }

        return $this->redirectToShow($skill);
    }

    #[Route('/{uuid}/delete', name: 'app_skill_delete', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(SkillVoter::DELETE, subject: 'skill')]
    #[IsCsrfTokenValid(new Expression('"'.self::CSRF_DELETE.'" ~ args["skill"].getUuid()'), methods: 'POST')]
    public function delete(
        #[MapEntity(expr: self::FIND_ACTIVE)] Skill $skill,
        RunAssignmentPruner $pruner,
        EntityManagerInterface $entityManager,
    ): Response {
        return $this->handleDelete($skill, $pruner, $entityManager);
    }

    protected function label(): string
    {
        return 'skill';
    }

    protected function contentFormType(): string
    {
        return SkillContentType::class;
    }

    protected function projectsFormType(): string
    {
        return SkillProjectsType::class;
    }

    protected function viewAttribute(): string
    {
        return SkillVoter::VIEW;
    }

    protected function deleteAttribute(): string
    {
        return SkillVoter::DELETE;
    }

    /**
     * @param Skill|null $entity
     */
    protected function formData(?object $entity): SkillData
    {
        return null === $entity ? new SkillData() : SkillData::fromVersion($entity->getEditableVersion());
    }

    /**
     * @param SkillData $data
     */
    protected function createEntity(object $data, User $user): Skill
    {
        return new Skill($data->normalizedTitle(), $data->normalizedDescription(), $data->normalizedContent(), $user);
    }

    /**
     * @param Skill     $entity
     * @param SkillData $data
     */
    protected function saveVersion(object $entity, object $data, bool $asDraft): SkillVersion
    {
        $args = [$data->normalizedTitle(), $data->normalizedDescription(), $data->normalizedContent()];

        return $asDraft ? $entity->saveDraft(...$args) : $entity->saveFinal(...$args);
    }

    /**
     * @param Skill $entity
     */
    protected function pruneRuns(RunAssignmentPruner $pruner, object $entity): void
    {
        $pruner->pruneSkill($entity);
    }
}
