<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Project;
use App\Entity\Testcase;
use App\Entity\User;
use App\Form\FormData;
use App\Form\Model\TestcaseData;
use App\Form\TestcaseContentType;
use App\Form\TestcaseProjectsType;
use App\Pagination\ListQuery;
use App\Pagination\PageSizeResolver;
use App\Repository\ProjectRepository;
use App\Repository\TestcaseRepository;
use App\Routing\Requirements;
use App\Run\RunAssignmentPruner;
use App\Security\Voter\TestcaseVoter;
use App\Versioning\VersionWorkflow;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\ClickableInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/testcases')]
final class TestcaseController extends AbstractController
{
    use SafeFlushTrait;

    private const LIST_KEY = 'testcases';
    private const FIND_TESTCASE = 'repository.findActiveByUuid(uuid)';

    #[Route('', name: 'app_testcases', methods: ['GET'])]
    public function index(
        TestcaseRepository $testcases,
        ProjectRepository $projects,
        PageSizeResolver $pageSizes,
        #[CurrentUser] User $viewer,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        ListQuery $query = new ListQuery(),
    ): Response {
        $accessible = $projects->findAccessibleBy($viewer);
        $page = $testcases->paginate($query, $pageSizes->resolve(self::LIST_KEY, $query->perPage), $viewer);

        return $this->render('testcases/index.html.twig', [
            'page' => $page,
            'draftNumbers' => $testcases->draftNumbers($page->items),
            'query' => $query,
            'projectChoices' => $accessible,
            'visibleProjectIds' => array_map(static fn (Project $project): ?int => $project->getId(), $accessible),
        ]);
    }

    #[Route('/new', name: 'app_testcase_new', methods: ['GET', 'POST'])]
    public function new(Request $request, ProjectRepository $projects, EntityManagerInterface $entityManager, #[CurrentUser] User $user): Response
    {
        $choices = $projects->findAccessibleBy($user);
        $form = $this->createForm(TestcaseContentType::class, new TestcaseData(), [
            'projects' => $choices,
            'require_project' => !$user->isAdmin(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $testcase = new Testcase(
                $data->normalizedTitle(),
                $data->normalizedDescription(),
                $data->normalizedPrompt(),
                $data->normalizedOutputCriteria(),
                $user,
            );
            foreach (FormData::entities(Project::class, $form->get('projects')->getData()) as $project) {
                $testcase->addProject($project);
            }
            $entityManager->persist($testcase);
            $entityManager->flush();
            $this->addFlash('success', \sprintf('Testcase "%s" created.', $testcase->getActiveVersion()->getTitle()));

            return $this->redirectToRoute('app_testcase_show', ['uuid' => $testcase->getUuid()]);
        }

        return $this->render('testcases/new.html.twig', ['form' => $form, 'hasProjects' => [] !== $choices]);
    }

    #[Route('/{uuid}', name: 'app_testcase_show', requirements: ['uuid' => Requirement::UUID], methods: ['GET', 'POST'])]
    #[IsGranted(TestcaseVoter::EDIT, subject: 'testcase')]
    public function show(
        #[MapEntity(expr: self::FIND_TESTCASE)] Testcase $testcase,
        Request $request,
        ProjectRepository $projects,
        TestcaseRepository $testcases,
        EntityManagerInterface $entityManager,
        #[CurrentUser] User $user,
    ): Response {
        $form = $this->createForm(TestcaseContentType::class, TestcaseData::fromVersion($testcase->getEditableVersion()));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $draftButton = $form->get('saveDraft');
            $asDraft = $draftButton instanceof ClickableInterface && $draftButton->isClicked();
            $args = [$data->normalizedTitle(), $data->normalizedDescription(), $data->normalizedPrompt(), $data->normalizedOutputCriteria()];
            $version = $asDraft ? $testcase->saveDraft(...$args) : $testcase->saveFinal(...$args);
            if ($this->flushOrConflict($entityManager)) {
                $this->addFlash('success', \sprintf('Saved as %s %s.', $asDraft ? 'draft' : 'version', $version->getLabel()));
            }

            return $this->redirectToRoute('app_testcase_show', ['uuid' => $testcase->getUuid()]);
        }

        $editable = $projects->findAccessibleBy($user);

        return $this->render('testcases/show.html.twig', [
            'testcase' => $testcase,
            'form' => $form,
            'projectsForm' => $this->createProjectsForm($testcase, $editable),
            'hiddenProjectCount' => $testcase->getProjects()->count() - \count($this->selectedAmong($testcase, $editable)),
            'runCount' => $testcases->runCounts([$testcase], $user)[(int) $testcase->getId()] ?? 0,
            'canDelete' => $this->isGranted(TestcaseVoter::DELETE, $testcase),
        ]);
    }

    #[Route('/{uuid}/projects', name: 'app_testcase_projects', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(TestcaseVoter::EDIT, subject: 'testcase')]
    public function projects(
        #[MapEntity(expr: self::FIND_TESTCASE)] Testcase $testcase,
        Request $request,
        ProjectRepository $projects,
        RunAssignmentPruner $pruner,
        EntityManagerInterface $entityManager,
        #[CurrentUser] User $user,
    ): Response {
        $editable = $projects->findAccessibleBy($user);
        $form = $this->createProjectsForm($testcase, $editable);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'The projects could not be saved.');
        } elseif (!$testcase->replaceProjects($editable, FormData::entities(Project::class, $form->get('projects')->getData()), $user->isAdmin())) {
            $this->addFlash('error', 'A testcase must keep at least one project.');
        } elseif ($this->flushOrConflict($entityManager, static fn () => $pruner->pruneTestcase($testcase))) {
            $this->addFlash('success', 'Projects saved.');

            if (!$this->isGranted(TestcaseVoter::VIEW, $testcase)) {
                $this->addFlash('info', 'You no longer have access to this testcase.');

                return $this->redirectToRoute('app_testcases');
            }
        }

        return $this->redirectToRoute('app_testcase_show', ['uuid' => $testcase->getUuid()]);
    }

