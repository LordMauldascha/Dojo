<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Project;
use App\Entity\Testcase;
use App\Entity\User;
use App\Form\Model\TestcaseData;
use App\Form\TestcaseContentType;
use App\Form\TestcaseProjectType;
use App\Pagination\ListQuery;
use App\Pagination\PageSizeResolver;
use App\Repository\ProjectRepository;
use App\Repository\TestcaseRepository;
use App\Security\UserRole;
use App\Security\Voter\TestcaseVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\ClickableInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/testcases')]
final class TestcaseController extends AbstractController
{
    use CsrfGuardTrait;

    private const LIST_KEY = 'testcases';
    private const FIND_TESTCASE = 'repository.findActiveByUuid(uuid)';

    #[Route('', name: 'app_testcases', methods: ['GET'])]
    public function index(
        TestcaseRepository $testcases,
        ProjectRepository $projects,
        PageSizeResolver $pageSizes,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        ListQuery $query = new ListQuery(),
    ): Response {
        $viewer = $this->currentUser();

        return $this->render('testcases/index.html.twig', [
            'page' => $testcases->paginate($query, $pageSizes->resolve(self::LIST_KEY, $query->perPage), $viewer),
            'query' => $query,
            'projectChoices' => $projects->findAccessibleBy($viewer),
        ]);
    }

