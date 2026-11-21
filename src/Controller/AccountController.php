<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\ChangePasswordType;
use App\Repository\GroupRepository;
use App\Repository\ProjectRepository;
use App\Repository\RunRepository;
use App\Repository\SkillRepository;
use App\Repository\TestcaseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class AccountController extends AbstractController
{
    private const PROJECT_LIMIT = 10;

    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly GroupRepository $groups,
        private readonly SkillRepository $skills,
        private readonly TestcaseRepository $testcases,
        private readonly RunRepository $runs,
    ) {
    }

    #[Route('/account', name: 'app_account', methods: ['GET'])]
    public function index(#[CurrentUser] User $user): Response
    {
        return $this->renderAccount($user, $this->createForm(ChangePasswordType::class));
    }

    /** The form lives in a dialog on the account page; an invalid submit shows that page again with the dialog open. */
    #[Route('/account/password', name: 'app_account_password', methods: ['POST'])]
    public function password(Request $request, EntityManagerInterface $entityManager, UserPasswordHasherInterface $hasher, #[CurrentUser] User $user): Response
    {
        $form = $this->createForm(ChangePasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $newPassword = $form->get('newPassword')->getData();
            \assert(\is_string($newPassword));
            $user->setPassword($hasher->hashPassword($user, $newPassword));
            $entityManager->flush();
            $this->addFlash('success', 'Password changed.');

            return $this->redirectToRoute('app_account');
        }

        return $this->renderAccount($user, $form);
    }

    /** @param FormInterface<array<string, mixed>|null> $form */
    private function renderAccount(User $user, FormInterface $form): Response
    {
        $memberships = $this->groups->findByMember($user);

        return $this->render('account/index.html.twig', [
            'form' => $form,
            'projects' => $this->projects->findOverview($user, self::PROJECT_LIMIT),
            'groups' => $memberships,
            'counts' => [
                'projects' => $this->projects->countAccessibleBy($user),
                'groups' => \count($memberships),
                'skills' => $this->skills->countCreatedBy($user),
                'testcases' => $this->testcases->countCreatedBy($user),
                'runs' => $this->runs->countCreatedBy($user),
            ],
        ]);
    }
}
