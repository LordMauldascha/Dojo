<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\UserCreateType;
use App\Routing\Requirements;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class UserController extends AbstractController
{
    #[Route('/admin/users', name: 'app_admin_users', methods: ['GET', 'POST'])]
    public function index(Request $request, EntityManagerInterface $entityManager, UserPasswordHasherInterface $hasher): Response
    {
        $user = new User();
        $form = $this->createForm(UserCreateType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = $form->get('plainPassword')->getData();
            \assert(\is_string($plainPassword));
            $user->setPassword($hasher->hashPassword($user, $plainPassword));
            $entityManager->persist($user);
            $entityManager->flush();
            $this->addFlash('success', \sprintf('User "%s" created.', $user->getUserIdentifier()));

            return $this->redirectToRoute('app_admin_users');
        }

        return $this->render('admin/users.html.twig', [
            'users' => $entityManager->getRepository(User::class)->findBy([], ['username' => 'ASC']),
            'form' => $form,
        ]);
    }

    #[Route('/admin/users/{id}/toggle', name: 'app_admin_user_toggle', requirements: ['id' => Requirements::ID], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"toggle-user-" ~ args["user"].getId()'), methods: 'POST')]
    public function toggle(User $user, #[CurrentUser] User $current, EntityManagerInterface $entityManager): Response
    {
        if ($current->getId() === $user->getId()) {
            return $this->rejectSelfAction();
        }

        $user->setEnabled(!$user->isEnabled());
        $entityManager->flush();
        $this->addFlash('success', \sprintf('User "%s" %s.', $user->getUserIdentifier(), $user->isEnabled() ? 'enabled' : 'disabled'));

        return $this->redirectToRoute('app_admin_users');
    }

    #[Route('/admin/users/{id}/delete', name: 'app_admin_user_delete', requirements: ['id' => Requirements::ID], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"delete-user-" ~ args["user"].getId()'), methods: 'POST')]
    public function delete(User $user, #[CurrentUser] User $current, EntityManagerInterface $entityManager): Response
    {
        if ($current->getId() === $user->getId()) {
            return $this->rejectSelfAction();
        }

        $username = $user->getUserIdentifier();
        $entityManager->remove($user);
        $entityManager->flush();
        $this->addFlash('success', \sprintf('User "%s" deleted.', $username));

        return $this->redirectToRoute('app_admin_users');
    }

    private function rejectSelfAction(): Response
    {
        $this->addFlash('error', 'You cannot disable or delete your own account.');

        return $this->redirectToRoute('app_admin_users');
    }
}
