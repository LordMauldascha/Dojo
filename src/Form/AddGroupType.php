<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Group;

final class AddGroupType extends AbstractAddEntityType
{
    protected function definition(): array
    {
        return ['class' => Group::class, 'field' => 'group', 'noun' => 'group', 'choice_label' => 'title'];
    }
}
