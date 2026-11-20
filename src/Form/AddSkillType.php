<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Skill;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/** @extends AbstractType<array{skill: Skill|null}> */
final class AddSkillType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('skill', EntityType::class, [
                'class' => Skill::class,
                'choices' => $options['available_skills'],
                'choice_label' => static fn (Skill $skill): string => $skill->getActiveVersion()->getTitle(),
                'label' => 'Add skill',
                'placeholder' => 'Select a skill',
                'constraints' => [new NotBlank(message: 'Please select a skill.')],
            ])
            ->add('add', SubmitType::class, ['label' => 'Add']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('available_skills')->setAllowedTypes('available_skills', 'array');
    }
}
