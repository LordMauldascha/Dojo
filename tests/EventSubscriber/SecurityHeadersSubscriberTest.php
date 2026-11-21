<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\SecurityHeadersSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class SecurityHeadersSubscriberTest extends TestCase
{
    public function testMainResponsesGetTheHeaders(): void
    {
        $request = new Request();
        $response = new Response();
        $subscriber = new SecurityHeadersSubscriber();
        $subscriber->onRequest($this->requestEvent($request, HttpKernelInterface::MAIN_REQUEST));

        $subscriber->onResponse($this->event($request, $response, HttpKernelInterface::MAIN_REQUEST));

        $nonce = $request->attributes->get(SecurityHeadersSubscriber::NONCE_ATTRIBUTE);
        self::assertIsString($nonce);
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('same-origin', $response->headers->get('Referrer-Policy'));
        self::assertSame(
            "default-src 'self'; script-src 'self' 'nonce-".$nonce."'; style-src 'self' 'nonce-".$nonce."'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'",
            $response->headers->get('Content-Security-Policy'),
        );
    }

    public function testNonceDiffersPerRequestAndIsAtLeast128BitsLong(): void
    {
        $subscriber = new SecurityHeadersSubscriber();
        $nonces = [];
        foreach ([new Request(), new Request()] as $request) {
            $subscriber->onRequest($this->requestEvent($request, HttpKernelInterface::MAIN_REQUEST));
            $nonce = $request->attributes->get(SecurityHeadersSubscriber::NONCE_ATTRIBUTE);
            self::assertIsString($nonce);
            $nonces[] = $nonce;
        }

        self::assertNotSame($nonces[0], $nonces[1]);
        self::assertSame(16, \strlen((string) base64_decode($nonces[0], true)));
    }

    public function testStrictTransportSecurityOnlyOnSecureRequests(): void
    {
        $subscriber = new SecurityHeadersSubscriber();

        $secure = new Response();
        $subscriber->onResponse($this->event(Request::create('https://dojo.test/'), $secure, HttpKernelInterface::MAIN_REQUEST));
        $plain = new Response();
        $subscriber->onResponse($this->event(Request::create('http://dojo.test/'), $plain, HttpKernelInterface::MAIN_REQUEST));

        self::assertSame('max-age=31536000', $secure->headers->get('Strict-Transport-Security'));
        self::assertFalse($plain->headers->has('Strict-Transport-Security'));
    }

    public function testHotReloadSourcesAreOnlyAllowedInDev(): void
    {
        $hotReload = 'https://localhost:2019/.well-known/mercure?topic=hot';
        $csp = static function (string $environment) use ($hotReload): string {
            $request = new Request(server: ['FRANKENPHP_HOT_RELOAD' => $hotReload]);
            $response = new Response();
            (new SecurityHeadersSubscriber($environment))->onResponse(new ResponseEvent(self::createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response));

            return (string) $response->headers->get('Content-Security-Policy');
        };

        self::assertStringContainsString("script-src 'self' 'nonce-", $csp('dev'));
        self::assertMatchesRegularExpression("~script-src 'self' 'nonce-[^']+' https://cdn\\.jsdelivr\\.net;~", $csp('dev'));
        self::assertStringContainsString("connect-src 'self' https://localhost:2019;", $csp('dev'));
        self::assertStringNotContainsString('jsdelivr', $csp('prod'));
        self::assertStringNotContainsString('2019', $csp('prod'));
    }

    public function testSubRequestsAreLeftAlone(): void
    {
        $response = new Response();

        (new SecurityHeadersSubscriber())->onResponse($this->event(new Request(), $response, HttpKernelInterface::SUB_REQUEST));

        self::assertFalse($response->headers->has('X-Content-Type-Options'));
        self::assertFalse($response->headers->has('Content-Security-Policy'));
    }

    private function event(Request $request, Response $response, int $type): ResponseEvent
    {
        return new ResponseEvent($this->createStub(HttpKernelInterface::class), $request, $type, $response);
    }

    private function requestEvent(Request $request, int $type): RequestEvent
    {
        return new RequestEvent($this->createStub(HttpKernelInterface::class), $request, $type);
    }
}
