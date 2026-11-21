<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Group;
use App\Entity\Project;
use App\Entity\Skill;
use App\Entity\User;
use App\Form\AddGroupType;
use App\Form\AddSkillType;
use App\Form\AddUserType;
use App\Form\ProjectType;
use App\Pagination\ListQuery;
use App\Pagination\PageSizeResolver;
use App\Repository\ProjectRepository;
use App\Repository\RunRepository;
use App\Repository\SkillRepository;
use App\Repository\TestcaseRepository;
use App\Routing\Requirements;
use App\Run\RunAssignmentPruner;
use App\Security\Voter\ProjectVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
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
#[Route('/projects')]
final class ProjectController extends AbstractController
{
    use SafeFlushTrait;

    private const LIST_KEY = 'projects';

    #[Route('', name: 'app_projects', methods: ['GET'])]
    public function index(
        ProjectRepository $projects,
        PageSizeResolver $pageSizes,
        #[CurrentUser] User $viewer,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        ListQuery $query = new ListQuery(),
    ): Response {
        return $this->render('projects/index.html.twig', [
            'page' => $projects->paginate($query, $pageSizes->resolve(self::LIST_KEY, $query->perPage), $viewer),
            'query' => $query,
        ]);
    }

    #[IsGranted('ROLE_ADMIN')]
    #[Route('/new', name: 'app_project_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $project = new Project();
        $form = $this->createForm(ProjectType::class, $project);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($project);
            if (!$this->flushOrConflict($entityManager)) {
                return $this->redirectToRoute('app_project_new');
            }
            $this->addFlash('success', \sprintf('Project "%s" created.', $project->getTitle()));

