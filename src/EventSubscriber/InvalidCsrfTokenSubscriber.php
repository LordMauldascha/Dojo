<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;

/**
 * #[IsCsrfTokenValid] throws an AuthenticationException, which the firewall would turn into a redirect to the login
 * (and a logged-out user). A forged request must simply be refused with 403.
 */
final class InvalidCsrfTokenSubscriber
{
    // before the firewall's exception listener (priority 1)
    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: 10)]
    public function onException(ExceptionEvent $event): void
    {
        if ($event->getThrowable() instanceof InvalidCsrfTokenException) {
            // no previous exception: the firewall listener walks the chain and would find the AuthenticationException again
            $event->setThrowable(new AccessDeniedHttpException('Invalid CSRF token.'));
        }
    }
}
