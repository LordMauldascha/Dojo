<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints as Assert;

/** @extends AbstractType<array<string, mixed>> */
final class ChangePasswordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('currentPassword', PasswordType::class, [
                'label' => 'Aktuelles Passwort',
                'attr' => ['autocomplete' => 'current-password'],
                'constraints' => [new Assert\NotBlank(), new UserPassword(message: 'Das aktuelle Passwort ist falsch.')],
            ])
            ->add('newPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'first_options' => ['label' => 'Neues Passwort', 'attr' => ['autocomplete' => 'new-password']],
                'second_options' => ['label' => 'Neues Passwort wiederholen', 'attr' => ['autocomplete' => 'new-password']],
                'invalid_message' => 'Die Passwörter stimmen nicht überein.',
                'constraints' => [new Assert\NotBlank(), new Assert\Length(min: 12, max: 4096)],
            ])
            ->add('save', SubmitType::class, ['label' => 'Passwort ändern']);
    }
}
