<?php

declare(strict_types=1);

namespace App\Controller;

use App\Ai\EffortMapper;
use App\Entity\Run;
use App\Entity\RunResult;
use App\Entity\RunVersion;
use App\Entity\TestcaseVersion;
use App\Routing\Requirements;
use App\Run\RunCompareQuery;
use App\Run\RunVersionDiffer;
use App\Run\StaleResultSweeper;
use App\Security\Voter\RunVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
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
    public function version(Run $run, int $number, StaleResultSweeper $sweeper, RunVersionDiffer $differ): Response
    {
        $version = $run->findVersion($number) ?? throw $this->createNotFoundException();
        $sweeper->sweep($version);
        $previous = $run->findVersion($number - 1);

        return $this->render('runs/version.html.twig', [
            'run' => $run,
            'version' => $version,
            'matrix' => $this->matrixContext($version),
            'previous' => $previous,
            'comparison' => null === $previous ? [] : $differ->metrics($previous, $version),
        ]);
    }

    #[Route('/runs/{uuid:run}/versions/{number}/progress', name: 'app_run_version_progress', requirements: ['uuid' => Requirement::UUID, 'number' => Requirements::ID], methods: ['GET'])]
    #[IsGranted(RunVoter::VIEW, subject: 'run')]
    public function progress(Run $run, int $number, StaleResultSweeper $sweeper, RunVersionDiffer $differ): JsonResponse
    {
        $version = $run->findVersion($number) ?? throw $this->createNotFoundException();
        $sweeper->sweep($version);
        $matrix = $this->matrixContext($version);
        $previous = $run->findVersion($number - 1);

        return new JsonResponse([
            'status' => $version->getStatus()->value,
            'done' => $matrix['done'],
            'total' => $matrix['total'],
            'comparison' => null === $previous ? [] : $differ->metrics($previous, $version),
            'html' => $this->renderView('runs/_result_matrix.html.twig', ['version' => $version, 'matrix' => $matrix]),
        ]);
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

    /**
     * Notes hold the effort note per configuration key, cells are keyed "testcaseVersionId|configurationKey", rows are sorted by title, then id.
     *
     * @return array{done: int, total: int, notes: array<string, string|null>, cells: array<string, RunResult>, rows: list<TestcaseVersion>}
     */
    private function matrixContext(RunVersion $version): array
    {
        $notes = [];
        foreach ($version->getConfigurations() as $configuration) {
            $notes[$configuration->key()] = EffortMapper::map($configuration)['note'];
        }
        $cells = [];
        $done = 0;
        foreach ($version->getResults() as $result) {
            $cells[$result->getTestcaseVersion()->getId().'|'.$result->getConfigurationKey()] = $result;
            if (!$result->getStatus()->isOpen()) {
                ++$done;
            }
        }
        $rows = $version->getTestcaseVersions()->toArray();
        usort($rows, static fn (TestcaseVersion $a, TestcaseVersion $b): int => strnatcasecmp($a->getTitle(), $b->getTitle()) ?: $a->getId() <=> $b->getId());

        return ['done' => $done, 'total' => $version->getResults()->count(), 'notes' => $notes, 'cells' => $cells, 'rows' => $rows];
    }
}
