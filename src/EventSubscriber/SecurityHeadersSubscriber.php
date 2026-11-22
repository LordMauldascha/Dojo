<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Controller\OAuthController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/** Defence in depth: strict CSP with a per-request nonce, HSTS, no content sniffing, no framing, no referrer leaks. */
final readonly class SecurityHeadersSubscriber
{
    public const NONCE_ATTRIBUTE = '_csp_nonce';

    public function __construct(
        #[Autowire('%kernel.environment%')]
        private string $environment = 'prod',
    ) {
    }

    // early, so the nonce exists before any controller renders a template
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 256)]
    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->nonce($event->getRequest());
        }
    }

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $headers = $event->getResponse()->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'same-origin');
        $headers->set('Content-Security-Policy', $this->policy($request, $this->nonce($request)));
        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000');
        }
    }

    private function nonce(Request $request): string
    {
        $nonce = $request->attributes->get(self::NONCE_ATTRIBUTE);
        if (!\is_string($nonce)) {
            $nonce = base64_encode(random_bytes(16));
            $request->attributes->set(self::NONCE_ATTRIBUTE, $nonce);
        }

        return $nonce;
    }

    private function policy(Request $request, string $nonce): string
    {
        $scriptSources = ["'self'", "'nonce-".$nonce."'"];
        $connectSources = ["'self'"];

        // the FrankenPHP hot reload (dev only, see base.html.twig) loads its script from a CDN and listens to an event stream
        $hotReload = $request->server->get('FRANKENPHP_HOT_RELOAD');
        if ('dev' === $this->environment && \is_string($hotReload) && '' !== $hotReload) {
            $scriptSources[] = 'https://cdn.jsdelivr.net';
            $parts = parse_url($hotReload);
            if (\is_array($parts) && isset($parts['scheme'], $parts['host'])) {
                $connectSources[] = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
            }
        }

        // Chrome applies form-action to the redirects after a submit: the consent form ends at the agent's loopback redirect URI
        $formActions = ["'self'"];
        if (OAuthController::CONSENT_ROUTE === $request->attributes->get('_route')) {
            array_push($formActions, 'http://127.0.0.1:*', 'http://localhost:*');
        }

        return implode('; ', [
            "default-src 'self'",
            'script-src '.implode(' ', $scriptSources),
            "style-src 'self' 'nonce-".$nonce."'",
            "img-src 'self' data:",
            "font-src 'self'",
            'connect-src '.implode(' ', $connectSources),
            "object-src 'none'",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            'form-action '.implode(' ', $formActions),
        ]);
    }
}
