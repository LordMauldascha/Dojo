<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Project;
use App\Entity\Skill;
use App\Entity\User;
use App\Form\Model\SkillData;
use App\Form\SkillContentType;
use App\Form\SkillProjectsType;
use App\Pagination\ListQuery;
use App\Pagination\PageSizeResolver;
use App\Repository\ProjectRepository;
use App\Repository\SkillRepository;
use App\Run\RunAssignmentPruner;
use App\Security\UserRole;
use App\Security\Voter\SkillVoter;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\ClickableInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/skills')]
final class SkillController extends AbstractController
{
    use CsrfGuardTrait;

    private const LIST_KEY = 'skills';
    private const FIND_SKILL = 'repository.findActiveByUuid(uuid)';

    #[Route('', name: 'app_skills', methods: ['GET'])]
    public function index(
        SkillRepository $skills,
        ProjectRepository $projects,
        PageSizeResolver $pageSizes,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        ListQuery $query = new ListQuery(),
    ): Response {
        $viewer = $this->currentUser();
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
    public function new(Request $request, ProjectRepository $projects, EntityManagerInterface $entityManager): Response
    {
        $user = $this->currentUser();
        $choices = $projects->findAccessibleBy($user);
        $form = $this->createForm(SkillContentType::class, new SkillData(), [
            'projects' => $choices,
            'require_project' => !$this->isAdmin($user),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $skill = new Skill($data->normalizedTitle(), $data->normalizedDescription(), $data->normalizedContent(), $user);
            foreach ($this->toList($form->get('projects')->getData()) as $project) {
                if ($project instanceof Project) {
                    $skill->addProject($project);
                }
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
    ): Response {
        $form = $this->createForm(SkillContentType::class, SkillData::fromVersion($skill->getEditableVersion()));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $draftButton = $form->get('saveDraft');
            $asDraft = $draftButton instanceof ClickableInterface && $draftButton->isClicked();
            $args = [$data->normalizedTitle(), $data->normalizedDescription(), $data->normalizedContent()];
            $version = $asDraft ? $skill->saveDraft(...$args) : $skill->saveFinal(...$args);
            $entityManager->flush();
            $this->addFlash('success', \sprintf('Saved as %s %s.', $asDraft ? 'draft' : 'version', $version->getLabel()));

            return $this->redirectToRoute('app_skill_show', ['uuid' => $skill->getUuid()]);
        }

        $editable = $projects->findAccessibleBy($this->currentUser());

        return $this->render('skills/show.html.twig', [
            'skill' => $skill,
            'form' => $form,
            'projectsForm' => $this->createProjectsForm($skill, $editable),
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
    ): Response {
        $user = $this->currentUser();
        $editable = $projects->findAccessibleBy($user);
        $form = $this->createProjectsForm($skill, $editable);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'The projects could not be saved.');

            return $this->redirectToRoute('app_skill_show', ['uuid' => $skill->getUuid()]);
        }

        $chosen = $this->toList($form->get('projects')->getData());
        $untouched = array_filter($skill->getProjects()->toArray(), static fn (Project $project): bool => !\in_array($project, $editable, true));
        if (!$this->isAdmin($user) && [] === $untouched && [] === $chosen) {
            $this->addFlash('error', 'A skill must keep at least one project.');

            return $this->redirectToRoute('app_skill_show', ['uuid' => $skill->getUuid()]);
        }

        foreach ($editable as $project) {
            \in_array($project, $chosen, true) ? $skill->addProject($project) : $skill->removeProject($project);
        }
        $entityManager->flush();
        $pruner->pruneSkill($skill);
        $this->addFlash('success', 'Projects saved.');

        if (!$this->isGranted(SkillVoter::VIEW, $skill)) {
            $this->addFlash('success', 'You no longer have access to this skill.');

            return $this->redirectToRoute('app_skills');
        }

        return $this->redirectToRoute('app_skill_show', ['uuid' => $skill->getUuid()]);
    }

    #[Route('/{uuid}/versions/{number}', name: 'app_skill_version', requirements: ['uuid' => Requirement::UUID, 'number' => Requirement::DIGITS], methods: ['GET'])]
    #[IsGranted(SkillVoter::VIEW, subject: 'skill')]
    public function version(#[MapEntity(expr: self::FIND_SKILL)] Skill $skill, int $number): Response
    {
        $version = $skill->findVersion($number) ?? throw $this->createNotFoundException();

        return $this->render('skills/version.html.twig', ['skill' => $skill, 'version' => $version]);
    }

    #[Route('/{uuid}/versions/{number}/restore', name: 'app_skill_restore', requirements: ['uuid' => Requirement::UUID, 'number' => Requirement::DIGITS], methods: ['POST'])]
    #[IsGranted(SkillVoter::EDIT, subject: 'skill')]
    public function restore(
        #[MapEntity(expr: self::FIND_SKILL)] Skill $skill,
        int $number,
        Request $request,
        EntityManagerInterface $entityManager,
    ): Response {
        $version = $skill->findVersion($number) ?? throw $this->createNotFoundException();
        $this->denyUnlessValidToken('restore-skill-'.$skill->getUuid().'-'.$number, $request);

        if (null !== $skill->getDraft()) {
            $this->addFlash('error', 'A draft exists: save it as final or discard it before restoring a version.');
        } elseif ($version->isDraft()) {
            $this->addFlash('error', 'A draft cannot be restored.');
        } else {
            $restored = $skill->restore($version);
            $entityManager->flush();
            $this->addFlash('success', \sprintf('Version %d restored as version %d.', $number, $restored->getNumber()));
        }

        return $this->redirectToRoute('app_skill_show', ['uuid' => $skill->getUuid()]);
    }

    #[Route('/{uuid}/draft/discard', name: 'app_skill_draft_discard', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(SkillVoter::EDIT, subject: 'skill')]
    public function discardDraft(
        #[MapEntity(expr: self::FIND_SKILL)] Skill $skill,
        Request $request,
        EntityManagerInterface $entityManager,
    ): Response {
        $this->denyUnlessValidToken('discard-skill-draft-'.$skill->getUuid(), $request);

        if (null === $skill->getDraft()) {
            $this->addFlash('error', 'There is no draft to discard.');
        } else {
            $skill->discardDraft();
            $entityManager->flush();
            $this->addFlash('success', 'Draft discarded.');
        }

        return $this->redirectToRoute('app_skill_show', ['uuid' => $skill->getUuid()]);
    }

    #[Route('/{uuid}/delete', name: 'app_skill_delete', requirements: ['uuid' => Requirement::UUID], methods: ['GET', 'POST'])]
    #[IsGranted(SkillVoter::DELETE, subject: 'skill')]
    public function delete(
        #[MapEntity(expr: self::FIND_SKILL)] Skill $skill,
        Request $request,
        RunAssignmentPruner $pruner,
        EntityManagerInterface $entityManager,
    ): Response {
        if ($request->isMethod('POST')) {
            $this->denyUnlessValidToken('delete-skill-'.$skill->getUuid(), $request);

            $title = $skill->getActiveVersion()->getTitle();
            $skill->softDelete();
            $entityManager->flush();
            $pruner->pruneSkill($skill);
            $this->addFlash('success', \sprintf('Skill "%s" deleted.', $title));

            return $this->redirectToRoute('app_skills');
        }

        return $this->render('skills/delete.html.twig', ['skill' => $skill]);
    }

    /**
     * @param list<Project> $editable
     *
     * @return FormInterface<array{projects: ArrayCollection<int, Project>}|array{projects: list<Project>}>
     */
    private function createProjectsForm(Skill $skill, array $editable): FormInterface
    {
        $selected = array_values(array_filter($skill->getProjects()->toArray(), static fn (Project $project): bool => \in_array($project, $editable, true)));

        return $this->createForm(SkillProjectsType::class, ['projects' => new ArrayCollection($selected)], [
            'action' => $this->generateUrl('app_skill_projects', ['uuid' => $skill->getUuid()]),
            'available_projects' => $editable,
        ]);
    }

    /** @return list<mixed> */
    private function toList(mixed $data): array
    {
        return \is_iterable($data) ? iterator_to_array($data, false) : [];
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }

    private function isAdmin(User $user): bool
    {
        return UserRole::Admin === $user->getRole();
    }
}
