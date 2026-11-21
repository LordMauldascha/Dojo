<?php

declare(strict_types=1);

namespace App\Controller;

use App\Ai\Effort;
use App\Ai\LlmConfiguration;
use App\Entity\Project;
use App\Entity\Run;
use App\Entity\Skill;
use App\Entity\Testcase;
use App\Entity\User;
use App\Form\FormData;
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
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
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
final class RunController extends AbstractController
{
    use SafeFlushTrait;

    private const LIST_KEY = 'runs';

    #[Route('/projects/{uuid:project}/runs', name: 'app_runs', requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted(ProjectVoter::VIEW, subject: 'project')]
    public function index(
        Project $project,
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

    #[Route('/projects/{uuid:project}/runs/new', name: 'app_run_new', requirements: ['uuid' => Requirement::UUID], methods: ['GET', 'POST'])]
    #[IsGranted(ProjectVoter::VIEW, subject: 'project')]
    public function new(
        Project $project,
        Request $request,
        EntityManagerInterface $entityManager,
        #[CurrentUser] User $user,
    ): Response {
        $run = new Run($project, $user);
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

    #[Route('/runs/{uuid:run}', name: 'app_run_show', requirements: ['uuid' => Requirement::UUID], methods: ['GET', 'POST'])]
    #[IsGranted(RunVoter::EDIT, subject: 'run')]
    public function show(
        Run $run,
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

    #[Route('/runs/{uuid:run}/configurations', name: 'app_run_configuration_add', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(RunVoter::EDIT, subject: 'run')]
    public function addConfiguration(
        Run $run,
        Request $request,
        EntityManagerInterface $entityManager,
    ): Response {
        $form = $this->createConfigurationForm($run);
        $form->handleRequest($request);

        $selected = $form->isSubmitted() && $form->isValid() ? $form->get('model')->getData() : null;
        $effort = $form->get('effort')->getData();
        $configuration = \is_string($selected) ? LlmConfiguration::fromChoice($selected, $effort instanceof Effort ? $effort : null) : null;

        if (null === $configuration) {
            $this->addFlash('error', 'The configuration could not be added.');
        } elseif (!$run->addConfiguration($configuration)) {
            $this->addFlash('error', 'This configuration already exists.');
        } elseif ($this->flushOrConflict($entityManager)) {
            $this->addFlash('success', 'Configuration added.');
        }

        return $this->redirectToRoute('app_run_show', ['uuid' => $run->getUuid()]);
    }

    #[Route('/runs/{uuid:run}/configurations/{key}/remove', name: 'app_run_configuration_remove', requirements: ['uuid' => Requirement::UUID, 'key' => LlmConfiguration::KEY_PATTERN], methods: ['POST'])]
    #[IsGranted(RunVoter::EDIT, subject: 'run')]
    #[IsCsrfTokenValid(new Expression('"remove-run-configuration-" ~ args["run"].getUuid() ~ "-" ~ args["key"]'), methods: 'POST')]
    public function removeConfiguration(
        Run $run,
        string $key,
        EntityManagerInterface $entityManager,
    ): Response {
        if (!$run->removeConfiguration($key)) {
            $this->addFlash('error', 'There is no such configuration.');
        } elseif ($this->flushOrConflict($entityManager)) {
            $this->addFlash('success', 'Configuration removed.');
        }

        return $this->redirectToRoute('app_run_show', ['uuid' => $run->getUuid()]);
    }

    #[Route('/runs/{uuid:run}/skills', name: 'app_run_skills', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(RunVoter::EDIT, subject: 'run')]
    public function skills(
        Run $run,
        Request $request,
        SkillRepository $skills,
        EntityManagerInterface $entityManager,
    ): Response {
        $form = $this->createSkillsForm($run, $skills->findByProject($run->getProject()));
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'The skills could not be saved.');
        } else {
            $run->setSkills(FormData::entities(Skill::class, $form->get('skills')->getData()));
            if ($this->flushOrConflict($entityManager)) {
                $this->addFlash('success', 'Skills saved.');
            }
        }

        return $this->redirectToRoute('app_run_show', ['uuid' => $run->getUuid()]);
    }

    #[Route('/runs/{uuid:run}/testcases', name: 'app_run_testcases', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(RunVoter::EDIT, subject: 'run')]
    public function testcases(
        Run $run,
        Request $request,
        TestcaseRepository $testcases,
        EntityManagerInterface $entityManager,
    ): Response {
        $form = $this->createTestcasesForm($run, $testcases->findByProject($run->getProject()));
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'The testcases could not be saved.');
        } else {
            $run->setTestcases(FormData::entities(Testcase::class, $form->get('testcases')->getData()));
            if ($this->flushOrConflict($entityManager)) {
                $this->addFlash('success', 'Testcases saved.');
            }
        }

        return $this->redirectToRoute('app_run_show', ['uuid' => $run->getUuid()]);
    }

    #[Route('/runs/{uuid:run}/start', name: 'app_run_start', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(RunVoter::START, subject: 'run')]
    #[IsCsrfTokenValid(new Expression('"start-run-" ~ args["run"].getUuid()'), methods: 'POST')]
    public function start(
        Run $run,
        EntityManagerInterface $entityManager,
    ): Response {
        try {
            $version = $run->start();
        } catch (\LogicException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('app_run_show', ['uuid' => $run->getUuid()]);
        }

        if ($this->flushOrConflict($entityManager)) {
            $this->addFlash('success', \sprintf('Started as version %d.', $version->getNumber()));
        }

        return $this->redirectToRoute('app_run_show', ['uuid' => $run->getUuid()]);
    }

    #[Route('/runs/{uuid:run}/delete', name: 'app_run_delete', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(RunVoter::DELETE, subject: 'run')]
    #[IsCsrfTokenValid(new Expression('"delete-run-" ~ args["run"].getUuid()'), methods: 'POST')]
    public function delete(Run $run, EntityManagerInterface $entityManager): Response
    {
        $project = $run->getProject();
        $title = $run->getTitle();
        $entityManager->remove($run);
        $entityManager->flush();
        $this->addFlash('success', \sprintf('Run "%s" deleted.', $title));

        return $this->redirectToRoute('app_runs', ['uuid' => $project->getUuid()]);
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
     * @return FormInterface<array<string, mixed>>
     */
    private function createSkillsForm(Run $run, array $available): FormInterface
    {
        return $this->createForm(RunSkillsType::class, ['skills' => new ArrayCollection(array_values($run->getSkills()->toArray()))], [
            'action' => $this->generateUrl('app_run_skills', ['uuid' => $run->getUuid()]),
            'choices' => $available,
        ]);
    }

    /**
     * @param list<Testcase> $available
     *
     * @return FormInterface<array<string, mixed>>
     */
    private function createTestcasesForm(Run $run, array $available): FormInterface
    {
        return $this->createForm(RunTestcasesType::class, ['testcases' => new ArrayCollection(array_values($run->getTestcases()->toArray()))], [
            'action' => $this->generateUrl('app_run_testcases', ['uuid' => $run->getUuid()]),
            'choices' => $available,
        ]);
    }
}