    #[Route('/new', name: 'app_testcase_new', methods: ['GET', 'POST'])]
    public function new(Request $request, ProjectRepository $projects, EntityManagerInterface $entityManager): Response
    {
        $user = $this->currentUser();
        $choices = $projects->findAccessibleBy($user);
        $form = $this->createForm(TestcaseContentType::class, new TestcaseData(), [
            'projects' => $choices,
            'require_project' => !$this->isAdmin($user),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $project = $form->get('project')->getData();
            $testcase = new Testcase(
                $data->normalizedTitle(),
                $data->normalizedDescription(),
                $data->normalizedPrompt(),
                $data->normalizedExpectedOutput(),
                $project instanceof Project ? $project : null,
                $user,
            );
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
        EntityManagerInterface $entityManager,
    ): Response {
        $form = $this->createForm(TestcaseContentType::class, TestcaseData::fromVersion($testcase->getEditableVersion()));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $draftButton = $form->get('saveDraft');
            $asDraft = $draftButton instanceof ClickableInterface && $draftButton->isClicked();
            $args = [$data->normalizedTitle(), $data->normalizedDescription(), $data->normalizedPrompt(), $data->normalizedExpectedOutput()];
            $version = $asDraft ? $testcase->saveDraft(...$args) : $testcase->saveFinal(...$args);
            $entityManager->flush();
            $this->addFlash('success', \sprintf('Saved as %s %s.', $asDraft ? 'draft' : 'version', $version->getLabel()));

            return $this->redirectToRoute('app_testcase_show', ['uuid' => $testcase->getUuid()]);
        }

        return $this->render('testcases/show.html.twig', [
            'testcase' => $testcase,
            'form' => $form,
            'projectForm' => $this->createProjectForm($testcase, $projects->findAccessibleBy($this->currentUser())),
            'canDelete' => $this->isGranted(TestcaseVoter::DELETE, $testcase),
        ]);
    }

    #[Route('/{uuid}/project', name: 'app_testcase_project', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(TestcaseVoter::EDIT, subject: 'testcase')]
    public function project(
        #[MapEntity(expr: self::FIND_TESTCASE)] Testcase $testcase,
        Request $request,
        ProjectRepository $projects,
        EntityManagerInterface $entityManager,
    ): Response {
        $form = $this->createProjectForm($testcase, $projects->findAccessibleBy($this->currentUser()));
        $form->handleRequest($request);

        $project = $form->get('project')->getData();
        if (!$form->isSubmitted() || !$form->isValid() || (!$project instanceof Project && !$this->isAdmin($this->currentUser()))) {
            $this->addFlash('error', 'The project could not be saved.');

            return $this->redirectToRoute('app_testcase_show', ['uuid' => $testcase->getUuid()]);
        }

        $testcase->moveToProject($project instanceof Project ? $project : null);
        $entityManager->flush();
        $this->addFlash('success', 'Project saved.');

        return $this->redirectToRoute('app_testcase_show', ['uuid' => $testcase->getUuid()]);
    }

    #[Route('/{uuid}/versions/{number}', name: 'app_testcase_version', requirements: ['uuid' => Requirement::UUID, 'number' => Requirement::DIGITS], methods: ['GET'])]
    #[IsGranted(TestcaseVoter::VIEW, subject: 'testcase')]
    public function version(#[MapEntity(expr: self::FIND_TESTCASE)] Testcase $testcase, int $number): Response
    {
        $version = $testcase->findVersion($number) ?? throw $this->createNotFoundException();

        return $this->render('testcases/version.html.twig', ['testcase' => $testcase, 'version' => $version]);
    }

    #[Route('/{uuid}/versions/{number}/restore', name: 'app_testcase_restore', requirements: ['uuid' => Requirement::UUID, 'number' => Requirement::DIGITS], methods: ['POST'])]
    #[IsGranted(TestcaseVoter::EDIT, subject: 'testcase')]
    public function restore(
        #[MapEntity(expr: self::FIND_TESTCASE)] Testcase $testcase,
        int $number,
        Request $request,
        EntityManagerInterface $entityManager,
    ): Response {
        $version = $testcase->findVersion($number) ?? throw $this->createNotFoundException();
        $this->denyUnlessValidToken('restore-testcase-'.$testcase->getUuid().'-'.$number, $request);

        if (null !== $testcase->getDraft()) {
            $this->addFlash('error', 'A draft exists: save it as final or discard it before restoring a version.');
        } elseif ($version->isDraft()) {
            $this->addFlash('error', 'A draft cannot be restored.');
        } else {
            $restored = $testcase->restore($version);
            $entityManager->flush();
            $this->addFlash('success', \sprintf('Version %d restored as version %d.', $number, $restored->getNumber()));
        }

        return $this->redirectToRoute('app_testcase_show', ['uuid' => $testcase->getUuid()]);
    }

    #[Route('/{uuid}/draft/discard', name: 'app_testcase_draft_discard', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(TestcaseVoter::EDIT, subject: 'testcase')]
    public function discardDraft(
        #[MapEntity(expr: self::FIND_TESTCASE)] Testcase $testcase,
        Request $request,
        EntityManagerInterface $entityManager,
    ): Response {
        $this->denyUnlessValidToken('discard-testcase-draft-'.$testcase->getUuid(), $request);

        if (null === $testcase->getDraft()) {
            $this->addFlash('error', 'There is no draft to discard.');
        } else {
            $testcase->discardDraft();
            $entityManager->flush();
            $this->addFlash('success', 'Draft discarded.');
        }

        return $this->redirectToRoute('app_testcase_show', ['uuid' => $testcase->getUuid()]);
    }

    #[Route('/{uuid}/delete', name: 'app_testcase_delete', requirements: ['uuid' => Requirement::UUID], methods: ['GET', 'POST'])]
    #[IsGranted(TestcaseVoter::DELETE, subject: 'testcase')]
    public function delete(
        #[MapEntity(expr: self::FIND_TESTCASE)] Testcase $testcase,
        Request $request,
        EntityManagerInterface $entityManager,
    ): Response {
        if ($request->isMethod('POST')) {
            $this->denyUnlessValidToken('delete-testcase-'.$testcase->getUuid(), $request);

            $title = $testcase->getActiveVersion()->getTitle();
            $testcase->softDelete();
            $entityManager->flush();
            $this->addFlash('success', \sprintf('Testcase "%s" deleted.', $title));

            return $this->redirectToRoute('app_testcases');
        }

        return $this->render('testcases/delete.html.twig', ['testcase' => $testcase]);
    }

    /**
     * @param list<Project> $editable
     *
     * @return FormInterface<array{project: Project|null}>
     */
    private function createProjectForm(Testcase $testcase, array $editable): FormInterface
    {
        return $this->createForm(TestcaseProjectType::class, ['project' => $testcase->getProject()], [
            'action' => $this->generateUrl('app_testcase_project', ['uuid' => $testcase->getUuid()]),
            'available_projects' => $editable,
            'allow_none' => $this->isAdmin($this->currentUser()),
        ]);
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }

    private function isAdmin(User $user): bool
    {
        return UserRole::Admin === $user->getRole();
    }
}
