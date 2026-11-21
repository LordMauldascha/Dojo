<?php

declare(strict_types=1);

namespace App\Entity\Trait;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** The using constructor assigns `$this->uuid = Uuid::v7()`. */
trait HasUuid
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $uuid;

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }
}
