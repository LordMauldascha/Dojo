<?php

declare(strict_types=1);

namespace App\OAuth;

use App\Controller\OAuthController;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** A 401 from the MCP endpoint points the client to the protected resource metadata (RFC 9728), which starts the OAuth flow. */
final readonly class McpChallengeListener
{
    public function __construct(private UrlGeneratorInterface $urls)
    {
    }

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function __invoke(ResponseEvent $event): void
    {
        $response = $event->getResponse();
        if (!$event->isMainRequest() || Response::HTTP_UNAUTHORIZED !== $response->getStatusCode()) {
            return;
        }
        if (!str_starts_with($event->getRequest()->getPathInfo(), $this->urls->generate('_mcp_endpoint_default'))) {
            return;
        }

        $metadata = $this->urls->generate(OAuthController::RESOURCE_METADATA_ROUTE, [], UrlGeneratorInterface::ABSOLUTE_URL);
        $response->headers->set('WWW-Authenticate', \sprintf('Bearer resource_metadata="%s", scope="%s"', $metadata, OAuthController::SCOPE));
    }
}
