<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Project;
use App\Entity\Skill;
use App\Entity\SkillVersion;
use App\Entity\Testcase;
use App\Entity\TestcaseVersion;
use App\Entity\User;
use App\Form\FormData;
use App\Form\Model\SkillData;
use App\Form\Model\TestcaseData;
use App\Pagination\ListQuery;
use App\Pagination\PageSizeResolver;
use App\Repository\ProjectRepository;
use App\Repository\VersionedEntityRepository;
use App\Run\RunAssignmentPruner;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\ClickableInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The shared actions of the project-assigned, versioned entities (skills, testcases). The concrete controller keeps the
 * routes, security attributes and CSRF constants and delegates; route names, template paths and the list key derive from
 * label() ("skill": app_skill_show, skills/show.html.twig, app_skills).
 *
 * @template T of Skill|Testcase
 * @template D of SkillData|TestcaseData
 */
abstract class VersionedEntityController extends AbstractController
{
    use SafeFlushTrait {
        flushOrConflict as protected;
    }

    protected const FIND_ACTIVE = 'repository.findActiveByUuid(uuid)';

    abstract protected function label(): string;

    /** @return class-string<FormTypeInterface<D>> */
    abstract protected function contentFormType(): string;

    /** @return class-string<FormTypeInterface<array<string, mixed>>> */
    abstract protected function projectsFormType(): string;

    abstract protected function viewAttribute(): string;

    abstract protected function deleteAttribute(): string;

    /**
     * @param T|null $entity the entity to edit, null for an empty form
     *
     * @return D
     */
    abstract protected function formData(?object $entity): object;

    /**
     * @param D $data
     *
     * @return T
     */
    abstract protected function createEntity(object $data, User $user): object;

    /**
     * @param T $entity
     * @param D $data
     */
    abstract protected function saveVersion(object $entity, object $data, bool $asDraft): SkillVersion|TestcaseVersion;

    /** @param T $entity */
    abstract protected function pruneRuns(RunAssignmentPruner $pruner, object $entity): void;

    /** @param VersionedEntityRepository<T> $repository */
    protected function renderIndex(
        VersionedEntityRepository $repository,
        ProjectRepository $projects,
        PageSizeResolver $pageSizes,
        User $viewer,
        ListQuery $query,
        bool $withRunCounts,
    ): Response {
        $accessible = $projects->findAccessibleBy($viewer);
        $page = $repository->paginate($query, $pageSizes->resolve($this->label().'s', $query->perPage), $viewer);

        return $this->render($this->template('index'), [
            'page' => $page,
            'draftNumbers' => $repository->draftNumbers($page->items),
            'query' => $query,
            'projectChoices' => $accessible,
            'visibleProjectIds' => array_map(static fn (Project $project): ?int => $project->getId(), $accessible),
        ] + ($withRunCounts ? ['runCounts' => $repository->runCounts($page->items, $viewer)] : []));
    }

