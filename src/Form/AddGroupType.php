<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Group;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/** @extends AbstractType<array{group: Group|null}> */
final class AddGroupType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('group', EntityType::class, [
                'class' => Group::class,
                'choices' => $options['available_groups'],
                'choice_label' => 'title',
                'label' => 'Add group',
                'placeholder' => 'Select a group',
                'constraints' => [new NotBlank(message: 'Please select a group.')],
            ])
            ->add('add', SubmitType::class, ['label' => 'Add']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('available_groups')->setAllowedTypes('available_groups', 'array');
    }
}
