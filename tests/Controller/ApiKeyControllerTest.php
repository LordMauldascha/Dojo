<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Ai\Provider;
use App\Entity\ProviderApiKey;
use App\Security\ApiKeyEncryptor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class ApiKeyControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->createQuery('DELETE FROM '.ProviderApiKey::class)->execute();
    }

    private function admin(): InMemoryUser
    {
        $hash = $_SERVER['ADMIN_PASSWORD_HASH'];
        self::assertIsString($hash);

        return new InMemoryUser('admin', $hash, ['ROLE_ADMIN']);
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
        $stored = $this->entityManager->getRepository(ProviderApiKey::class)->findOneBy(['provider' => Provider::OpenAi]);
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
        $this->entityManager->persist(new ProviderApiKey(Provider::Gemini, $encryptor->encrypt('gemini-key-0000'), '0000'));
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/admin/api-keys');
        $this->client->submit($crawler->filter('form[action$="/gemini/delete"]')->form());

        self::assertResponseRedirects('/admin/api-keys');
        $this->entityManager->clear();
        self::assertNull($this->entityManager->getRepository(ProviderApiKey::class)->findOneBy(['provider' => Provider::Gemini]));
    }
}
