<?php

declare(strict_types=1);

namespace App\Form;

use App\Ai\Effort;
use App\Ai\LlmConfiguration;
use App\Ai\Provider;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * One model select grouped by provider (value "provider:model") plus an optional effort level.
 *
 * @extends AbstractType<array{model: string|null, effort: Effort|null}>
 */
final class RunConfigurationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $choices = [];
        foreach (Provider::cases() as $provider) {
            foreach ($provider->models() as $model) {
                $choices[$provider->displayName()][$model] = LlmConfiguration::choiceValue($provider, $model);
            }
        }

        $builder
            ->add('model', ChoiceType::class, [
                'choices' => $choices,
                'label' => 'Provider and model',
                'placeholder' => 'Select a model',
                'constraints' => [new NotBlank(message: 'Please select a model.')],
            ])
            ->add('effort', EnumType::class, [
                'class' => Effort::class,
                'label' => 'Effort',
                'placeholder' => 'No effort',
                'required' => false,
                'choice_label' => static fn (Effort $effort): string => $effort->value,
            ])
            ->add('add', SubmitType::class, ['label' => 'Add configuration']);
    }
}
