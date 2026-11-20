<?php

declare(strict_types=1);

namespace App\Routing;

final class Requirements
{
    /** Database ids and list positions; bounded so huge numbers are a 404, not a 500. */
    public const ID = '\d{1,9}';
}
