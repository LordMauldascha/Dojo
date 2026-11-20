<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Run;
use App\Run\RunCompareQuery;
use App\Run\RunVersionDiffer;
use App\Security\Voter\RunVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class RunVersionController extends AbstractController
{
    #[Route('/runs/{uuid}/versions/{number}', name: 'app_run_version', requirements: ['uuid' => Requirement::UUID, 'number' => Requirement::DIGITS], methods: ['GET'])]
    #[IsGranted(RunVoter::VIEW, subject: 'run')]
    public function version(#[MapEntity(mapping: ['uuid' => 'uuid'])] Run $run, int $number): Response
    {
        $version = $run->findVersion($number) ?? throw $this->createNotFoundException();

        return $this->render('runs/version.html.twig', ['run' => $run, 'version' => $version]);
    }

    #[Route('/runs/{uuid}/compare', name: 'app_run_compare', requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted(RunVoter::VIEW, subject: 'run')]
    public function compare(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Run $run,
        RunVersionDiffer $differ,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        RunCompareQuery $query = new RunCompareQuery(),
    ): Response {
        $latest = $run->getLatestVersion();
        $toNumber = $query->to ?? $latest?->getNumber();
        $fromNumber = $query->from ?? (null === $toNumber || 1 >= $toNumber ? null : $toNumber - 1);

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
