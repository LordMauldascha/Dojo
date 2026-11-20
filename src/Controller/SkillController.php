<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Project;
use App\Entity\Skill;
use App\Entity\User;
use App\Form\FormData;
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
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\ClickableInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/skills')]
final class SkillController extends AbstractController
{
    use SafeFlushTrait;

    private const LIST_KEY = 'skills';
    private const FIND_SKILL = 'repository.findActiveByUuid(uuid)';

    #[Route('', name: 'app_skills', methods: ['GET'])]
    public function index(
        SkillRepository $skills,
        ProjectRepository $projects,
        PageSizeResolver $pageSizes,
        #[CurrentUser] User $viewer,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        ListQuery $query = new ListQuery(),
    ): Response {
        $accessible = $projects->findAccessibleBy($viewer);
        $page = $skills->paginate($query, $pageSizes->resolve(self::LIST_KEY, $query->perPage), $viewer);

        return $this->render('skills/index.html.twig', [
            'page' => $page,
            'runCounts' => $skills->runCounts($page->items, $viewer),
            'query' => $query,
            'projectChoices' => $accessible,
            'visibleProjectIds' => array_map(static fn (Project $project): ?int => $project->getId(), $accessible),
        ]);
    }

    #[Route('/new', name: 'app_skill_new', methods: ['GET', 'POST'])]
    public function new(Request $request, ProjectRepository $projects, EntityManagerInterface $entityManager, #[CurrentUser] User $user): Response
    {
        $choices = $projects->findAccessibleBy($user);
        $form = $this->createForm(SkillContentType::class, new SkillData(), [
            'projects' => $choices,
            'require_project' => !$user->isAdmin(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $skill = new Skill($data->normalizedTitle(), $data->normalizedDescription(), $data->normalizedContent(), $user);
            foreach (FormData::entities(Project::class, $form->get('projects')->getData()) as $project) {
                $skill->addProject($project);
            }
            $entityManager->persist($skill);
            $entityManager->flush();
            $this->addFlash('success', \sprintf('Skill "%s" created.', $skill->getActiveVersion()->getTitle()));

            return $this->redirectToRoute('app_skill_show', ['uuid' => $skill->getUuid()]);
        }

        return $this->render('skills/new.html.twig', ['form' => $form, 'hasProjects' => [] !== $choices]);
    }

    #[Route('/{uuid}', name: 'app_skill_show', requirements: ['uuid' => Requirement::UUID], methods: ['GET', 'POST'])]
    #[IsGranted(SkillVoter::EDIT, subject: 'skill')]
    public function show(
        #[MapEntity(expr: self::FIND_SKILL)] Skill $skill,
        Request $request,
        ProjectRepository $projects,
        EntityManagerInterface $entityManager,
        #[CurrentUser] User $user,
    ): Response {
        $form = $this->createForm(SkillContentType::class, SkillData::fromVersion($skill->getEditableVersion()));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $draftButton = $form->get('saveDraft');
            $asDraft = $draftButton instanceof ClickableInterface && $draftButton->isClicked();
            $args = [$data->normalizedTitle(), $data->normalizedDescription(), $data->normalizedContent()];
            $version = $asDraft ? $skill->saveDraft(...$args) : $skill->saveFinal(...$args);
            if ($this->flushOrConflict($entityManager)) {
                $this->addFlash('success', \sprintf('Saved as %s %s.', $asDraft ? 'draft' : 'version', $version->getLabel()));
            }

            return $this->redirectToRoute('app_skill_show', ['uuid' => $skill->getUuid()]);
        }

        $editable = $projects->findAccessibleBy($user);

        return $this->render('skills/show.html.twig', [
            'skill' => $skill,
            'form' => $form,
            'projectsForm' => $this->createProjectsForm($skill, $editable),
            'hiddenProjectCount' => $skill->getProjects()->count() - \count($this->selectedAmong($skill, $editable)),
            'canDelete' => $this->isGranted(SkillVoter::DELETE, $skill),
        ]);
    }

    #[Route('/{uuid}/projects', name: 'app_skill_projects', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(SkillVoter::EDIT, subject: 'skill')]
    public function projects(
        #[MapEntity(expr: self::FIND_SKILL)] Skill $skill,
        Request $request,
        ProjectRepository $projects,
        RunAssignmentPruner $pruner,
        EntityManagerInterface $entityManager,
        #[CurrentUser] User $user,
    ): Response {
        $editable = $projects->findAccessibleBy($user);
        $form = $this->createProjectsForm($skill, $editable);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'The projects could not be saved.');
        } elseif (!$skill->replaceProjects($editable, FormData::entities(Project::class, $form->get('projects')->getData()), $user->isAdmin())) {
            $this->addFlash('error', 'A skill must keep at least one project.');
        } elseif ($this->flushOrConflict($entityManager, static fn () => $pruner->pruneSkill($skill))) {
            $this->addFlash('success', 'Projects saved.');

            if (!$this->isGranted(SkillVoter::VIEW, $skill)) {
                $this->addFlash('info', 'You no longer have access to this skill.');

                return $this->redirectToRoute('app_skills');
            }
        }

        return $this->redirectToRoute('app_skill_show', ['uuid' => $skill->getUuid()]);
    }

