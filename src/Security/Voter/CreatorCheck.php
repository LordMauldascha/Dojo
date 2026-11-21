<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\User;

/** The rule shared by the voters of user-created entities: only the creator may delete. */
final class CreatorCheck
{
    public static function isCreator(?User $creator, User $user): bool
    {
        return $creator?->getId() === $user->getId();
    }
}
