<?php

declare(strict_types=1);

namespace App\Form\Model;

/** For form models with a nullable $title and $description. */
trait NormalizesText
{
    public function normalizedTitle(): string
    {
        return trim($this->title ?? '');
    }

    public function normalizedDescription(): ?string
    {
        $description = trim($this->description ?? '');

        return '' === $description ? null : $description;
    }
}
