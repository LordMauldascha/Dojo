<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\Support\McpWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class OAuthFlowTest extends McpWebTestCase
{
    public function testMcpWithoutTokenPointsToTheResourceMetadata(): void
    {
        $this->client->request('POST', '/mcp');

        self::assertResponseStatusCodeSame(401);
        self::assertSame(
            'Bearer resource_metadata="http://localhost/.well-known/oauth-protected-resource", scope="mcp"',
            $this->client->getResponse()->headers->get('WWW-Authenticate'),
        );
    }

    public function testMcpWithInvalidTokenIsRefusedWithTheSameChallenge(): void
    {
        $this->client->request('POST', '/mcp', [], [], ['HTTP_AUTHORIZATION' => 'Bearer not-a-token']);

        self::assertResponseStatusCodeSame(401);
        self::assertStringContainsString('resource_metadata=', (string) $this->client->getResponse()->headers->get('WWW-Authenticate'));
    }

    public function testDiscoveryDocuments(): void
    {
        $this->client->request('GET', '/.well-known/oauth-protected-resource');
        $resource = $this->decoded();
        self::assertSame('http://localhost/mcp', $resource['resource']);
        self::assertSame(['http://localhost'], $resource['authorization_servers']);

        $this->client->request('GET', '/.well-known/oauth-authorization-server');
        $server = $this->decoded();
        self::assertSame('http://localhost/token', $server['token_endpoint']);
        self::assertSame('http://localhost/oauth/register', $server['registration_endpoint']);
        self::assertSame(['S256'], $server['code_challenge_methods_supported']);
    }

    public function testRegistrationRefusesRedirectUrisOutsideTheLoopback(): void
    {
        foreach (['https://evil.example/cb', 'http://evil.example/cb', 'http://127.0.0.1.evil.example/cb', 'javascript:alert(1)'] as $uri) {
            $this->jsonRequest('POST', '/oauth/register', ['client_name' => 'Agent', 'redirect_uris' => [$uri]]);
            self::assertResponseStatusCodeSame(400, $uri);
        }

        $this->jsonRequest('POST', '/oauth/register', ['client_name' => '', 'redirect_uris' => [self::REDIRECT_URI]]);
        self::assertResponseStatusCodeSame(400);
    }

    public function testAuthorizeAsksForConsentAndIssuesATokenThatActsAsTheUser(): void
    {
        $user = $this->persistUser('agent-user');
        $token = $this->connect($user);

        $initialize = $this->rpc($token, 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'test', 'version' => '1']]);
        self::assertSame('2.0', $initialize['jsonrpc']);
        self::assertArrayHasKey('result', $initialize);
    }

    public function testDenyAnswersAccessDeniedToTheAgent(): void
    {
        $user = $this->persistUser('agent-user');
        $clientId = $this->registerClient();

        $location = $this->authorize($user, $clientId, 'verifier-0123456789', approve: false);

        self::assertStringStartsWith(self::REDIRECT_URI.'?', $location);
        self::assertStringContainsString('error=access_denied', $location);
        self::assertStringContainsString('state=xyz', $location);
    }

    public function testApprovalIsUsedOnceAndAskedAgain(): void
    {
        $user = $this->persistUser('agent-user');
        $clientId = $this->registerClient();
        $this->authorize($user, $clientId, 'verifier-0123456789');

        $this->client->request('GET', '/authorize', [
            'response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => self::REDIRECT_URI, 'scope' => 'mcp',
            'code_challenge' => str_repeat('a', 43), 'code_challenge_method' => 'S256',
        ]);

        self::assertResponseRedirects('http://localhost/oauth/consent');
    }

    public function testOnlyTheConsentPageMayFormPostToTheLoopback(): void
    {
        $user = $this->persistUser('agent-user');
        $clientId = $this->registerClient();
        $this->authorize($user, $clientId, 'verifier-0123456789', approve: false);
        $this->client->request('GET', '/authorize', [
            'response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => self::REDIRECT_URI, 'scope' => 'mcp',
            'code_challenge' => str_repeat('a', 43), 'code_challenge_method' => 'S256',
        ]);
        $this->client->followRedirect();
        self::assertStringContainsString("form-action 'self' http://127.0.0.1:* http://localhost:*", (string) $this->client->getResponse()->headers->get('Content-Security-Policy'));

        $this->client->request('GET', '/account');
        self::assertStringContainsString("form-action 'self'", (string) $this->client->getResponse()->headers->get('Content-Security-Policy'));
        self::assertStringNotContainsString('127.0.0.1', (string) $this->client->getResponse()->headers->get('Content-Security-Policy'));
    }

    public function testPlainPkceIsRefused(): void
    {
        $user = $this->persistUser('agent-user');
        $clientId = $this->registerClient();
        $this->client->loginUser($user);

        $this->client->request('GET', '/authorize', [
            'response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => self::REDIRECT_URI, 'scope' => 'mcp',
            'code_challenge' => 'plain-challenge', 'code_challenge_method' => 'plain',
        ]);

        self::assertFalse($this->client->getResponse()->isRedirect('http://localhost/oauth/consent'));
    }

    public function testTokenOfADisabledUserIsRefused(): void
    {
        $user = $this->persistUser('agent-user');
        $token = $this->connect($user);
        static::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('UPDATE app_user SET enabled = false');

        $this->rpcRaw($token);

        self::assertResponseStatusCodeSame(401);
    }

    private function rpcRaw(string $token): void
    {
        $this->client->request('POST', '/mcp', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'test', 'version' => '1']]], \JSON_THROW_ON_ERROR));
    }
}
