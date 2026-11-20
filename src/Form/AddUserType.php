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
final class AddUserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('user', EntityType::class, [
                'class' => User::class,
                'choices' => $options['available_users'],
                'choice_label' => 'username',
                'label' => 'Add user',
                'placeholder' => 'Select a user',
                'constraints' => [new NotBlank(message: 'Please select a user.')],
            ])
            ->add('add', SubmitType::class, ['label' => 'Add']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('available_users')->setAllowedTypes('available_users', 'array');
    }
}
