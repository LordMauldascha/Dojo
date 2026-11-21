<?php

declare(strict_types=1);

namespace App\Ai;

enum HuggingFaceModelAddResult
{
    case Added;
    case AlreadyAdded;
    case NotFoundOnHub;
    case HubUnavailable;
    /** A concurrent request added the same model first; the entity manager is closed then. */
    case Conflict;
}
