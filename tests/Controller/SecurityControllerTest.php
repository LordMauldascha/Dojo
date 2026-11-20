<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Security\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SecurityControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->createQuery('DELETE FROM '.User::class)->execute();

        $admin = new User();
        $admin->setUsername('root');
        $admin->setRole(UserRole::Admin);
        $admin->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($admin, 'test-only-password'));
        $entityManager->persist($admin);
        $entityManager->flush();
    }

    public function testAdminCanLogIn(): void
    {
        $crawler = $this->client->request('GET', '/login');

        $this->client->submit($crawler->selectButton('Sign in')->form(['_username' => 'root', '_password' => 'test-only-password']));

        self::assertResponseRedirects('/');
    }

    public function testWrongPasswordIsRejected(): void
    {
        $crawler = $this->client->request('GET', '/login');

        $this->client->submit($crawler->selectButton('Sign in')->form(['_username' => 'root', '_password' => 'wrong']));

        self::assertResponseRedirects('/login');
    }

    public function testFormerInMemoryAdminIsGone(): void
    {
        $crawler = $this->client->request('GET', '/login');

        $this->client->submit($crawler->selectButton('Sign in')->form(['_username' => 'admin', '_password' => 'test-only']));

        self::assertResponseRedirects('/login');
    }

    public function testTheLoginIsPublicAndTheHomePageIsNot(): void
    {
        $this->client->request('GET', '/login');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/');
        self::assertResponseRedirects('http://localhost/login');
    }

    public function testResponsesCarryTheSecurityHeaders(): void
    {
        $this->client->request('GET', '/login');

        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        self::assertResponseHeaderSame('Referrer-Policy', 'same-origin');
        self::assertStringContainsString("frame-ancestors 'self'", (string) $this->client->getResponse()->headers->get('Content-Security-Policy'));
    }

    public function testLogoutNeedsTheTokenOfTheLogoutLink(): void
    {
        $root = static::getContainer()->get(EntityManagerInterface::class)->getRepository(User::class)->findOneBy(['username' => 'root']);
        self::assertInstanceOf(User::class, $root);
        $this->client->loginUser($root);
        $href = (string) $this->client->request('GET', '/')->selectLink('Logout')->attr('href');
        self::assertStringContainsString('_csrf_token=', $href);

        $this->client->request('GET', '/logout');
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', $href, server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertResponseRedirects();
        $this->client->request('GET', '/');
        self::assertResponseRedirects('http://localhost/login');
    }
}
