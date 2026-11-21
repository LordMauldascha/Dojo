<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Project;
use App\Form\Model\SkillData;
use App\Skill\SkillHeader;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Count;

/**
 * Title, description, the generated skill header and the instructions (the SKILL.md body). With the "projects" option (list of selectable projects) the form
 * also asks for the project assignment and offers a single create button, else the draft/final buttons.
 *
 * @extends AbstractType<SkillData>
 */
final class SkillContentType extends AbstractType
{
    use AddsVersionButtons;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, ['label' => 'Title', 'attr' => ['maxlength' => 255]])
            ->add('description', TextareaType::class, ['label' => 'Description', 'required' => false, 'help' => Help::NO_PERSONAL_DATA, 'attr' => ['maxlength' => 2000, 'rows' => 3]])
            ->add('skillHeader', TextareaType::class, [
                'label' => 'Skills Header',
                'required' => false,
                'getter' => static fn (SkillData $data): string => SkillHeader::render($data->title, $data->description),
                'setter' => static function (): void {},
                'help' => 'Generated from title and description. Read only.',
                'attr' => ['readonly' => true, 'rows' => 6, 'data-skill-header' => ''],
            ])
            ->add('content', TextareaType::class, ['label' => 'Instructions', 'trim' => false, 'help' => Help::NO_PERSONAL_DATA, 'attr' => ['maxlength' => 100000, 'rows' => 20]]);

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
