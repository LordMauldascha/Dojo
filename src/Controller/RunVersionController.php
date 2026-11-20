<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Run;
use App\Routing\Requirements;
use App\Run\RunCompareQuery;
use App\Run\RunVersionDiffer;
use App\Security\Voter\RunVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class RunVersionController extends AbstractController
{
    #[Route('/runs/{uuid:run}/versions/{number}', name: 'app_run_version', requirements: ['uuid' => Requirement::UUID, 'number' => Requirements::ID], methods: ['GET'])]
    #[IsGranted(RunVoter::VIEW, subject: 'run')]
    public function version(Run $run, int $number): Response
    {
        $version = $run->findVersion($number) ?? throw $this->createNotFoundException();

        return $this->render('runs/version.html.twig', ['run' => $run, 'version' => $version]);
    }

    #[Route('/runs/{uuid:run}/compare', name: 'app_run_compare', requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted(RunVoter::VIEW, subject: 'run')]
    public function compare(
        Run $run,
        RunVersionDiffer $differ,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        RunCompareQuery $query = new RunCompareQuery(),
    ): Response {
        [$fromNumber, $toNumber] = $query->resolve($run->getLatestVersion()?->getNumber());

        $from = null === $fromNumber ? null : ($run->findVersion($fromNumber) ?? throw $this->createNotFoundException());
        $to = null === $toNumber ? null : ($run->findVersion($toNumber) ?? throw $this->createNotFoundException());

        return $this->render('runs/compare.html.twig', [
            'run' => $run,
            'from' => $from,
            'to' => $to,
            'diff' => null !== $from && null !== $to ? $differ->diff($from, $to) : null,
        ]);
    }
}
