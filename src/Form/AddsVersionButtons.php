<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;

/** The buttons of the edit form of a versioned entity (skill, testcase). */
trait AddsVersionButtons
{
    /**
     * @template T
     *
     * @param FormBuilderInterface<T> $builder
     */
    private function addVersionButtons(FormBuilderInterface $builder): void
    {
        $builder
            // A disabled first submit button turns Enter in a text field into a no-op (implicit submission).
            ->add('noImplicitSubmit', SubmitType::class, ['label' => false, 'disabled' => true, 'attr' => ['hidden' => true, 'tabindex' => -1], 'row_attr' => ['hidden' => true]])
            ->add('saveDraft', SubmitType::class, ['label' => 'Save as draft'])
            ->add('saveFinal', SubmitType::class, ['label' => 'Save as final']);
    }
}
