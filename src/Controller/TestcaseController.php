<?php

declare(strict_types=1);

namespace App\Controller;

use App\Ai\ContentGenerator;
use App\Entity\Testcase;
use App\Entity\TestcaseVersion;
use App\Entity\User;
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
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** @extends VersionedEntityController<Testcase, TestcaseData> */
#[IsGranted('ROLE_USER')]
#[Route('/testcases')]
final class TestcaseController extends VersionedEntityController
{
    public const CSRF_RESTORE = 'restore-testcase-';
    public const CSRF_DISCARD_DRAFT = 'discard-testcase-draft-';
    public const CSRF_DELETE = 'delete-testcase-';

    #[Route('', name: 'app_testcases', methods: ['GET'])]
    public function index(
        TestcaseRepository $testcases,
        ProjectRepository $projects,
        PageSizeResolver $pageSizes,
        #[CurrentUser] User $viewer,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        ListQuery $query = new ListQuery(),
    ): Response {
        return $this->renderIndex($testcases, $projects, $pageSizes, $viewer, $query, false);
    }

    #[Route('/new', name: 'app_testcase_new', methods: ['GET', 'POST'])]
    public function new(Request $request, ProjectRepository $projects, EntityManagerInterface $entityManager, #[CurrentUser] User $user): Response
    {
        return $this->handleNew($request, $projects, $entityManager, $user);
    }

    #[Route('/generate', name: 'app_testcase_generate', methods: ['GET', 'POST'])]
    public function generate(Request $request, ProjectRepository $projects, ContentGenerator $generator, #[CurrentUser] User $user): Response
    {
        return $this->handleGenerate($request, $projects, $generator, $user);
    }

    #[Route('/{uuid}', name: 'app_testcase_show', requirements: ['uuid' => Requirement::UUID], methods: ['GET', 'POST'])]
    #[IsGranted(TestcaseVoter::EDIT, subject: 'testcase')]
    public function show(
        #[MapEntity(expr: self::FIND_ACTIVE)] Testcase $testcase,
        Request $request,
        ProjectRepository $projects,
        TestcaseRepository $testcases,
        EntityManagerInterface $entityManager,
        #[CurrentUser] User $user,
    ): Response {
        return $this->handleShow($testcase, $request, $testcases, $projects, $entityManager, $user);
    }

    #[Route('/{uuid}/projects', name: 'app_testcase_projects', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(TestcaseVoter::EDIT, subject: 'testcase')]
    public function projects(
        #[MapEntity(expr: self::FIND_ACTIVE)] Testcase $testcase,
        Request $request,
        ProjectRepository $projects,
        RunAssignmentPruner $pruner,
        EntityManagerInterface $entityManager,
        #[CurrentUser] User $user,
    ): Response {
        return $this->handleProjects($testcase, $request, $projects, $pruner, $entityManager, $user);
    }

    #[Route('/{uuid}/versions/{number}', name: 'app_testcase_version', requirements: ['uuid' => Requirement::UUID, 'number' => Requirements::ID], methods: ['GET'])]
    #[IsGranted(TestcaseVoter::VIEW, subject: 'testcase')]
    public function version(#[MapEntity(expr: self::FIND_ACTIVE)] Testcase $testcase, int $number): Response
    {
        $version = $testcase->findVersion($number) ?? throw $this->createNotFoundException();

        return $this->render('testcases/version.html.twig', ['testcase' => $testcase, 'version' => $version]);
    }

    #[Route('/{uuid}/versions/{number}/restore', name: 'app_testcase_restore', requirements: ['uuid' => Requirement::UUID, 'number' => Requirements::ID], methods: ['POST'])]
    #[IsGranted(TestcaseVoter::EDIT, subject: 'testcase')]
    #[IsCsrfTokenValid(new Expression('"'.self::CSRF_RESTORE.'" ~ args["testcase"].getUuid() ~ "-" ~ args["number"]'), methods: 'POST')]
    public function restore(
        #[MapEntity(expr: self::FIND_ACTIVE)] Testcase $testcase,
        int $number,
        VersionWorkflow $versions,
        EntityManagerInterface $entityManager,
    ): Response {
        [$type, $message] = $versions->restore($testcase, $number);
        if ($this->flushOrConflict($entityManager)) {
            $this->addFlash($type, $message);
        }

        return $this->redirectToShow($testcase);
    }

    #[Route('/{uuid}/draft/discard', name: 'app_testcase_draft_discard', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(TestcaseVoter::EDIT, subject: 'testcase')]
    #[IsCsrfTokenValid(new Expression('"'.self::CSRF_DISCARD_DRAFT.'" ~ args["testcase"].getUuid()'), methods: 'POST')]
    public function discardDraft(
        #[MapEntity(expr: self::FIND_ACTIVE)] Testcase $testcase,
        VersionWorkflow $versions,
        EntityManagerInterface $entityManager,
    ): Response {
        [$type, $message] = $versions->discardDraft($testcase);
        if ($this->flushOrConflict($entityManager)) {
            $this->addFlash($type, $message);
        }

        return $this->redirectToShow($testcase);
    }

    #[Route('/{uuid}/delete', name: 'app_testcase_delete', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(TestcaseVoter::DELETE, subject: 'testcase')]
    #[IsCsrfTokenValid(new Expression('"'.self::CSRF_DELETE.'" ~ args["testcase"].getUuid()'), methods: 'POST')]
    public function delete(
        #[MapEntity(expr: self::FIND_ACTIVE)] Testcase $testcase,
        RunAssignmentPruner $pruner,
        EntityManagerInterface $entityManager,
    ): Response {
        return $this->handleDelete($testcase, $pruner, $entityManager);
    }

    protected function label(): string
    {
        return 'testcase';
    }

    protected function contentFormType(): string
    {
        return TestcaseContentType::class;
    }

    protected function projectsFormType(): string
    {
        return TestcaseProjectsType::class;
    }

    protected function viewAttribute(): string
    {
        return TestcaseVoter::VIEW;
    }

    protected function deleteAttribute(): string
    {
        return TestcaseVoter::DELETE;
    }

    /**
     * @param Testcase|null $entity
     */
    protected function formData(?object $entity): TestcaseData
    {
        return null === $entity ? new TestcaseData() : TestcaseData::fromVersion($entity->getEditableVersion());
    }

    /**
     * @param TestcaseData $data
     */
    protected function createEntity(object $data, User $user): Testcase
    {
        return new Testcase($data->normalizedTitle(), $data->normalizedDescription(), $data->normalizedPrompt(), $data->normalizedOutputCriteria(), $user);
    }

    /**
     * @param Testcase     $entity
     * @param TestcaseData $data
     */
    protected function saveVersion(object $entity, object $data, bool $asDraft): TestcaseVersion
    {
        $args = [$data->normalizedTitle(), $data->normalizedDescription(), $data->normalizedPrompt(), $data->normalizedOutputCriteria()];

        return $asDraft ? $entity->saveDraft(...$args) : $entity->saveFinal(...$args);
    }

    protected function generateContent(ContentGenerator $generator, string $description): TestcaseData
    {
        return $generator->testcase($description);
    }

    /**
     * @param Testcase $entity
     */
    protected function pruneRuns(RunAssignmentPruner $pruner, object $entity): void
    {
        $pruner->pruneTestcase($entity);
    }
}
