<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Project;
use App\Form\Model\TestcaseData;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Title, description, prompt and expected output. With the "projects" option (list of selectable projects) the form
 * also asks for the project and offers a single create button, else the draft/final buttons.
 *
 * @extends AbstractType<TestcaseData>
 */
final class TestcaseContentType extends AbstractType
{
    use AddsVersionButtons;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, ['label' => 'Title', 'attr' => ['maxlength' => 255]])
            ->add('description', TextareaType::class, ['label' => 'Description', 'required' => false, 'help' => Help::NO_PERSONAL_DATA, 'attr' => ['maxlength' => 2000, 'rows' => 3]])
            ->add('prompt', TextareaType::class, [
                'label' => 'Prompt',
                'trim' => false,
                'help' => Help::NO_PERSONAL_DATA,
                'attr' => ['maxlength' => 100000, 'rows' => 10],
            ])
            ->add('expectedOutput', TextareaType::class, ['label' => 'Expected output', 'trim' => false, 'help' => Help::NO_PERSONAL_DATA, 'attr' => ['maxlength' => 100000, 'rows' => 10]]);

        if (null === $options['projects']) {
            $this->addVersionButtons($builder);

            return;
        }

        $builder
            ->add('project', EntityType::class, [
                'class' => Project::class,
                'choices' => $options['projects'],
                'choice_label' => 'title',
                'label' => 'Project',
                'placeholder' => $options['require_project'] ? 'Select a project' : 'No project (admins only)',
                'required' => $options['require_project'],
                'mapped' => false,
                'constraints' => $options['require_project'] ? [new NotBlank(message: 'Select a project.')] : [],
            ])
            ->add('create', SubmitType::class, ['label' => 'Create testcase']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['data_class' => TestcaseData::class, 'projects' => null, 'require_project' => true])
            ->setAllowedTypes('projects', ['null', 'array'])
            ->setAllowedTypes('require_project', 'bool');
    }
}
