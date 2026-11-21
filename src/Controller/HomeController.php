<?php

declare(strict_types=1);

namespace App\Controller;

use App\Ai\Provider;
use App\Entity\User;
use App\Repository\GroupRepository;
use App\Repository\ProjectRepository;
use App\Repository\RunRepository;
use App\Repository\SkillRepository;
use App\Repository\TestcaseRepository;
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
    private const PROJECT_LIMIT = 10;
    private const RECENT_LIMIT = 5;

    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function __invoke(
        ProjectRepository $projects,
        RunRepository $runs,
        SkillRepository $skills,
        TestcaseRepository $testcases,
        GroupRepository $groups,
        ApiKeyStore $store,
        EntityManagerInterface $entityManager,
        #[CurrentUser] User $user,
    ): Response {
        $admin = null;
        if ($user->isAdmin()) {
            $admin = [
                'providerCount' => \count(Provider::cases()),
                'missing' => array_values(array_filter(Provider::cases(), static fn (Provider $provider): bool => !$store->hasProvider($provider))),
                'userCount' => $entityManager->getRepository(User::class)->count([]),
                'groupCount' => $groups->count([]),
            ];
        }

        return $this->render('home.html.twig', [
            'projects' => $projects->findOverview($user, self::PROJECT_LIMIT),
            'runVersions' => $runs->findRecentVersions($user, self::RECENT_LIMIT),
            'skillDrafts' => $skills->findRecentDrafts($user, self::RECENT_LIMIT),
            'testcaseDrafts' => $testcases->findRecentDrafts($user, self::RECENT_LIMIT),
            'admin' => $admin,
        ]);
    }
}
