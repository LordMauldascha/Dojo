<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Group;
use App\Entity\User;
use App\Form\AddUserType;
use App\Form\GroupType;
use App\Pagination\ListQuery;
use App\Pagination\PageSizeResolver;
use App\Repository\GroupRepository;
use App\Repository\ProjectRepository;
use App\Routing\Requirements;
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
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/groups')]
final class GroupController extends AbstractController
{
    use SafeFlushTrait;

    private const LIST_KEY = 'groups';

    #[Route('', name: 'app_admin_groups', methods: ['GET'])]
    public function index(
        GroupRepository $groups,
        PageSizeResolver $pageSizes,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        ListQuery $query = new ListQuery(),
    ): Response {
        $page = $groups->paginate($query, $pageSizes->resolve(self::LIST_KEY, $query->perPage));

        return $this->render('admin/groups/index.html.twig', [
            'page' => $page,
            'query' => $query,
        ]);
    }

    #[Route('/new', name: 'app_admin_group_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $group = new Group();
        $form = $this->createForm(GroupType::class, $group);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($group);
            if (!$this->flushOrConflict($entityManager)) {
                return $this->redirectToRoute('app_admin_group_new');
            }
            $this->addFlash('success', \sprintf('Group "%s" created.', $group->getTitle()));

            return $this->redirectToRoute('app_admin_group_show', ['uuid' => $group->getUuid()]);
        }

        return $this->render('admin/groups/new.html.twig', ['form' => $form]);
    }

    #[Route('/{uuid:group}', name: 'app_admin_group_show', requirements: ['uuid' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function show(
        Group $group,
        Request $request,
        GroupRepository $groups,
        ProjectRepository $projects,
        EntityManagerInterface $entityManager,
    ): Response {
        $form = $this->createForm(GroupType::class, $group);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($this->flushOrConflict($entityManager)) {
                $this->addFlash('success', 'Group saved.');
            }

            return $this->redirectToRoute('app_admin_group_show', ['uuid' => $group->getUuid()]);
        }

        return $this->render('admin/groups/show.html.twig', [
            'group' => $group,
            'projects' => $projects->findByGroup($group),
            'form' => $form,
            'addUserForm' => $this->createAddUserForm($group, $groups),
        ]);
    }

    #[Route('/{uuid:group}/users', name: 'app_admin_group_user_add', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    public function addUser(
        Group $group,
        Request $request,
        GroupRepository $groups,
        EntityManagerInterface $entityManager,
    ): Response {
        $form = $this->createAddUserForm($group, $groups);
        $form->handleRequest($request);

        $user = $form->isSubmitted() && $form->isValid() ? $form->get('user')->getData() : null;
        if (!$user instanceof User) {
            $this->addFlash('error', 'The user could not be added.');
        } else {
            $group->addUser($user);
            if ($this->flushOrConflict($entityManager)) {
                $this->addFlash('success', \sprintf('User "%s" added.', $user->getUserIdentifier()));
            }
        }

        return $this->redirectToRoute('app_admin_group_show', ['uuid' => $group->getUuid()]);
    }

    #[Route('/{uuid:group}/users/{userId}/remove', name: 'app_admin_group_user_remove', requirements: ['uuid' => Requirement::UUID, 'userId' => Requirements::ID], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"remove-group-user-" ~ args["group"].getUuid() ~ "-" ~ args["user"].getId()'), methods: 'POST')]
    public function removeUser(
        Group $group,
        #[MapEntity(id: 'userId')] User $user,
        EntityManagerInterface $entityManager,
    ): Response {
        $group->removeUser($user);
        $entityManager->flush();
        $this->addFlash('success', \sprintf('User "%s" removed.', $user->getUserIdentifier()));

        return $this->redirectToRoute('app_admin_group_show', ['uuid' => $group->getUuid()]);
    }

    #[Route('/{uuid:group}/delete', name: 'app_admin_group_delete', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"delete-group-" ~ args["group"].getUuid()'), methods: 'POST')]
    public function delete(Group $group, EntityManagerInterface $entityManager): Response
    {
        $title = $group->getTitle();
        $entityManager->remove($group);
        $entityManager->flush();
        $this->addFlash('success', \sprintf('Group "%s" deleted.', $title));

        return $this->redirectToRoute('app_admin_groups');
    }

    /** @return FormInterface<array<string, mixed>|null> */
    private function createAddUserForm(Group $group, GroupRepository $groups): FormInterface
    {
        return $this->createForm(AddUserType::class, null, [
            'action' => $this->generateUrl('app_admin_group_user_add', ['uuid' => $group->getUuid()]),
            'choices' => $groups->findAssignableUsers($group),
        ]);
    }
}
