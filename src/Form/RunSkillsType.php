<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Skill;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<array{skills: list<Skill>}> */
final class RunSkillsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('skills', EntityType::class, [
                'class' => Skill::class,
                'choices' => $options['available_skills'],
                'choice_label' => static fn (Skill $skill): string => $skill->getActiveVersion()->getTitle(),
                'label' => 'Skills',
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ])
            ->add('save', SubmitType::class, ['label' => 'Save skills']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('available_skills')->setAllowedTypes('available_skills', 'array');
    }
}
