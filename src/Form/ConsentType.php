<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;

/** @extends AbstractType<array<string, mixed>> */
final class ConsentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('approve', SubmitType::class, ['label' => 'Approve', 'attr' => ['class' => 'button-primary']])
            ->add('deny', SubmitType::class, ['label' => 'Deny']);
    }
}
