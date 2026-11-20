<?php

declare(strict_types=1);

namespace App\Controller;

use App\Ai\Effort;
use App\Ai\LlmConfiguration;
use App\Ai\Provider;
use App\Entity\Project;
use App\Entity\Run;
use App\Entity\Skill;
use App\Entity\Testcase;
use App\Entity\User;
use App\Form\RunConfigurationType;
use App\Form\RunSkillsType;
use App\Form\RunTestcasesType;
use App\Form\RunType;
use App\Pagination\ListQuery;
use App\Pagination\PageSizeResolver;
use App\Repository\RunRepository;
use App\Repository\SkillRepository;
use App\Repository\TestcaseRepository;
use App\Security\Voter\ProjectVoter;
use App\Security\Voter\RunVoter;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class RunController extends AbstractController
{
    use CsrfGuardTrait;

    private const LIST_KEY = 'runs';

    #[Route('/projects/{uuid}/runs', name: 'app_runs', requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted(ProjectVoter::VIEW, subject: 'project')]
    public function index(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Project $project,
        RunRepository $runs,
        PageSizeResolver $pageSizes,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        ListQuery $query = new ListQuery(),
    ): Response {
        return $this->render('runs/index.html.twig', [
            'project' => $project,
            'page' => $runs->paginate($project, $query, $pageSizes->resolve(self::LIST_KEY, $query->perPage)),
            'query' => $query,
        ]);
    }

    #[Route('/projects/{uuid}/runs/new', name: 'app_run_new', requirements: ['uuid' => Requirement::UUID], methods: ['GET', 'POST'])]
    #[IsGranted(ProjectVoter::VIEW, subject: 'project')]
    public function new(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Project $project,
        Request $request,
        EntityManagerInterface $entityManager,
    ): Response {
        $run = new Run($project, $this->currentUser());
        $form = $this->createForm(RunType::class, $run);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($run);
            $entityManager->flush();
            $this->addFlash('success', \sprintf('Run "%s" created.', $run->getTitle()));

            return $this->redirectToRoute('app_run_show', ['uuid' => $run->getUuid()]);
        }

        return $this->render('runs/new.html.twig', ['project' => $project, 'form' => $form]);
    }

    #[Route('/runs/{uuid}', name: 'app_run_show', requirements: ['uuid' => Requirement::UUID], methods: ['GET', 'POST'])]
    #[IsGranted(RunVoter::EDIT, subject: 'run')]
    public function show(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Run $run,
        Request $request,
        SkillRepository $skills,
        TestcaseRepository $testcases,
        EntityManagerInterface $entityManager,
    ): Response {
        $form = $this->createForm(RunType::class, $run);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Run saved.');

            return $this->redirectToRoute('app_run_show', ['uuid' => $run->getUuid()]);
        }

        $availableSkills = $skills->findByProject($run->getProject());
        $availableTestcases = $testcases->findByProject($run->getProject());

        return $this->render('runs/show.html.twig', [
            'run' => $run,
            'form' => $form,
            'configurationForm' => $this->createConfigurationForm($run),
            'skillsForm' => $this->createSkillsForm($run, $availableSkills),
            'testcasesForm' => $this->createTestcasesForm($run, $availableTestcases),
            'hasSkills' => [] !== $availableSkills,
            'hasTestcases' => [] !== $availableTestcases,
            'canDelete' => $this->isGranted(RunVoter::DELETE, $run),
        ]);
    }

    #[Route('/runs/{uuid}/configurations', name: 'app_run_configuration_add', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(RunVoter::EDIT, subject: 'run')]
    public function addConfiguration(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Run $run,
        Request $request,
        EntityManagerInterface $entityManager,
    ): Response {
        $form = $this->createConfigurationForm($run);
        $form->handleRequest($request);

        $selected = $form->isSubmitted() && $form->isValid() ? $form->get('model')->getData() : null;
        [$providerValue, $model] = \is_string($selected) ? explode(':', $selected, 2) + [1 => ''] : ['', ''];
        $provider = Provider::tryFrom($providerValue);
        $effort = $form->get('effort')->getData();

        if (null === $provider || !\in_array($model, $provider->models(), true)) {
            $this->addFlash('error', 'The configuration could not be added.');
        } elseif ($run->addConfiguration(new LlmConfiguration($provider, $model, $effort instanceof Effort ? $effort : null))) {
            $entityManager->flush();
            $this->addFlash('success', 'Configuration added.');
        } else {
            $this->addFlash('error', 'This configuration already exists.');
        }

        return $this->redirectToRoute('app_run_show', ['uuid' => $run->getUuid()]);
    }

    #[Route('/runs/{uuid}/configurations/{index}/remove', name: 'app_run_configuration_remove', requirements: ['uuid' => Requirement::UUID, 'index' => Requirement::DIGITS], methods: ['POST'])]
    #[IsGranted(RunVoter::EDIT, subject: 'run')]
    public function removeConfiguration(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Run $run,
        int $index,
        Request $request,
        EntityManagerInterface $entityManager,
    ): Response {
        $this->denyUnlessValidToken('remove-run-configuration-'.$run->getUuid().'-'.$index, $request);

        if ($run->removeConfigurationAt($index)) {
            $entityManager->flush();
            $this->addFlash('success', 'Configuration removed.');
        } else {
            $this->addFlash('error', 'There is no such configuration.');
        }

        return $this->redirectToRoute('app_run_show', ['uuid' => $run->getUuid()]);
    }

    #[Route('/runs/{uuid}/skills', name: 'app_run_skills', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(RunVoter::EDIT, subject: 'run')]
    public function skills(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Run $run,
        Request $request,
        SkillRepository $skills,
        EntityManagerInterface $entityManager,
    ): Response {
        $form = $this->createSkillsForm($run, $skills->findByProject($run->getProject()));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $run->setSkills(array_values(array_filter($this->toList($form->get('skills')->getData()), static fn (mixed $skill): bool => $skill instanceof Skill)));
            $entityManager->flush();
            $this->addFlash('success', 'Skills saved.');
        } else {
            $this->addFlash('error', 'The skills could not be saved.');
        }

        return $this->redirectToRoute('app_run_show', ['uuid' => $run->getUuid()]);
    }

    #[Route('/runs/{uuid}/testcases', name: 'app_run_testcases', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(RunVoter::EDIT, subject: 'run')]
    public function testcases(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Run $run,
        Request $request,
        TestcaseRepository $testcases,
        EntityManagerInterface $entityManager,
    ): Response {
        $form = $this->createTestcasesForm($run, $testcases->findByProject($run->getProject()));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $run->setTestcases(array_values(array_filter($this->toList($form->get('testcases')->getData()), static fn (mixed $testcase): bool => $testcase instanceof Testcase)));
            $entityManager->flush();
            $this->addFlash('success', 'Testcases saved.');
        } else {
            $this->addFlash('error', 'The testcases could not be saved.');
        }

        return $this->redirectToRoute('app_run_show', ['uuid' => $run->getUuid()]);
    }

    #[Route('/runs/{uuid}/start', name: 'app_run_start', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(RunVoter::START, subject: 'run')]
    public function start(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Run $run,
        Request $request,
        EntityManagerInterface $entityManager,
    ): Response {
        $this->denyUnlessValidToken('start-run-'.$run->getUuid(), $request);

        try {
            $version = $run->start();
            $entityManager->flush();
            $this->addFlash('success', \sprintf('Started as version %d.', $version->getNumber()));
        } catch (\LogicException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_run_show', ['uuid' => $run->getUuid()]);
    }

    #[Route('/runs/{uuid}/delete', name: 'app_run_delete', requirements: ['uuid' => Requirement::UUID], methods: ['GET', 'POST'])]
    #[IsGranted(RunVoter::DELETE, subject: 'run')]
    public function delete(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Run $run,
        Request $request,
        EntityManagerInterface $entityManager,
    ): Response {
        if ($request->isMethod('POST')) {
            $this->denyUnlessValidToken('delete-run-'.$run->getUuid(), $request);

            $project = $run->getProject();
            $title = $run->getTitle();
            $entityManager->remove($run);
            $entityManager->flush();
            $this->addFlash('success', \sprintf('Run "%s" deleted.', $title));

            return $this->redirectToRoute('app_runs', ['uuid' => $project->getUuid()]);
        }

        return $this->render('runs/delete.html.twig', ['run' => $run]);
    }

    /** @return FormInterface<array{model: string|null, effort: Effort|null}|null> */
    private function createConfigurationForm(Run $run): FormInterface
    {
        return $this->createForm(RunConfigurationType::class, null, [
            'action' => $this->generateUrl('app_run_configuration_add', ['uuid' => $run->getUuid()]),
        ]);
    }

    /**
     * @param list<Skill> $available
     *
     * @return FormInterface<array{skills: ArrayCollection<int, Skill>}|array{skills: list<Skill>}>
     */
    private function createSkillsForm(Run $run, array $available): FormInterface
    {
        return $this->createForm(RunSkillsType::class, ['skills' => new ArrayCollection(array_values($run->getSkills()->toArray()))], [
            'action' => $this->generateUrl('app_run_skills', ['uuid' => $run->getUuid()]),
            'available_skills' => $available,
        ]);
    }

    /**
     * @param list<Testcase> $available
     *
     * @return FormInterface<array{testcases: ArrayCollection<int, Testcase>}|array{testcases: list<Testcase>}>
     */
    private function createTestcasesForm(Run $run, array $available): FormInterface
    {
        return $this->createForm(RunTestcasesType::class, ['testcases' => new ArrayCollection(array_values($run->getTestcases()->toArray()))], [
            'action' => $this->generateUrl('app_run_testcases', ['uuid' => $run->getUuid()]),
            'available_testcases' => $available,
        ]);
    }

    /** @return list<mixed> */
    private function toList(mixed $data): array
    {
        return \is_iterable($data) ? iterator_to_array($data, false) : [];
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
