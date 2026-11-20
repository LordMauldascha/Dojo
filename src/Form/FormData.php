<?php

declare(strict_types=1);

namespace App\Form;

final class FormData
{
    /**
     * Entities selected in an EntityType field (a Collection or an array depending on the form data).
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    public static function entities(string $class, mixed $data): array
    {
        $items = \is_iterable($data) ? iterator_to_array($data, false) : [];

        return array_values(array_filter($items, static fn (mixed $item): bool => $item instanceof $class));
    }
}
