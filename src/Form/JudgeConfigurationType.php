<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Provider and model of the LLM judge or the AI helper as one select grouped by provider (value "provider:model").
 *
 * @extends AbstractType<array{model: string|null}>
 */
final class JudgeConfigurationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('model', ModelChoiceType::class)
            ->add('save', SubmitType::class, ['label' => 'Save']);
    }
}
