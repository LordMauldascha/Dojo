<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Project;
use App\Form\Model\SkillData;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Count;

/**
 * Title, description and SKILL.md content. With the "projects" option (list of selectable projects) the form
 * also asks for the project assignment and offers a single create button, else the draft/final buttons.
 *
 * @extends AbstractType<SkillData>
 */
final class SkillContentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, ['label' => 'Title', 'attr' => ['maxlength' => 255]])
            ->add('description', TextareaType::class, ['label' => 'Description', 'required' => false, 'attr' => ['maxlength' => 2000, 'rows' => 3]])
            ->add('content', TextareaType::class, ['label' => 'SKILL.md', 'attr' => ['maxlength' => 100000, 'rows' => 20]]);

        if (null === $options['projects']) {
            $builder
                ->add('saveDraft', SubmitType::class, ['label' => 'Save as draft'])
                ->add('saveFinal', SubmitType::class, ['label' => 'Save as final']);

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
            ->add('create', SubmitType::class, ['label' => 'Create skill']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['data_class' => SkillData::class, 'projects' => null, 'require_project' => true])
            ->setAllowedTypes('projects', ['null', 'array'])
            ->setAllowedTypes('require_project', 'bool');
    }
}
