<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Skill;

final class AddSkillType extends AbstractAddEntityType
{
    protected function definition(): array
    {
        return ['class' => Skill::class, 'field' => 'skill', 'noun' => 'skill', 'choice_label' => static fn (Skill $skill): string => $skill->getActiveVersion()->getTitle()];
    }
}