            return $this->redirectToRoute('app_project_show', ['uuid' => $project->getUuid()]);
        }

        return $this->render('projects/new.html.twig', ['form' => $form]);
    }

    #[Route('/{uuid:project}', name: 'app_project_show', requirements: ['uuid' => Requirement::UUID], methods: ['GET', 'POST'])]
    #[IsGranted(ProjectVoter::VIEW, subject: 'project')]
    public function show(
        Project $project,
        Request $request,
        ProjectRepository $projects,
        SkillRepository $skills,
        TestcaseRepository $testcases,
        RunRepository $runs,
        EntityManagerInterface $entityManager,
    ): Response {
        $canManage = $this->isGranted(ProjectVoter::MANAGE, $project);
        if (!$canManage && $request->isMethod('POST')) {
            throw $this->createAccessDeniedException();
        }

        $form = null;
        if ($canManage) {
            $form = $this->createForm(ProjectType::class, $project);
            $form->handleRequest($request);

            if ($form->isSubmitted() && $form->isValid()) {
                if ($this->flushOrConflict($entityManager)) {
                    $this->addFlash('success', 'Project saved.');
                }

                return $this->redirectToRoute('app_project_show', ['uuid' => $project->getUuid()]);
            }
        }

        return $this->render('projects/show.html.twig', [
            'project' => $project,
            'canManage' => $canManage,
            'skills' => $skills->findByProject($project),
            'testcaseCount' => $testcases->countByProject($project),
            'runCount' => $runs->countByProject($project),
            'form' => $form,
            'addGroupForm' => $canManage ? $this->createAddGroupForm($project, $projects) : null,
            'addUserForm' => $canManage ? $this->createAddUserForm($project, $projects) : null,
            'addSkillForm' => $canManage ? $this->createAddSkillForm($project, $skills) : null,
        ]);
    }

    #[Route('/{uuid:project}/groups', name: 'app_project_group_add', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
    public function addGroup(
        Project $project,
        Request $request,
        ProjectRepository $projects,
        EntityManagerInterface $entityManager,
    ): Response {
        $form = $this->createAddGroupForm($project, $projects);
        $form->handleRequest($request);

        $group = $form->isSubmitted() && $form->isValid() ? $form->get('group')->getData() : null;
        if (!$group instanceof Group) {
            $this->addFlash('error', 'The group could not be added.');
        } else {
            $project->addGroup($group);
            if ($this->flushOrConflict($entityManager)) {
                $this->addFlash('success', \sprintf('Group "%s" added.', $group->getTitle()));
            }
        }

        return $this->redirectToRoute('app_project_show', ['uuid' => $project->getUuid()]);
    }

    #[Route('/{uuid:project}/groups/{groupUuid}/remove', name: 'app_project_group_remove', requirements: ['uuid' => Requirement::UUID, 'groupUuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
    #[IsCsrfTokenValid(new Expression('"remove-project-group-" ~ args["project"].getUuid() ~ "-" ~ args["group"].getUuid()'), methods: 'POST')]
    public function removeGroup(
        Project $project,
        #[MapEntity(mapping: ['groupUuid' => 'uuid'])] Group $group,
        EntityManagerInterface $entityManager,
    ): Response {
        $project->removeGroup($group);
        $entityManager->flush();
        $this->addFlash('success', \sprintf('Group "%s" removed.', $group->getTitle()));

        return $this->redirectToRoute('app_project_show', ['uuid' => $project->getUuid()]);
    }

    #[Route('/{uuid:project}/users', name: 'app_project_user_add', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
    public function addUser(
        Project $project,
        Request $request,
        ProjectRepository $projects,
        EntityManagerInterface $entityManager,
    ): Response {
        $form = $this->createAddUserForm($project, $projects);
        $form->handleRequest($request);

        $user = $form->isSubmitted() && $form->isValid() ? $form->get('user')->getData() : null;
        if (!$user instanceof User) {
            $this->addFlash('error', 'The user could not be added.');
        } else {
            $project->addUser($user);
            if ($this->flushOrConflict($entityManager)) {
                $this->addFlash('success', \sprintf('User "%s" added.', $user->getUserIdentifier()));
            }
        }

        return $this->redirectToRoute('app_project_show', ['uuid' => $project->getUuid()]);
    }

    #[Route('/{uuid:project}/users/{userId}/remove', name: 'app_project_user_remove', requirements: ['uuid' => Requirement::UUID, 'userId' => Requirements::ID], methods: ['POST'])]
    #[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
    #[IsCsrfTokenValid(new Expression('"remove-project-user-" ~ args["project"].getUuid() ~ "-" ~ args["user"].getId()'), methods: 'POST')]
    public function removeUser(
        Project $project,
        #[MapEntity(id: 'userId')] User $user,
        EntityManagerInterface $entityManager,
    ): Response {
        $project->removeUser($user);
        $entityManager->flush();
        $this->addFlash('success', \sprintf('User "%s" removed.', $user->getUserIdentifier()));

        return $this->redirectToRoute('app_project_show', ['uuid' => $project->getUuid()]);
    }

    #[Route('/{uuid:project}/skills', name: 'app_project_skill_add', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
    public function addSkill(
        Project $project,
        Request $request,
        SkillRepository $skills,
        EntityManagerInterface $entityManager,
    ): Response {
        $form = $this->createAddSkillForm($project, $skills);
        $form->handleRequest($request);

        $skill = $form->isSubmitted() && $form->isValid() ? $form->get('skill')->getData() : null;
        if (!$skill instanceof Skill) {
            $this->addFlash('error', 'The skill could not be added.');
        } else {
            $skill->addProject($project);
            if ($this->flushOrConflict($entityManager)) {
                $this->addFlash('success', \sprintf('Skill "%s" added.', $skill->getActiveVersion()->getTitle()));
            }
        }

        return $this->redirectToRoute('app_project_show', ['uuid' => $project->getUuid()]);
    }

    #[Route('/{uuid:project}/skills/{skillUuid}/remove', name: 'app_project_skill_remove', requirements: ['uuid' => Requirement::UUID, 'skillUuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
    #[IsCsrfTokenValid(new Expression('"remove-project-skill-" ~ args["project"].getUuid() ~ "-" ~ args["skill"].getUuid()'), methods: 'POST')]
    public function removeSkill(
        Project $project,
        #[MapEntity(expr: 'repository.findActiveByUuid(skillUuid)')] Skill $skill,
        RunAssignmentPruner $pruner,
        EntityManagerInterface $entityManager,
    ): Response {
        $skill->removeProject($project);
        if ($this->flushOrConflict($entityManager, static fn () => $pruner->pruneSkill($skill))) {
            $this->addFlash('success', \sprintf('Skill "%s" removed.', $skill->getActiveVersion()->getTitle()));
        }

        return $this->redirectToRoute('app_project_show', ['uuid' => $project->getUuid()]);
    }

    #[Route('/{uuid:project}/delete', name: 'app_project_delete', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
    #[IsCsrfTokenValid(new Expression('"delete-project-" ~ args["project"].getUuid()'), methods: 'POST')]
    public function delete(Project $project, EntityManagerInterface $entityManager): Response
    {
        $title = $project->getTitle();
        $entityManager->remove($project);
        $entityManager->flush();
        $this->addFlash('success', \sprintf('Project "%s" deleted.', $title));

        return $this->redirectToRoute('app_projects');
    }

    /** @return FormInterface<array<string, mixed>|null> */
    private function createAddGroupForm(Project $project, ProjectRepository $projects): FormInterface
    {
        return $this->createForm(AddGroupType::class, null, [
            'action' => $this->generateUrl('app_project_group_add', ['uuid' => $project->getUuid()]),
            'choices' => $projects->findAssignableGroups($project),
        ]);
    }

    /** @return FormInterface<array<string, mixed>|null> */
    private function createAddSkillForm(Project $project, SkillRepository $skills): FormInterface
    {
        return $this->createForm(AddSkillType::class, null, [
            'action' => $this->generateUrl('app_project_skill_add', ['uuid' => $project->getUuid()]),
            'choices' => $skills->findAssignableToProject($project),
        ]);
    }

    /** @return FormInterface<array<string, mixed>|null> */
    private function createAddUserForm(Project $project, ProjectRepository $projects): FormInterface
    {
        return $this->createForm(AddUserType::class, null, [
            'action' => $this->generateUrl('app_project_user_add', ['uuid' => $project->getUuid()]),
            'choices' => $projects->findAssignableUsers($project),
        ]);
    }
}
