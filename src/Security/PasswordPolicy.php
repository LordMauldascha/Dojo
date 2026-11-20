<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints as Assert;

/** One password rule for the web forms and the console command. */
final class PasswordPolicy
{
    public const MIN_LENGTH = 12;

    /** @return list<Constraint> */
    public static function constraints(): array
    {
        return [new Assert\NotBlank(), new Assert\Length(min: self::MIN_LENGTH, max: 4096)];
    }
}
