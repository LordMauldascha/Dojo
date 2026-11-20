<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Testcase;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<array{testcases: list<Testcase>}> */
final class RunTestcasesType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('testcases', EntityType::class, [
                'class' => Testcase::class,
                'choices' => $options['available_testcases'],
                'choice_label' => static fn (Testcase $testcase): string => $testcase->getActiveVersion()->getTitle(),
                'label' => 'Testcases',
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ])
            ->add('save', SubmitType::class, ['label' => 'Save testcases']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('available_testcases')->setAllowedTypes('available_testcases', 'array');
    }
}
