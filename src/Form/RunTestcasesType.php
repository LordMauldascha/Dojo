<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Testcase;

final class RunTestcasesType extends AbstractChecklistType
{
    protected function definition(): array
    {
        return ['class' => Testcase::class, 'field' => 'testcases', 'label' => 'Testcases', 'submit' => 'Save testcases', 'choice_label' => static fn (Testcase $testcase): string => $testcase->getActiveVersion()->getTitle()];
    }
}
