<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Run;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<Run> */
final class RunType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, ['label' => 'Title', 'attr' => ['maxlength' => 255]])
            ->add('description', TextareaType::class, ['label' => 'Description', 'required' => false, 'help' => Help::NO_PERSONAL_DATA, 'attr' => ['maxlength' => 2000, 'rows' => 4]])
            ->add('save', SubmitType::class, ['label' => 'Save']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Run::class]);
    }
}
