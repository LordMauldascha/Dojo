<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Ai\Credential;
use App\Ai\Provider;
use App\Entity\AiHelperSetting;
use App\Entity\JudgeSetting;
use App\Entity\ProviderApiKey;
use App\Entity\User;
use App\Security\ApiKeyEncryptor;
use App\Security\UserRole;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class LlmConfigurationControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->createQuery('DELETE FROM '.ProviderApiKey::class)->execute();
        $this->entityManager->createQuery('DELETE FROM '.JudgeSetting::class)->execute();
        $this->entityManager->createQuery('DELETE FROM '.AiHelperSetting::class)->execute();
        $this->entityManager->createQuery('DELETE FROM '.User::class)->execute();
    }

    private function storeKeys(Credential ...$credentials): void
    {
        $encryptor = static::getContainer()->get(ApiKeyEncryptor::class);
        foreach ($credentials as $credential) {
            $this->entityManager->persist(new ProviderApiKey($credential, $encryptor->encrypt('synthetic-key-0000'), '0000'));
        }
        $this->entityManager->flush();
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
        $this->client->request('GET', '/admin/llm-configuration');

        self::assertResponseRedirects('http://localhost/login');
    }

    public function testNonAdminIsForbidden(): void
    {
        $user = new User();
        $user->setUsername('member');
        $user->setRole(UserRole::User);
        $user->setPassword('x');
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $this->client->loginUser($user);

        $this->client->request('GET', '/admin/llm-configuration');

        self::assertResponseStatusCodeSame(403);
    }

    public function testJudgeIsNotSetByDefault(): void
    {
        $this->client->loginUser($this->admin());
        $this->client->request('GET', '/admin/llm-configuration');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'LLM Configuration');
        self::assertSelectorTextContains('body', 'Not set.');
    }

    public function testSavingTheJudgeStoresProviderAndModelAndPreselectsIt(): void
    {
        $this->storeKeys(Credential::Gemini);
        $this->client->loginUser($this->admin());
        $crawler = $this->client->request('GET', '/admin/llm-configuration');

        $this->client->submit($crawler->filter('form[name="judge"]')->form(['judge[model]' => 'gemini:gemini-3.5-flash']));
        self::assertResponseRedirects('/admin/llm-configuration');

        $this->entityManager->clear();
        $stored = $this->entityManager->getRepository(JudgeSetting::class)->findAll();
        self::assertCount(1, $stored);
        self::assertSame(Provider::Gemini, $stored[0]->getProvider());
        self::assertSame('gemini-3.5-flash', $stored[0]->getModel());

        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Current: Google Gemini / gemini-3.5-flash.');
        self::assertSame('gemini:gemini-3.5-flash', $crawler->filter('form[name="judge"]')->form()->getValues()['judge[model]']);
    }

    public function testSavingTheAiHelperStoresItApartFromTheJudge(): void
    {
        $this->storeKeys(Credential::Gemini);
        $this->client->loginUser($this->admin());
        $crawler = $this->client->request('GET', '/admin/llm-configuration');

        $this->client->submit($crawler->filter('form[name="helper"]')->form(['helper[model]' => 'gemini:gemini-3.5-flash']));
        self::assertResponseRedirects('/admin/llm-configuration');

        $this->entityManager->clear();
        $stored = $this->entityManager->getRepository(AiHelperSetting::class)->findAll();
        self::assertCount(1, $stored);
        self::assertSame('gemini-3.5-flash', $stored[0]->getModel());
        self::assertCount(0, $this->entityManager->getRepository(JudgeSetting::class)->findAll());

        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'AI helper saved.');
        self::assertSame('gemini:gemini-3.5-flash', $crawler->filter('form[name="helper"]')->form()->getValues()['helper[model]']);
        self::assertSame('', $crawler->filter('form[name="judge"]')->form()->getValues()['judge[model]']);
    }

    public function testAnUnknownAiHelperModelIsRejected(): void
    {
        $this->storeKeys(Credential::Gemini);
        $this->client->loginUser($this->admin());
        $crawler = $this->client->request('GET', '/admin/llm-configuration');
        $form = $crawler->filter('form[name="helper"]')->form();
        /** @var array<string, array<string, string>> $values */
        $values = $form->getPhpValues();
        $values['helper']['model'] = 'openai:gpt-5.5';
        $this->client->request('POST', '/admin/llm-configuration', $values);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->entityManager->getRepository(AiHelperSetting::class)->findAll());
    }

    public function testSavingTheJudgeAgainUpdatesTheSameRow(): void
    {
        $this->storeKeys(Credential::OpenAi, Credential::ClaudeOAuth);
        $this->client->loginUser($this->admin());
        foreach (['openai:gpt-5.5', 'claude_code:sonnet'] as $choice) {
            $crawler = $this->client->request('GET', '/admin/llm-configuration');
            $this->client->submit($crawler->filter('form[name="judge"]')->form(['judge[model]' => $choice]));
            self::assertResponseRedirects('/admin/llm-configuration');
        }

        $this->entityManager->clear();
        $stored = $this->entityManager->getRepository(JudgeSetting::class)->findAll();
        self::assertCount(1, $stored);
        self::assertSame(Provider::ClaudeCode, $stored[0]->getProvider());
        self::assertSame('sonnet', $stored[0]->getModel());
    }

    public function testAConcurrentFirstSaveOfTheJudgeShowsTheConflictMessage(): void
    {
        $this->storeKeys(Credential::Gemini);
        $this->client->loginUser($this->admin());
        $crawler = $this->client->request('GET', '/admin/llm-configuration');
        $form = $crawler->filter('form[name="judge"]')->form(['judge[model]' => 'gemini:gemini-3.5-flash']);

        $connection = $this->entityManager->getConnection();
        $this->client->disableReboot();
        $this->entityManager->getEventManager()->addEventListener(Events::prePersist, new class($connection) {
            public function __construct(private readonly Connection $connection)
            {
            }

            public function prePersist(PrePersistEventArgs $args): void
            {
                if ($args->getObject() instanceof JudgeSetting) {
                    $this->connection->executeStatement("INSERT INTO judge_setting (id, provider, model, updated_at) VALUES (1, 'openai', 'gpt-5.5', NOW())");
                }
            }
        });
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/llm-configuration');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Reload the page and try again');
        self::assertSame(['gpt-5.5'], $connection->fetchFirstColumn('SELECT model FROM judge_setting'));
    }

    public function testJudgeSelectOffersOnlyModelsOfProvidersWithKey(): void
    {
        $this->storeKeys(Credential::DeepSeek);
        $this->client->loginUser($this->admin());

        $crawler = $this->client->request('GET', '/admin/llm-configuration');

        self::assertSame(['DeepSeek'], $crawler->filter('form[name="judge"] optgroup')->each(static fn (Crawler $group): string => (string) $group->attr('label')));
    }

    public function testAnUnknownJudgeModelIsRefusedAndNothingIsStored(): void
    {
        $this->storeKeys(Credential::OpenAi);
        $this->client->loginUser($this->admin());
        $crawler = $this->client->request('GET', '/admin/llm-configuration');
        $form = $crawler->filter('form[name="judge"]')->form();
        $form->disableValidation();

        $this->client->submit($form, ['judge[model]' => 'openai:no-such-model']);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->entityManager->getRepository(JudgeSetting::class)->findAll());
    }

    public function testTheOldApiKeysUrlIsGone(): void
    {
        $this->client->loginUser($this->admin());
        $this->client->request('GET', '/admin/api-keys');

        self::assertResponseStatusCodeSame(404);
    }

    public function testSavedKeyIsStoredEncryptedAndShownMasked(): void
    {
        $this->client->loginUser($this->admin());
        $crawler = $this->client->request('GET', '/admin/llm-configuration');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter('form[name="key_openai"]')->form(['key_openai[apiKey]' => 'sk-test-abcdef123456']));
        self::assertResponseRedirects('/admin/llm-configuration');

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

        $crawler = $this->client->request('GET', '/admin/llm-configuration');
        $this->client->submit($crawler->filter('form[action$="/gemini/delete"]')->form());

        self::assertResponseRedirects('/admin/llm-configuration');
        $this->entityManager->clear();
        self::assertNull($this->entityManager->getRepository(ProviderApiKey::class)->findOneBy(['credential' => Credential::Gemini]));
    }

    public function testAKeyShorterThanSixteenCharactersIsRefused(): void
    {
        $this->client->loginUser($this->admin());
        $crawler = $this->client->request('GET', '/admin/llm-configuration');

        $this->client->submit($crawler->filter('form[name="key_openai"]')->form(['key_openai[apiKey]' => 'fifteen-char-15']));

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->entityManager->getRepository(ProviderApiKey::class)->findAll());
    }

    public function testPageShowsOneCardPerProviderWithClaudeCodeHoldingBothAnthropicCredentials(): void
    {
        $this->client->loginUser($this->admin());
        $crawler = $this->client->request('GET', '/admin/llm-configuration');

        self::assertResponseIsSuccessful();
        self::assertSame(['OpenAI', 'Google Gemini', 'DeepSeek', 'Claude Code', 'Hugging Face'], $crawler->filter('.provider-card > h2')->each(static fn (Crawler $h): string => trim($h->text())));
        self::assertCount(2, $crawler->filter('.provider-card:nth-child(4) .credential'));
        self::assertSelectorTextContains('.provider-card:nth-child(4)', 'ANTHROPIC_API_KEY');
        self::assertSelectorTextContains('.provider-card:nth-child(4)', 'CLAUDE_CODE_OAUTH_TOKEN');
        self::assertSelectorTextContains('.provider-card:nth-child(4)', 'If both are set, the API key wins.');
        self::assertCount(6, $crawler->filter('dialog[id^="key-dialog-"] form'));
        self::assertSelectorNotExists('.provider-card:first-child .status-dot--final');
    }

    public function testEitherAnthropicCredentialMarksClaudeCodeAsConfigured(): void
    {
        $this->client->loginUser($this->admin());
        $encryptor = static::getContainer()->get(ApiKeyEncryptor::class);
        $this->entityManager->persist(new ProviderApiKey(Credential::ClaudeOAuth, $encryptor->encrypt('oauth-token-00000000'), '0000'));
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/admin/llm-configuration');

        self::assertSelectorExists('.provider-card:nth-child(4) .status-dot--final');
        self::assertSelectorTextContains('.provider-card:nth-child(4)', '••••0000');
        $credentials = $crawler->filter('.provider-card:nth-child(4) .credential');
        self::assertSame('Set key', trim($credentials->eq(0)->filter('.credential-actions button[command="show-modal"]')->text()));
        self::assertSame('Replace key', trim($credentials->eq(1)->filter('.credential-actions button[command="show-modal"]')->text()));

        $this->client->submit($crawler->filter('form[action$="/claude_oauth/delete"]')->form());
        self::assertResponseRedirects('/admin/llm-configuration');
        $this->entityManager->clear();
        self::assertCount(0, $this->entityManager->getRepository(ProviderApiKey::class)->findAll());
    }

    public function testSavingTheAnthropicApiKeyStoresItAsItsOwnCredential(): void
    {
        $this->client->loginUser($this->admin());
        $crawler = $this->client->request('GET', '/admin/llm-configuration');

        $this->client->submit($crawler->filter('form[name="key_anthropic"]')->form(['key_anthropic[apiKey]' => 'sk-ant-test-abcdef123456']));

        self::assertResponseRedirects('/admin/llm-configuration');
        $this->entityManager->clear();
        self::assertNotNull($this->entityManager->getRepository(ProviderApiKey::class)->findOneBy(['credential' => Credential::Anthropic]));
        self::assertNull($this->entityManager->getRepository(ProviderApiKey::class)->findOneBy(['credential' => Credential::ClaudeOAuth]));
    }

    public function testAnInvalidKeyReopensItsDialog(): void
    {
        $this->client->loginUser($this->admin());
        $crawler = $this->client->request('GET', '/admin/llm-configuration');
        self::assertStringNotContainsString('"showOnLoad":true', (string) $this->client->getResponse()->getContent());

        $this->client->submit($crawler->filter('form[name="key_claude_oauth"]')->form(['key_claude_oauth[apiKey]' => 'short']));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('"id":"key-dialog-claude_oauth","showOnLoad":true', (string) $this->client->getResponse()->getContent());
    }
}
