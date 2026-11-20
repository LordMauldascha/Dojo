<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;

final class AddUserType extends AbstractAddEntityType
{
    protected function definition(): array
    {
        return ['class' => User::class, 'field' => 'user', 'noun' => 'user', 'choice_label' => 'username'];
    }
}
