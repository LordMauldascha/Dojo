<?php

declare(strict_types=1);

namespace App\Controller;

use App\Ai\Provider;
use App\Entity\User;
use App\Security\ApiKeyStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function __invoke(ApiKeyStore $store, EntityManagerInterface $entityManager, #[CurrentUser] User $user): Response
    {
        $stored = $store->findAll();

        return $this->render('home.html.twig', [
            'rows' => array_map(static fn (Provider $provider): array => ['provider' => $provider, 'key' => $stored[$provider->value] ?? null], Provider::cases()),
            'userCount' => $user->isAdmin() ? $entityManager->getRepository(User::class)->count([]) : null,
        ]);
    }
}
