<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Ai\Credential;
use App\Entity\ProviderApiKey;
use App\Entity\User;
use App\Security\ApiKeyEncryptor;
use App\Security\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class ApiKeyControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->createQuery('DELETE FROM '.ProviderApiKey::class)->execute();
        $this->entityManager->createQuery('DELETE FROM '.User::class)->execute();
    }

    private function admin(): User
    {
        $admin = new User();
        $admin->setUsername('root');
        $admin->setRole(UserRole::Admin);
        $admin->setPassword('x');
        $this->entityManager->persist($admin);
        $this->entityManager->flush();

        return $admin;
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/admin/api-keys');

        self::assertResponseRedirects('http://localhost/login');
    }

    public function testSavedKeyIsStoredEncryptedAndShownMasked(): void
    {
        $this->client->loginUser($this->admin());
        $crawler = $this->client->request('GET', '/admin/api-keys');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter('form[name="key_openai"]')->form(['key_openai[apiKey]' => 'sk-test-abcdef123456']));
        self::assertResponseRedirects('/admin/api-keys');

        $this->entityManager->clear();
        $stored = $this->entityManager->getRepository(ProviderApiKey::class)->findOneBy(['credential' => Credential::OpenAi]);
        self::assertInstanceOf(ProviderApiKey::class, $stored);
        self::assertStringNotContainsString('abcdef', $stored->getEncryptedKey());
        self::assertSame('3456', $stored->getLastFour());
        self::assertSame('sk-test-abcdef123456', static::getContainer()->get(ApiKeyEncryptor::class)->decrypt($stored->getEncryptedKey()));

        $this->client->followRedirect();
        self::assertSelectorTextContains('body', '••••3456');
        self::assertStringNotContainsString('abcdef', (string) $this->client->getResponse()->getContent());
    }

    public function testDeleteRemovesKey(): void
    {
        $this->client->loginUser($this->admin());
        $encryptor = static::getContainer()->get(ApiKeyEncryptor::class);
        $this->entityManager->persist(new ProviderApiKey(Credential::Gemini, $encryptor->encrypt('gemini-key-0000'), '0000'));
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/admin/api-keys');
        $this->client->submit($crawler->filter('form[action$="/gemini/delete"]')->form());

        self::assertResponseRedirects('/admin/api-keys');
        $this->entityManager->clear();
        self::assertNull($this->entityManager->getRepository(ProviderApiKey::class)->findOneBy(['credential' => Credential::Gemini]));
    }

    public function testAKeyShorterThanSixteenCharactersIsRefused(): void
    {
        $this->client->loginUser($this->admin());
        $crawler = $this->client->request('GET', '/admin/api-keys');

        $this->client->submit($crawler->filter('form[name="key_openai"]')->form(['key_openai[apiKey]' => 'fifteen-char-15']));

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->entityManager->getRepository(ProviderApiKey::class)->findAll());
    }

    public function testPageShowsOneCardPerProviderWithClaudeCodeHoldingBothAnthropicCredentials(): void
    {
        $this->client->loginUser($this->admin());
        $crawler = $this->client->request('GET', '/admin/api-keys');

        self::assertResponseIsSuccessful();
        self::assertSame(['OpenAI', 'Google Gemini', 'DeepSeek', 'Claude Code'], $crawler->filter('.provider-card > h2')->each(static fn (Crawler $h): string => trim($h->text())));
        self::assertCount(2, $crawler->filter('.provider-card:last-child .credential'));
        self::assertSelectorTextContains('.provider-card:last-child', 'ANTHROPIC_API_KEY');
        self::assertSelectorTextContains('.provider-card:last-child', 'CLAUDE_CODE_OAUTH_TOKEN');
        self::assertSelectorTextContains('.provider-card:last-child', 'If both are set, the API key wins.');
        self::assertCount(5, $crawler->filter('dialog[id^="key-dialog-"] form'));
        self::assertSelectorNotExists('.provider-card:first-child .status-dot--final');
    }

    public function testEitherAnthropicCredentialMarksClaudeCodeAsConfigured(): void
    {
        $this->client->loginUser($this->admin());
        $encryptor = static::getContainer()->get(ApiKeyEncryptor::class);
        $this->entityManager->persist(new ProviderApiKey(Credential::ClaudeOAuth, $encryptor->encrypt('oauth-token-00000000'), '0000'));
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/admin/api-keys');

        self::assertSelectorExists('.provider-card:last-child .status-dot--final');
        self::assertSelectorTextContains('.provider-card:last-child', '••••0000');
        $credentials = $crawler->filter('.provider-card:last-child .credential');
        self::assertSame('Set key', trim($credentials->eq(0)->filter('.credential-actions button[command="show-modal"]')->text()));
        self::assertSame('Replace key', trim($credentials->eq(1)->filter('.credential-actions button[command="show-modal"]')->text()));

        $this->client->submit($crawler->filter('form[action$="/claude_oauth/delete"]')->form());
        self::assertResponseRedirects('/admin/api-keys');
        $this->entityManager->clear();
        self::assertCount(0, $this->entityManager->getRepository(ProviderApiKey::class)->findAll());
    }

    public function testSavingTheAnthropicApiKeyStoresItAsItsOwnCredential(): void
    {
        $this->client->loginUser($this->admin());
        $crawler = $this->client->request('GET', '/admin/api-keys');

        $this->client->submit($crawler->filter('form[name="key_anthropic"]')->form(['key_anthropic[apiKey]' => 'sk-ant-test-abcdef123456']));

        self::assertResponseRedirects('/admin/api-keys');
        $this->entityManager->clear();
        self::assertNotNull($this->entityManager->getRepository(ProviderApiKey::class)->findOneBy(['credential' => Credential::Anthropic]));
        self::assertNull($this->entityManager->getRepository(ProviderApiKey::class)->findOneBy(['credential' => Credential::ClaudeOAuth]));
    }

    public function testAnInvalidKeyReopensItsDialog(): void
    {
        $this->client->loginUser($this->admin());
        $crawler = $this->client->request('GET', '/admin/api-keys');
        self::assertStringNotContainsString('"showOnLoad":true', (string) $this->client->getResponse()->getContent());

        $this->client->submit($crawler->filter('form[name="key_claude_oauth"]')->form(['key_claude_oauth[apiKey]' => 'short']));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('"id":"key-dialog-claude_oauth","showOnLoad":true', (string) $this->client->getResponse()->getContent());
    }
}
