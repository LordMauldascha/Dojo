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
use App\Repository\SkillRepository;
use App\Security\Voter\ProjectVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/projects')]
final class ProjectController extends AbstractController
{
    use CsrfGuardTrait;

    private const LIST_KEY = 'projects';

    #[Route('', name: 'app_projects', methods: ['GET'])]
    public function index(
        ProjectRepository $projects,
        PageSizeResolver $pageSizes,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        ListQuery $query = new ListQuery(),
    ): Response {
        $viewer = $this->getUser();
        \assert($viewer instanceof User);

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
            $entityManager->flush();
            $this->addFlash('success', \sprintf('Project "%s" created.', $project->getTitle()));

            return $this->redirectToRoute('app_project_show', ['uuid' => $project->getUuid()]);
        }

        return $this->render('projects/new.html.twig', ['form' => $form]);
    }

    #[Route('/{uuid}', name: 'app_project_show', requirements: ['uuid' => Requirement::UUID], methods: ['GET', 'POST'])]
    #[IsGranted(ProjectVoter::VIEW, subject: 'project')]
    public function show(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Project $project,
        Request $request,
        ProjectRepository $projects,
        SkillRepository $skills,
        EntityManagerInterface $entityManager,
    ): Response {
        $projectSkills = $skills->findByProject($project);

        if (!$this->isGranted(ProjectVoter::MANAGE, $project)) {
            if ($request->isMethod('POST')) {
                throw $this->createAccessDeniedException();
            }

            return $this->render('projects/show.html.twig', ['project' => $project, 'canManage' => false, 'skills' => $projectSkills]);
        }

        $form = $this->createForm(ProjectType::class, $project);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Project saved.');

            return $this->redirectToRoute('app_project_show', ['uuid' => $project->getUuid()]);
        }

        return $this->render('projects/show.html.twig', [
            'project' => $project,
            'canManage' => true,
            'skills' => $projectSkills,
            'form' => $form,
            'addGroupForm' => $this->createAddGroupForm($project, $projects),
            'addUserForm' => $this->createAddUserForm($project, $projects),
            'addSkillForm' => $this->createAddSkillForm($project, $skills),
        ]);
    }

    #[Route('/{uuid}/groups', name: 'app_project_group_add', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
    public function addGroup(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Project $project,
        Request $request,
        ProjectRepository $projects,
        EntityManagerInterface $entityManager,
    ): Response {
        $form = $this->createAddGroupForm($project, $projects);
        $form->handleRequest($request);

        $group = $form->isSubmitted() && $form->isValid() ? $form->get('group')->getData() : null;
        if ($group instanceof Group) {
            $project->addGroup($group);
            $entityManager->flush();
            $this->addFlash('success', \sprintf('Group "%s" added.', $group->getTitle()));
        } else {
            $this->addFlash('error', 'The group could not be added.');
        }

        return $this->redirectToRoute('app_project_show', ['uuid' => $project->getUuid()]);
    }

    #[Route('/{uuid}/groups/{groupUuid}/remove', name: 'app_project_group_remove', requirements: ['uuid' => Requirement::UUID, 'groupUuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
    public function removeGroup(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Project $project,
        #[MapEntity(mapping: ['groupUuid' => 'uuid'])] Group $group,
        Request $request,
        EntityManagerInterface $entityManager,
    ): Response {
        $this->denyUnlessValidToken('remove-project-group-'.$project->getUuid().'-'.$group->getUuid(), $request);

        $project->removeGroup($group);
        $entityManager->flush();
        $this->addFlash('success', \sprintf('Group "%s" removed.', $group->getTitle()));

        return $this->redirectToRoute('app_project_show', ['uuid' => $project->getUuid()]);
    }

    #[Route('/{uuid}/users', name: 'app_project_user_add', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
    public function addUser(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Project $project,
        Request $request,
        ProjectRepository $projects,
        EntityManagerInterface $entityManager,
    ): Response {
        $form = $this->createAddUserForm($project, $projects);
        $form->handleRequest($request);

        $user = $form->isSubmitted() && $form->isValid() ? $form->get('user')->getData() : null;
        if ($user instanceof User) {
            $project->addUser($user);
            $entityManager->flush();
            $this->addFlash('success', \sprintf('User "%s" added.', $user->getUserIdentifier()));
        } else {
            $this->addFlash('error', 'The user could not be added.');
        }

        return $this->redirectToRoute('app_project_show', ['uuid' => $project->getUuid()]);
    }

    #[Route('/{uuid}/users/{userId}/remove', name: 'app_project_user_remove', requirements: ['uuid' => Requirement::UUID, 'userId' => Requirement::DIGITS], methods: ['POST'])]
    #[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
    public function removeUser(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Project $project,
        #[MapEntity(id: 'userId')] User $user,
        Request $request,
        EntityManagerInterface $entityManager,
    ): Response {
        $this->denyUnlessValidToken('remove-project-user-'.$project->getUuid().'-'.$user->getId(), $request);

        $project->removeUser($user);
        $entityManager->flush();
        $this->addFlash('success', \sprintf('User "%s" removed.', $user->getUserIdentifier()));

        return $this->redirectToRoute('app_project_show', ['uuid' => $project->getUuid()]);
    }

    #[Route('/{uuid}/skills', name: 'app_project_skill_add', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
    public function addSkill(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Project $project,
        Request $request,
        SkillRepository $skills,
        EntityManagerInterface $entityManager,
    ): Response {
        $form = $this->createAddSkillForm($project, $skills);
        $form->handleRequest($request);

        $skill = $form->isSubmitted() && $form->isValid() ? $form->get('skill')->getData() : null;
        if ($skill instanceof Skill) {
            $skill->addProject($project);
            $entityManager->flush();
            $this->addFlash('success', \sprintf('Skill "%s" added.', $skill->getActiveVersion()->getTitle()));
        } else {
            $this->addFlash('error', 'The skill could not be added.');
        }

        return $this->redirectToRoute('app_project_show', ['uuid' => $project->getUuid()]);
    }

    #[Route('/{uuid}/skills/{skillUuid}/remove', name: 'app_project_skill_remove', requirements: ['uuid' => Requirement::UUID, 'skillUuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
    public function removeSkill(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Project $project,
        #[MapEntity(expr: 'repository.findActiveByUuid(skillUuid)')] Skill $skill,
        Request $request,
        EntityManagerInterface $entityManager,
    ): Response {
        $this->denyUnlessValidToken('remove-project-skill-'.$project->getUuid().'-'.$skill->getUuid(), $request);

        $skill->removeProject($project);
        $entityManager->flush();
        $this->addFlash('success', \sprintf('Skill "%s" removed.', $skill->getActiveVersion()->getTitle()));

        return $this->redirectToRoute('app_project_show', ['uuid' => $project->getUuid()]);
    }

    #[Route('/{uuid}/delete', name: 'app_project_delete', requirements: ['uuid' => Requirement::UUID], methods: ['GET', 'POST'])]
    #[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
    public function delete(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Project $project,
        Request $request,
        SkillRepository $skills,
        EntityManagerInterface $entityManager,
    ): Response {
        if ($request->isMethod('POST')) {
            $this->denyUnlessValidToken('delete-project-'.$project->getUuid(), $request);

            $title = $project->getTitle();
            $entityManager->remove($project);
            $entityManager->flush();
            $this->addFlash('success', \sprintf('Project "%s" deleted.', $title));

            return $this->redirectToRoute('app_projects');
        }

        return $this->render('projects/delete.html.twig', ['project' => $project, 'skillCount' => \count($skills->findByProject($project))]);
    }

    /** @return FormInterface<array{group: Group|null}|null> */
    private function createAddGroupForm(Project $project, ProjectRepository $projects): FormInterface
    {
        return $this->createForm(AddGroupType::class, null, [
            'action' => $this->generateUrl('app_project_group_add', ['uuid' => $project->getUuid()]),
            'available_groups' => $projects->findAssignableGroups($project),
        ]);
    }

    /** @return FormInterface<array{skill: Skill|null}|null> */
    private function createAddSkillForm(Project $project, SkillRepository $skills): FormInterface
    {
        return $this->createForm(AddSkillType::class, null, [
            'action' => $this->generateUrl('app_project_skill_add', ['uuid' => $project->getUuid()]),
            'available_skills' => $skills->findAssignableToProject($project),
        ]);
    }

    /** @return FormInterface<array{user: User|null}|null> */
    private function createAddUserForm(Project $project, ProjectRepository $projects): FormInterface
    {
        return $this->createForm(AddUserType::class, null, [
            'action' => $this->generateUrl('app_project_user_add', ['uuid' => $project->getUuid()]),
            'available_users' => $projects->findAssignableUsers($project),
        ]);
    }
}
