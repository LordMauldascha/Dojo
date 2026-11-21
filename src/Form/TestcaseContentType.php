<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\TestcaseVersion;
use App\Form\Model\TestcaseData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Title, description, prompt and output criteria. With the "projects" option (list of selectable projects) the form
 * also asks for the projects and offers a single create button, else the draft/final buttons.
 *
 * @extends AbstractType<TestcaseData>
 */
final class TestcaseContentType extends AbstractType
{
    use AddsContentFields;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addTitleAndDescription($builder, TestcaseVersion::TITLE_MAX, TestcaseVersion::DESCRIPTION_MAX);
        $builder
            ->add('prompt', TextareaType::class, [
                'label' => 'Prompt',
                'trim' => false,
                'help' => Help::NO_PERSONAL_DATA,
                'attr' => ['maxlength' => 100000, 'rows' => 10],
            ])
            ->add('outputCriteria', TextareaType::class, ['label' => 'Output criteria', 'trim' => false, 'help' => 'One criterion per line. The LLM judge checks the output against them. '.Help::NO_PERSONAL_DATA, 'attr' => ['maxlength' => 100000, 'rows' => 10]]);

        $this->addProjectsOrVersionButtons($builder, $options, 'Create testcase');
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $this->configureContentOptions($resolver, TestcaseData::class);
    }
}
