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

        $this->client->submit($crawler->selectButton('Anmelden')->form(['_username' => 'root', '_password' => 'test-only-password']));

        self::assertResponseRedirects('/');
    }

    public function testWrongPasswordIsRejected(): void
    {
        $crawler = $this->client->request('GET', '/login');

        $this->client->submit($crawler->selectButton('Anmelden')->form(['_username' => 'root', '_password' => 'wrong']));

        self::assertResponseRedirects('/login');
    }

    public function testFormerInMemoryAdminIsGone(): void
    {
        $crawler = $this->client->request('GET', '/login');

        $this->client->submit($crawler->selectButton('Anmelden')->form(['_username' => 'admin', '_password' => 'test-only']));

        self::assertResponseRedirects('/login');
    }
}
