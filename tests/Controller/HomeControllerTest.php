<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Ai\Provider;
use App\Entity\ProviderApiKey;
use App\Entity\User;
use App\Security\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HomeControllerTest extends WebTestCase
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

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseRedirects('http://localhost/login');
    }

    public function testAdminSeesProviderStatusAndUserCount(): void
    {
        $this->entityManager->persist(new ProviderApiKey(Provider::OpenAi, 'ciphertext', '1234'));
        $this->entityManager->flush();
        $admin = new User();
        $admin->setUsername('root');
        $admin->setRole(UserRole::Admin);
        $admin->setPassword('x');
        $this->entityManager->persist($admin);
        $this->entityManager->flush();
        $this->client->loginUser($admin);

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('tbody tr:nth-child(1)', 'OpenAI API Key');
        self::assertSelectorTextNotContains('tbody tr:nth-child(1)', 'not set');
        self::assertSelectorTextContains('tbody tr:nth-child(2)', 'not set');
        self::assertSelectorTextContains('body', 'Existing users: 1');
        self::assertStringNotContainsString('1234', (string) $this->client->getResponse()->getContent());
    }

    public function testRegularUserSeesStatusWithoutUserCount(): void
    {
        $user = new User();
        $user->setUsername('frank');
        $user->setPassword('x');
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $this->client->loginUser($user);

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('table');
        self::assertSelectorTextNotContains('body', 'Existing users');
    }
}
