<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/** @extends AbstractType<array{user: User|null}> */
final class GroupAddUserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('user', EntityType::class, [
                'class' => User::class,
                'choices' => $options['available_users'],
                'choice_label' => 'username',
                'label' => 'Benutzer hinzufügen',
                'placeholder' => 'Benutzer wählen',
                'constraints' => [new NotBlank(message: 'Bitte einen Benutzer wählen.')],
            ])
            ->add('add', SubmitType::class, ['label' => 'Hinzufügen']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('available_users')->setAllowedTypes('available_users', 'array');
    }
}
