<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\SecurityHeadersSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class SecurityHeadersSubscriberTest extends TestCase
{
    public function testMainResponsesGetTheHeaders(): void
    {
        $response = new Response();

        (new SecurityHeadersSubscriber())->onResponse($this->event($response, HttpKernelInterface::MAIN_REQUEST));

        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('same-origin', $response->headers->get('Referrer-Policy'));
        self::assertStringContainsString("frame-ancestors 'self'", (string) $response->headers->get('Content-Security-Policy'));
    }

    public function testSubRequestsAreLeftAlone(): void
    {
        $response = new Response();

        (new SecurityHeadersSubscriber())->onResponse($this->event($response, HttpKernelInterface::SUB_REQUEST));

        self::assertFalse($response->headers->has('X-Content-Type-Options'));
    }

    private function event(Response $response, int $type): ResponseEvent
    {
        return new ResponseEvent($this->createStub(HttpKernelInterface::class), new Request(), $type, $response);
    }
}
