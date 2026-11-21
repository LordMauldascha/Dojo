<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Project;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Count;

/** The fields and options the content forms of a versioned entity (skill, testcase) have in common. */
trait AddsContentFields
{
    use AddsVersionButtons;

    /**
     * @template T
     *
     * @param FormBuilderInterface<T> $builder
     */
    private function addTitleAndDescription(FormBuilderInterface $builder, int $titleMax, int $descriptionMax): void
    {
        $builder
            ->add('title', TextType::class, ['label' => 'Title', 'attr' => ['maxlength' => $titleMax]])
            ->add('description', TextareaType::class, ['label' => 'Description', 'required' => false, 'help' => Help::NO_PERSONAL_DATA, 'attr' => ['maxlength' => $descriptionMax, 'rows' => 3]]);
    }

    /**
     * The draft/final buttons, or with the "projects" option the project checklist and a single create button.
     *
     * @template T
     *
     * @param FormBuilderInterface<T> $builder
     * @param array<string, mixed>    $options
     */
    private function addProjectsOrVersionButtons(FormBuilderInterface $builder, array $options, string $createLabel): void
    {
        if (null === $options['projects']) {
            $this->addVersionButtons($builder);

            return;
        }

        $builder
            ->add('projects', EntityType::class, [
                'class' => Project::class,
                'choices' => $options['projects'],
                'choice_label' => 'title',
                'label' => 'Projects',
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'mapped' => false,
                'constraints' => $options['require_project'] ? [new Count(min: 1, minMessage: 'Select at least one project.')] : [],
            ])
            ->add('create', SubmitType::class, ['label' => $createLabel]);
    }

    /** @param class-string $dataClass */
    private function configureContentOptions(OptionsResolver $resolver, string $dataClass): void
    {
        $resolver
            ->setDefaults(['data_class' => $dataClass, 'projects' => null, 'require_project' => true])
            ->setAllowedTypes('projects', ['null', 'array'])
            ->setAllowedTypes('require_project', 'bool');
    }
}
