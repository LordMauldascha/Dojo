<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\Compound;

/** PostgreSQL cannot store a NUL byte in text columns. */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class NoNulByte extends Compound
{
    protected function getConstraints(array $options): array
    {
        return [new Assert\Regex(pattern: '/\A[^\x00]*\z/', message: 'This value must not contain null bytes.')];
    }
}
