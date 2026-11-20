<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Skill;

final class RunSkillsType extends AbstractChecklistType
{
    protected function definition(): array
    {
        return ['class' => Skill::class, 'field' => 'skills', 'label' => 'Skills', 'submit' => 'Save skills', 'choice_label' => static fn (Skill $skill): string => $skill->getActiveVersion()->getTitle()];
    }
}
