<?php

declare(strict_types=1);

namespace App\Form;

use App\Validator\NoNulByte;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The description an AI helper turns into a skill or testcase.
 *
 * @extends AbstractType<array{description: string|null}>
 */
final class GenerateType extends AbstractType
{
    public const DESCRIPTION_MAX = 5000;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'help' => 'It is sent to the provider of the AI helper. '.Help::NO_PERSONAL_DATA,
                'attr' => ['maxlength' => self::DESCRIPTION_MAX, 'rows' => 10],
                'constraints' => [new Assert\NotBlank(normalizer: 'trim'), new Assert\Length(max: self::DESCRIPTION_MAX), new NoNulByte()],
            ])
            ->add('generate', SubmitType::class, ['label' => 'Generate']);
    }
}
