<?php

declare(strict_types=1);

namespace App\Form;

use App\Ai\ModelCatalog;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Required select of the model catalog grouped by provider (value "provider:model").
 *
 * @extends AbstractType<string|null>
 */
final class ModelChoiceType extends AbstractType
{
    public function __construct(private readonly ModelCatalog $catalog)
    {
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'choices' => $this->catalog->choices(),
            'label' => 'Provider and model',
            'placeholder' => 'Select a model',
            'constraints' => [new NotBlank(message: 'Please select a model.')],
        ]);
    }

    public function getParent(): string
    {
        return ChoiceType::class;
    }
}
