<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/** @extends AbstractType<array<string, mixed>> */
final class ApiKeyType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('apiKey', PasswordType::class, [
                'label' => 'API Key',
                'trim' => true,
                'attr' => ['autocomplete' => 'off'],
                'constraints' => [new Assert\NotBlank(), new Assert\Length(min: 8, max: 512)],
            ])
            ->add('save', SubmitType::class, ['label' => 'Save']);
    }
}
