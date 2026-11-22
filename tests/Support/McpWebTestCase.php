<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\User;

/** Talks to /mcp like an agent: full OAuth flow (register, authorize, consent, token with PKCE), then JSON-RPC. */
abstract class McpWebTestCase extends AppWebTestCase
{
    protected const REDIRECT_URI = 'http://127.0.0.1:53682/callback';

    /** @return non-empty-string client id */
    protected function registerClient(string $name = 'Test agent', string $redirectUri = self::REDIRECT_URI): string
    {
        $this->jsonRequest('POST', '/oauth/register', ['client_name' => $name, 'redirect_uris' => [$redirectUri]]);
        self::assertResponseStatusCodeSame(201);
        $clientId = $this->decoded()['client_id'];
        \assert(\is_string($clientId) && '' !== $clientId);

        return $clientId;
    }

    /**
     * Signs the user in, walks through authorize and consent and returns the redirect Location of the agent (code or error).
     *
     * @param array<string, string> $extra
     */
    protected function authorize(User $user, string $clientId, string $verifier, bool $approve = true, array $extra = []): string
    {
        $this->client->loginUser($user);
        $this->client->request('GET', '/authorize', $extra + [
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT_URI,
            'scope' => 'mcp',
            'state' => 'xyz',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]);
        self::assertResponseRedirects('http://localhost/oauth/consent');
        $this->client->followRedirect();
        $this->client->submitForm($approve ? 'Approve' : 'Deny');
        $this->client->followRedirect();
        self::assertTrue($this->client->getResponse()->isRedirect());

        return (string) $this->client->getResponse()->headers->get('Location');
    }

    /** @return array<mixed> */
    protected function exchange(string $clientId, string $code, string $verifier): array
    {
        $this->client->request('POST', '/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'code' => $code,
            'redirect_uri' => self::REDIRECT_URI,
            'code_verifier' => $verifier,
        ]);

        return $this->decoded();
    }

    /** @return non-empty-string access token of an approved agent acting as the user; pass a client id to let several users approve the same agent */
    protected function connect(User $user, ?string $clientId = null): string
    {
        $clientId ??= $this->registerClient();
        $verifier = bin2hex(random_bytes(32));
        $location = $this->authorize($user, $clientId, $verifier);
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);
        self::assertIsString($query['code'] ?? null, $location);
        $token = $this->exchange($clientId, $query['code'], $verifier)['access_token'] ?? null;
        \assert(\is_string($token) && '' !== $token);

        return $token;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<mixed> the decoded JSON-RPC response
     */
    protected function rpc(string $token, string $method, array $params = [], ?string $session = null): array
    {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ];
        if (null !== $session) {
            $server['HTTP_MCP_SESSION_ID'] = $session;
        }
        $this->client->request('POST', '/mcp', [], [], $server, json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params], \JSON_THROW_ON_ERROR));

        return $this->decoded();
    }

    /**
     * Calls a tool in a fresh MCP session (initialize, initialized, tools/call).
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{isError: bool, data: array<mixed>, text: string} data is the decoded result, empty when the tool returned no object
     */
    protected function callTool(string $token, string $name, array $arguments = []): array
    {
        $this->rpc($token, 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'test', 'version' => '1']]);
        $session = $this->client->getResponse()->headers->get('Mcp-Session-Id');
        self::assertIsString($session);
        $this->notify($token, 'notifications/initialized', $session);

        $response = $this->rpc($token, 'tools/call', ['name' => $name, 'arguments' => (object) $arguments], $session);
        self::assertArrayNotHasKey('error', $response, json_encode($response, \JSON_THROW_ON_ERROR));
        $result = $response['result'];
        self::assertIsArray($result);
        $content = $result['content'] ?? [];
        self::assertIsArray($content);
        $first = $content[0] ?? [];
        self::assertIsArray($first);
        $text = $first['text'] ?? '';
        self::assertIsString($text);
        $data = $result['structuredContent'] ?? json_decode($text, true);

        return [
            'isError' => true === ($result['isError'] ?? false),
            'data' => \is_array($data) ? $data : [],
            'text' => $text,
        ];
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed> the list or object under $key
     */
    protected function listAt(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        self::assertIsArray($value, \sprintf('"%s" is missing or not a list: %s', $key, json_encode($data)));

        return $value;
    }

    private function notify(string $token, string $method, string $session): void
    {
        $this->client->request('POST', '/mcp', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'HTTP_MCP_SESSION_ID' => $session,
        ], json_encode(['jsonrpc' => '2.0', 'method' => $method], \JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $body */
    protected function jsonRequest(string $method, string $uri, array $body): void
    {
        $this->client->request($method, $uri, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /** @return array<mixed> JSON body, or the first "data:" line of an event stream */
    protected function decoded(): array
    {
        $content = $this->client->getResponse()->getContent();
        self::assertIsString($content);
        if (str_starts_with($content, 'event:') || str_starts_with($content, 'data:')) {
            preg_match('/^data: (.*)$/m', $content, $matches);
            $content = $matches[1] ?? '';
        }
        $data = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }
}
