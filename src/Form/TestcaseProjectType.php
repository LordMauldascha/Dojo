<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Project;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/** @extends AbstractType<array{project: Project|null}> */
final class TestcaseProjectType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('project', EntityType::class, [
                'class' => Project::class,
                'choices' => $options['available_projects'],
                'choice_label' => 'title',
                'label' => 'Project',
                'placeholder' => $options['allow_none'] ? 'No project (admins only)' : 'Select a project',
                'required' => !$options['allow_none'],
                'constraints' => $options['allow_none'] ? [] : [new NotBlank(message: 'Select a project.')],
            ])
            ->add('save', SubmitType::class, ['label' => 'Save project']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setRequired('available_projects')
            ->setAllowedTypes('available_projects', 'array')
            ->setDefaults(['allow_none' => false])
            ->setAllowedTypes('allow_none', 'bool');
    }
}
