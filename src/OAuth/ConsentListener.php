<?php

declare(strict_types=1);

namespace App\OAuth;

use App\Controller\OAuthController;
use League\Bundle\OAuth2ServerBundle\Event\AuthorizationRequestResolveEvent;
use League\Bundle\OAuth2ServerBundle\OAuth2Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The signed-in user must approve every authorization request on the consent page. The page leaves the decision in the
 * session; it is consumed once and only for the client it was given for.
 */
final readonly class ConsentListener
{
    public const PENDING = 'oauth_pending';
    public const DECISION = 'oauth_decision';

    public function __construct(
        private RequestStack $requests,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[AsEventListener(event: OAuth2Events::AUTHORIZATION_REQUEST_RESOLVE)]
    public function __invoke(AuthorizationRequestResolveEvent $event): void
    {
        $session = $this->requests->getSession();
        $clientId = $event->getClient()->getIdentifier();

        $decision = $session->remove(self::DECISION);
        if (\is_array($decision) && $clientId === ($decision['client'] ?? null)) {
            $event->resolveAuthorization(true === ($decision['approved'] ?? false));

            return;
        }

        $session->set(self::PENDING, ['client' => $clientId, 'return' => $this->requests->getCurrentRequest()?->getUri()]);
        $event->setResponse(new RedirectResponse($this->urls->generate(OAuthController::CONSENT_ROUTE)));
    }
}
