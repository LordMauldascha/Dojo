<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Project;

final class SkillProjectsType extends AbstractChecklistType
{
    protected function definition(): array
    {
        return ['class' => Project::class, 'field' => 'projects', 'label' => 'Projects', 'submit' => 'Save projects', 'choice_label' => 'title'];
    }
}
