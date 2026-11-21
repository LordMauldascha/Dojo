<?php

declare(strict_types=1);

namespace App\Form;

use App\Ai\ModelCatalog;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Provider and model of the LLM judge as one select grouped by provider (value "provider:model").
 *
 * @extends AbstractType<array{model: string|null}>
 */
final class JudgeConfigurationType extends AbstractType
{
    public function __construct(private readonly ModelCatalog $catalog)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('model', ChoiceType::class, [
                'choices' => $this->catalog->choices(),
                'label' => 'Provider and model',
                'placeholder' => 'Select a model',
                'constraints' => [new NotBlank(message: 'Please select a model.')],
            ])
            ->add('save', SubmitType::class, ['label' => 'Save']);
    }
}
