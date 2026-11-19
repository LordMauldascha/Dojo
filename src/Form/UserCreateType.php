<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use App\Security\UserRole;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** @extends AbstractType<User> */
final class UserCreateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('username', TextType::class, ['label' => 'Benutzername', 'attr' => ['autocomplete' => 'off']])
            ->add('plainPassword', RepeatedType::class, [
                'mapped' => false,
                'type' => PasswordType::class,
                'first_options' => ['label' => 'Passwort', 'attr' => ['autocomplete' => 'new-password']],
                'second_options' => ['label' => 'Passwort wiederholen', 'attr' => ['autocomplete' => 'new-password']],
                'invalid_message' => 'Die Passwörter stimmen nicht überein.',
                'constraints' => [new Assert\NotBlank(), new Assert\Length(min: 12, max: 4096)],
            ])
            ->add('role', EnumType::class, [
                'class' => UserRole::class,
                'label' => 'Rolle',
                'choice_label' => static fn (UserRole $role): string => $role->label(),
            ])
            ->add('save', SubmitType::class, ['label' => 'Benutzer anlegen']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => User::class]);
    }
}
