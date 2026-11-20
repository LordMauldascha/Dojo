<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/** Limits state-changing requests per signed-in user (the login has its own throttling). */
final readonly class WriteRateLimitSubscriber implements EventSubscriberInterface
{
    public function __construct(
        #[Target('writeLimiter')]
        private RateLimiterFactoryInterface $limiter,
        private TokenStorageInterface $tokenStorage,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // after the firewall (priority 8), so the user is known
        return [KernelEvents::REQUEST => ['onRequest', 0]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || $event->getRequest()->isMethodSafe()) {
            return;
        }

        $user = $this->tokenStorage->getToken()?->getUser();
        if (null === $user) {
            return;
        }

        $limit = $this->limiter->create($user->getUserIdentifier())->consume();
        if (!$limit->isAccepted()) {
            $retryAfter = $limit->getRetryAfter()->getTimestamp() - time();

            throw new TooManyRequestsHttpException(max(1, $retryAfter), 'Too many changes in a short time. Please wait a moment.');
        }
    }
}