    protected function handleNew(Request $request, ProjectRepository $projects, EntityManagerInterface $entityManager, User $user): Response
    {
        $choices = $projects->findAccessibleBy($user);
        $form = $this->createForm($this->contentFormType(), $this->formData(null), [
            'projects' => $choices,
            'require_project' => !$user->isAdmin(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $this->createEntity($form->getData(), $user);
            foreach (FormData::entities(Project::class, $form->get('projects')->getData()) as $project) {
                $entity->addProject($project);
            }
            $entityManager->persist($entity);
            if (!$this->flushOrConflict($entityManager)) {
                return $this->redirectToRoute($this->listRoute());
            }
            $this->addFlash('success', \sprintf('%s "%s" created.', ucfirst($this->label()), $entity->getActiveVersion()->getTitle()));

            return $this->redirectToShow($entity);
        }

        return $this->render($this->template('new'), ['form' => $form, 'hasProjects' => [] !== $choices]);
    }

    /**
     * @param T                            $entity
     * @param VersionedEntityRepository<T> $repository
     */
    protected function handleShow(
        Skill|Testcase $entity,
        Request $request,
        VersionedEntityRepository $repository,
        ProjectRepository $projects,
        EntityManagerInterface $entityManager,
        User $user,
    ): Response {
        $form = $this->createForm($this->contentFormType(), $this->formData($entity));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $asDraft = $this->isClicked($form, 'saveDraft');
            if (!$asDraft && !$this->isClicked($form, 'saveFinal')) {
                return $this->redirectToShow($entity);
            }

            $version = $this->saveVersion($entity, $form->getData(), $asDraft);
            if ($this->flushOrConflict($entityManager)) {
                $this->addFlash('success', \sprintf('Saved as %s %s.', $asDraft ? 'draft' : 'version', $version->getLabel()));
            }

            return $this->redirectToShow($entity);
        }

        $editable = $projects->findAccessibleBy($user);

        return $this->render($this->template('show'), [
            $this->label() => $entity,
            'form' => $form,
            'projectsForm' => $this->createProjectsForm($entity, $editable),
            'runCount' => $repository->runCounts([$entity], $user)[(int) $entity->getId()] ?? 0,
            'hiddenProjectCount' => $entity->getProjects()->count() - \count($this->selectedAmong($entity, $editable)),
            'canDelete' => $this->isGranted($this->deleteAttribute(), $entity),
        ]);
    }

    /** @param T $entity */
    protected function handleProjects(
        Skill|Testcase $entity,
        Request $request,
        ProjectRepository $projects,
        RunAssignmentPruner $pruner,
        EntityManagerInterface $entityManager,
        User $user,
    ): Response {
        $editable = $projects->findAccessibleBy($user);
        $form = $this->createProjectsForm($entity, $editable);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'The projects could not be saved.');
        } elseif (!$entity->replaceProjects($editable, FormData::entities(Project::class, $form->get('projects')->getData()), $user->isAdmin())) {
            $this->addFlash('error', \sprintf('A %s must keep at least one project.', $this->label()));
        } elseif ($this->flushOrConflict($entityManager, fn () => $this->pruneRuns($pruner, $entity))) {
            $this->addFlash('success', 'Projects saved.');

            if (!$this->isGranted($this->viewAttribute(), $entity)) {
                $this->addFlash('info', \sprintf('You no longer have access to this %s.', $this->label()));

                return $this->redirectToRoute($this->listRoute());
            }
        }

        return $this->redirectToShow($entity);
    }

    /** @param T $entity */
    protected function handleDelete(Skill|Testcase $entity, RunAssignmentPruner $pruner, EntityManagerInterface $entityManager): Response
    {
        $title = $entity->getActiveVersion()->getTitle();
        $entity->softDelete();
        if ($this->flushOrConflict($entityManager, fn () => $this->pruneRuns($pruner, $entity))) {
            $this->addFlash('success', \sprintf('%s "%s" deleted.', ucfirst($this->label()), $title));
        }

        return $this->redirectToRoute($this->listRoute());
    }

    /** @param T $entity */
    protected function redirectToShow(Skill|Testcase $entity): Response
    {
        return $this->redirectToRoute(\sprintf('app_%s_show', $this->label()), ['uuid' => $entity->getUuid()]);
    }

    protected function template(string $page): string
    {
        return \sprintf('%ss/%s.html.twig', $this->label(), $page);
    }

    private function listRoute(): string
    {
        return 'app_'.$this->label().'s';
    }

    /**
     * @template F
     *
     * @param FormInterface<F> $form
     */
    private function isClicked(FormInterface $form, string $button): bool
    {
        $submit = $form->get($button);

        return $submit instanceof ClickableInterface && $submit->isClicked();
    }

    /**
     * @param T             $entity
     * @param list<Project> $editable
     *
     * @return FormInterface<array<string, mixed>>
     */
    private function createProjectsForm(Skill|Testcase $entity, array $editable): FormInterface
    {
        return $this->createForm($this->projectsFormType(), ['projects' => new ArrayCollection($this->selectedAmong($entity, $editable))], [
            'action' => $this->generateUrl(\sprintf('app_%s_projects', $this->label()), ['uuid' => $entity->getUuid()]),
            'choices' => $editable,
        ]);
    }

    /**
     * @param T             $entity
     * @param list<Project> $editable
     *
     * @return list<Project>
     */
    private function selectedAmong(Skill|Testcase $entity, array $editable): array
    {
        return array_values(array_filter($entity->getProjects()->toArray(), static fn (Project $project): bool => \in_array($project, $editable, true)));
    }
}
