<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\UserCreateType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
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
            $this->addFlash('success', \sprintf('Benutzer "%s" angelegt.', $user->getUserIdentifier()));

            return $this->redirectToRoute('app_admin_users');
        }

        return $this->render('admin/users.html.twig', [
            'users' => $entityManager->getRepository(User::class)->findBy([], ['username' => 'ASC']),
            'form' => $form,
        ]);
    }

    #[Route('/admin/users/{id}/toggle', name: 'app_admin_user_toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggle(User $user, Request $request, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCurrentUser($user)) {
            return $this->rejectSelfAction();
        }
        $this->denyUnlessValidToken('toggle-user-'.$user->getId(), $request);

        $user->setEnabled(!$user->isEnabled());
        $entityManager->flush();
        $this->addFlash('success', \sprintf('Benutzer "%s" %s.', $user->getUserIdentifier(), $user->isEnabled() ? 'aktiviert' : 'deaktiviert'));

        return $this->redirectToRoute('app_admin_users');
    }

    #[Route('/admin/users/{id}/delete', name: 'app_admin_user_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(User $user, Request $request, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCurrentUser($user)) {
            return $this->rejectSelfAction();
        }
        $this->denyUnlessValidToken('delete-user-'.$user->getId(), $request);

        $username = $user->getUserIdentifier();
        $entityManager->remove($user);
        $entityManager->flush();
        $this->addFlash('success', \sprintf('Benutzer "%s" gelöscht.', $username));

        return $this->redirectToRoute('app_admin_users');
    }

    private function isCurrentUser(User $user): bool
    {
        return $this->getUser()?->getUserIdentifier() === $user->getUserIdentifier();
    }

    private function rejectSelfAction(): Response
    {
        $this->addFlash('error', 'Das eigene Konto kann nicht deaktiviert oder gelöscht werden.');

        return $this->redirectToRoute('app_admin_users');
    }

    private function denyUnlessValidToken(string $tokenId, Request $request): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
