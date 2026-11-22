<?php

declare(strict_types=1);

namespace App\Ai;

use Symfony\AI\Platform\PlatformInterface;

interface PlatformProvider
{
    /** @throws MissingApiKeyException if the credential is not stored */
    public function forCredential(Credential $credential): PlatformInterface;
}
