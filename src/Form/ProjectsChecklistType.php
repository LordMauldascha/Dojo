<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Project;

/** The project checklist of a versioned entity. A subclass only names the form (SkillProjectsType is "skill_projects"). */
abstract class ProjectsChecklistType extends AbstractChecklistType
{
    protected function definition(): array
    {
        return ['class' => Project::class, 'field' => 'projects', 'label' => 'Projects', 'submit' => 'Save projects', 'choice_label' => 'title'];
    }
}
