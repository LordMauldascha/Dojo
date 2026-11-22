<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\Support\McpWebTestCase;

final class ConnectedAgentsTest extends McpWebTestCase
{
    public function testAccountPageListsTheAgentAndDisconnectingCutsItsToken(): void
    {
        $user = $this->persistUser('agent-user');
        $token = $this->connect($user);

        $crawler = $this->client->request('GET', '/account');
        self::assertSelectorTextContains('main', 'Test agent');

        $this->client->submit($crawler->filter('dialog form[action*="/account/agents/"]')->form());
        self::assertResponseRedirects('/account');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash', 'Agent disconnected.');
        self::assertSelectorTextNotContains('main', 'Test agent');

        $this->client->request('POST', '/mcp', [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testDisconnectingOnlyAffectsTheOwnTokensOfASharedClient(): void
    {
        $first = $this->persistUser('first-user');
        $second = $this->persistUser('second-user');
        $clientId = $this->registerClient();
        $firstToken = $this->connect($first, $clientId);
        $secondToken = $this->connect($second, $clientId);

        $this->client->loginUser($second);
        $crawler = $this->client->request('GET', '/account');
        $this->client->submit($crawler->filter('dialog form[action*="/account/agents/"]')->form());

        self::assertFalse($this->callTool($firstToken, 'list_projects')['isError']);
        $this->client->request('POST', '/mcp', [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$secondToken]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testPageWithoutAgentsShowsTheConnectCommand(): void
    {
        $this->client->loginUser($this->persistUser('agent-user'));

        $this->client->request('GET', '/account');

        self::assertSelectorTextContains('main', 'claude mcp add --transport http dojo http://localhost/mcp');
    }
}
