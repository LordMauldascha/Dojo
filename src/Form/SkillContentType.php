<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\SkillVersion;
use App\Form\Model\SkillData;
use App\Skill\SkillHeader;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Title, description, the generated skill header and the instructions (the SKILL.md body). With the "projects" option (list of selectable projects) the form
 * also asks for the project assignment and offers a single create button, else the draft/final buttons.
 *
 * @extends AbstractType<SkillData>
 */
final class SkillContentType extends AbstractType
{
    use AddsContentFields;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addTitleAndDescription($builder, SkillVersion::TITLE_MAX, SkillVersion::DESCRIPTION_MAX);
        $builder
            ->add('skillHeader', TextareaType::class, [
                'label' => 'Skills Header',
                'required' => false,
                'getter' => static fn (SkillData $data): string => SkillHeader::render($data->title, $data->description),
                'setter' => static function (): void {},
                'help' => 'Generated from title and description. Read only.',
                'attr' => ['readonly' => true, 'rows' => 6, 'data-skill-header' => ''],
            ])
            ->add('content', TextareaType::class, ['label' => 'Instructions', 'trim' => false, 'help' => Help::NO_PERSONAL_DATA, 'attr' => ['maxlength' => 100000, 'rows' => 20]]);

        $this->addProjectsOrVersionButtons($builder, $options, 'Create skill');
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $this->configureContentOptions($resolver, SkillData::class);
    }
}
