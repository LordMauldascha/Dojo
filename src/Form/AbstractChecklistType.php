<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A checkbox list of entities with a save button. A subclass only names the entity; its short class name gives
 * the form name (RunSkillsType is "run_skills").
 *
 * @extends AbstractType<array<string, mixed>>
 */
abstract class AbstractChecklistType extends AbstractType
{
    /** @return array{class: class-string, field: string, label: string, submit: string, choice_label: string|\Closure} */
    abstract protected function definition(): array;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $definition = $this->definition();

        $builder
            ->add($definition['field'], EntityType::class, [
                'class' => $definition['class'],
                'choices' => $options['choices'],
                'choice_label' => $definition['choice_label'],
                'label' => $definition['label'],
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ])
            ->add('save', SubmitType::class, ['label' => $definition['submit']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('choices')->setAllowedTypes('choices', 'array');
    }
}