    #[Route('/{uuid}/versions/{number}', name: 'app_testcase_version', requirements: ['uuid' => Requirement::UUID, 'number' => Requirements::ID], methods: ['GET'])]
    #[IsGranted(TestcaseVoter::VIEW, subject: 'testcase')]
    public function version(#[MapEntity(expr: self::FIND_TESTCASE)] Testcase $testcase, int $number): Response
    {
        $version = $testcase->findVersion($number) ?? throw $this->createNotFoundException();

        return $this->render('testcases/version.html.twig', ['testcase' => $testcase, 'version' => $version]);
    }

    #[Route('/{uuid}/versions/{number}/restore', name: 'app_testcase_restore', requirements: ['uuid' => Requirement::UUID, 'number' => Requirements::ID], methods: ['POST'])]
    #[IsGranted(TestcaseVoter::EDIT, subject: 'testcase')]
    #[IsCsrfTokenValid(new Expression('"restore-testcase-" ~ args["testcase"].getUuid() ~ "-" ~ args["number"]'), methods: 'POST')]
    public function restore(
        #[MapEntity(expr: self::FIND_TESTCASE)] Testcase $testcase,
        int $number,
        VersionWorkflow $versions,
        EntityManagerInterface $entityManager,
    ): Response {
        [$type, $message] = $versions->restore($testcase, $number);
        if ($this->flushOrConflict($entityManager)) {
            $this->addFlash($type, $message);
        }

        return $this->redirectToRoute('app_testcase_show', ['uuid' => $testcase->getUuid()]);
    }

    #[Route('/{uuid}/draft/discard', name: 'app_testcase_draft_discard', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(TestcaseVoter::EDIT, subject: 'testcase')]
    #[IsCsrfTokenValid(new Expression('"discard-testcase-draft-" ~ args["testcase"].getUuid()'), methods: 'POST')]
    public function discardDraft(
        #[MapEntity(expr: self::FIND_TESTCASE)] Testcase $testcase,
        VersionWorkflow $versions,
        EntityManagerInterface $entityManager,
    ): Response {
        [$type, $message] = $versions->discardDraft($testcase);
        if ($this->flushOrConflict($entityManager)) {
            $this->addFlash($type, $message);
        }

        return $this->redirectToRoute('app_testcase_show', ['uuid' => $testcase->getUuid()]);
    }

    #[Route('/{uuid}/delete', name: 'app_testcase_delete', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(TestcaseVoter::DELETE, subject: 'testcase')]
    #[IsCsrfTokenValid(new Expression('"delete-testcase-" ~ args["testcase"].getUuid()'), methods: 'POST')]
    public function delete(
        #[MapEntity(expr: self::FIND_TESTCASE)] Testcase $testcase,
        RunAssignmentPruner $pruner,
        EntityManagerInterface $entityManager,
    ): Response {
        $title = $testcase->getActiveVersion()->getTitle();
        $testcase->softDelete();
        if ($this->flushOrConflict($entityManager, static fn () => $pruner->pruneTestcase($testcase))) {
            $this->addFlash('success', \sprintf('Testcase "%s" deleted.', $title));
        }

        return $this->redirectToRoute('app_testcases');
    }

    /**
     * @param list<Project> $editable
     *
     * @return FormInterface<array<string, mixed>>
     */
    private function createProjectsForm(Testcase $testcase, array $editable): FormInterface
    {
        return $this->createForm(TestcaseProjectsType::class, ['projects' => new ArrayCollection($this->selectedAmong($testcase, $editable))], [
            'action' => $this->generateUrl('app_testcase_projects', ['uuid' => $testcase->getUuid()]),
            'choices' => $editable,
        ]);
    }

    /**
     * @param list<Project> $editable
     *
     * @return list<Project>
     */
    private function selectedAmong(Testcase $testcase, array $editable): array
    {
        return array_values(array_filter($testcase->getProjects()->toArray(), static fn (Project $project): bool => \in_array($project, $editable, true)));
    }
}
