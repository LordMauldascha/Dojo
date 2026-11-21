<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Tests\Support\AppWebTestCase;

final class SecurityHeadersPageTest extends AppWebTestCase
{
    public function testEveryInlineScriptAndStyleOnTheLoginPageCarriesTheHeaderNonce(): void
    {
        $this->client->request('GET', '/login');

        $this->assertInlineTagsCarryTheNonce();
    }

    public function testEveryInlineScriptAndStyleOnALoggedInPageCarriesTheHeaderNonce(): void
    {
        $this->client->loginUser($this->persistUser('sensei'));
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $this->assertInlineTagsCarryTheNonce();
    }

    public function testEachPageGetsItsOwnNonce(): void
    {
        $nonces = [];
        foreach ([1, 2] as $_) {
            $this->client->request('GET', '/login');
            $nonces[] = $this->headerNonce();
        }

        self::assertNotSame($nonces[0], $nonces[1]);
    }

    private function assertInlineTagsCarryTheNonce(): void
    {
        $nonce = $this->headerNonce();
        $html = (string) $this->client->getResponse()->getContent();
        $inline = 0;
        // external scripts (src) are allowed by 'self', everything else is inline
        preg_match_all('~<(script|style)\b([^>]*)>~i', $html, $tags, \PREG_SET_ORDER);
        foreach ($tags as [$tag, , $attributes]) {
            if (str_contains($attributes, ' src=') || str_starts_with($attributes, 'src=')) {
                continue;
            }
            ++$inline;
            self::assertStringContainsString('nonce="'.$nonce.'"', $tag);
        }

        self::assertGreaterThanOrEqual(4, $inline, 'base.html.twig has one inline style and three inline scripts');
        self::assertStringNotContainsString(' style="', $html);
    }

    private function headerNonce(): string
    {
        $csp = (string) $this->client->getResponse()->headers->get('Content-Security-Policy');
        self::assertSame(1, preg_match("~script-src 'self' 'nonce-([^']+)'~", $csp, $script));
        self::assertSame(1, preg_match("~style-src 'self' 'nonce-([^']+)'~", $csp, $style));
        self::assertSame($script[1], $style[1]);

        return $script[1];
    }
}
