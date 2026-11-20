<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * One select of the entities that can still be added plus an "Add" button. A subclass only names the entity;
 * its short class name gives the form name (AddUserType is "add_user").
 *
 * @extends AbstractType<array<string, mixed>>
 */
abstract class AbstractAddEntityType extends AbstractType
{
    /** @return array{class: class-string, field: string, noun: string, choice_label: string|\Closure} */
    abstract protected function definition(): array;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $definition = $this->definition();

        $builder
            ->add($definition['field'], EntityType::class, [
                'class' => $definition['class'],
                'choices' => $options['choices'],
                'choice_label' => $definition['choice_label'],
                'label' => 'Add '.$definition['noun'],
                'placeholder' => 'Select a '.$definition['noun'],
                'constraints' => [new NotBlank(message: 'Please select a '.$definition['noun'].'.')],
            ])
            ->add('add', SubmitType::class, ['label' => 'Add']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('choices')->setAllowedTypes('choices', 'array');
    }
}
