<?php

declare(strict_types=1);

namespace App\Form;

use App\Security\PasswordPolicy;
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
                'label' => 'Current password',
                'attr' => ['autocomplete' => 'current-password'],
                'constraints' => [new Assert\NotBlank(), new UserPassword(message: 'The current password is wrong.')],
            ])
            ->add('newPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'first_options' => ['label' => 'New password', 'attr' => ['autocomplete' => 'new-password']],
                'second_options' => ['label' => 'Repeat new password', 'attr' => ['autocomplete' => 'new-password']],
                'invalid_message' => 'The passwords do not match.',
                'constraints' => PasswordPolicy::constraints(),
            ])
            ->add('save', SubmitType::class, ['label' => 'Change password']);
    }
}
