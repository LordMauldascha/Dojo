<?php

declare(strict_types=1);

namespace App\Pagination;

use Symfony\Component\HttpFoundation\RequestStack;

final readonly class PageSizeResolver
{
    public const SIZES = [10, 20, 50, 100];
    public const DEFAULT_SIZE = 10;

    public function __construct(private RequestStack $requestStack)
    {
    }

    /** URL value wins and is remembered per list, then the session value, then the default. */
    public function resolve(string $listKey, ?int $perPage): int
    {
        $session = $this->requestStack->getSession();
        $sessionKey = 'page_size.'.$listKey;

        if (null !== $perPage && \in_array($perPage, self::SIZES, true)) {
            $session->set($sessionKey, $perPage);

            return $perPage;
        }

        $stored = $session->get($sessionKey);

        return \is_int($stored) && \in_array($stored, self::SIZES, true) ? $stored : self::DEFAULT_SIZE;
    }
}