    #[Route('/{uuid}/versions/{number}', name: 'app_skill_version', requirements: ['uuid' => Requirement::UUID, 'number' => Requirements::ID], methods: ['GET'])]
    #[IsGranted(SkillVoter::VIEW, subject: 'skill')]
    public function version(#[MapEntity(expr: self::FIND_SKILL)] Skill $skill, int $number): Response
    {
        $version = $skill->findVersion($number) ?? throw $this->createNotFoundException();

        return $this->render('skills/version.html.twig', ['skill' => $skill, 'version' => $version]);
    }

    #[Route('/{uuid}/versions/{number}/restore', name: 'app_skill_restore', requirements: ['uuid' => Requirement::UUID, 'number' => Requirements::ID], methods: ['POST'])]
    #[IsGranted(SkillVoter::EDIT, subject: 'skill')]
    #[IsCsrfTokenValid(new Expression('"restore-skill-" ~ args["skill"].getUuid() ~ "-" ~ args["number"]'), methods: 'POST')]
    public function restore(
        #[MapEntity(expr: self::FIND_SKILL)] Skill $skill,
        int $number,
        VersionWorkflow $versions,
        EntityManagerInterface $entityManager,
    ): Response {
        [$type, $message] = $versions->restore($skill, $number);
        if ($this->flushOrConflict($entityManager)) {
            $this->addFlash($type, $message);
        }

        return $this->redirectToRoute('app_skill_show', ['uuid' => $skill->getUuid()]);
    }

    #[Route('/{uuid}/draft/discard', name: 'app_skill_draft_discard', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(SkillVoter::EDIT, subject: 'skill')]
    #[IsCsrfTokenValid(new Expression('"discard-skill-draft-" ~ args["skill"].getUuid()'), methods: 'POST')]
    public function discardDraft(
        #[MapEntity(expr: self::FIND_SKILL)] Skill $skill,
        VersionWorkflow $versions,
        EntityManagerInterface $entityManager,
    ): Response {
        [$type, $message] = $versions->discardDraft($skill);
        if ($this->flushOrConflict($entityManager)) {
            $this->addFlash($type, $message);
        }

        return $this->redirectToRoute('app_skill_show', ['uuid' => $skill->getUuid()]);
    }

    #[Route('/{uuid}/delete', name: 'app_skill_delete', requirements: ['uuid' => Requirement::UUID], methods: ['GET', 'POST'])]
    #[IsGranted(SkillVoter::DELETE, subject: 'skill')]
    #[IsCsrfTokenValid(new Expression('"delete-skill-" ~ args["skill"].getUuid()'), methods: 'POST')]
    public function delete(
        #[MapEntity(expr: self::FIND_SKILL)] Skill $skill,
        Request $request,
        RunAssignmentPruner $pruner,
        EntityManagerInterface $entityManager,
    ): Response {
        if (!$request->isMethod('POST')) {
            return $this->render('skills/delete.html.twig', ['skill' => $skill]);
        }

        $title = $skill->getActiveVersion()->getTitle();
        $skill->softDelete();
        if ($this->flushOrConflict($entityManager, static fn () => $pruner->pruneSkill($skill))) {
            $this->addFlash('success', \sprintf('Skill "%s" deleted.', $title));
        }

        return $this->redirectToRoute('app_skills');
    }

    /**
     * @param list<Project> $editable
     *
     * @return FormInterface<array<string, mixed>>
     */
    private function createProjectsForm(Skill $skill, array $editable): FormInterface
    {
        return $this->createForm(SkillProjectsType::class, ['projects' => new ArrayCollection($this->selectedAmong($skill, $editable))], [
            'action' => $this->generateUrl('app_skill_projects', ['uuid' => $skill->getUuid()]),
            'choices' => $editable,
        ]);
    }

    /**
     * @param list<Project> $editable
     *
     * @return list<Project>
     */
    private function selectedAmong(Skill $skill, array $editable): array
    {
        return array_values(array_filter($skill->getProjects()->toArray(), static fn (Project $project): bool => \in_array($project, $editable, true)));
    }
}
