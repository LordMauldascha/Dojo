<?php

declare(strict_types=1);

namespace App\Entity\Trait;

use Doctrine\ORM\Mapping as ORM;

/** Requires `$title` and `touch()` (HasTimestamps) in the using class. */
trait HasTitleKey
{
    /** Lower-cased title, unique, so titles differ by more than their letter case. */
    #[ORM\Column(length: 512, unique: true)]
    private string $titleKey = '';

    public function setTitle(?string $title): void
    {
        $title = trim($title ?? '');
        if ($title !== $this->title) {
            $this->title = $title;
            $this->titleKey = mb_strtolower($title);
            $this->touch();
        }
    }
}
