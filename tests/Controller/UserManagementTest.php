<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class UserManagementTest extends WebTestCase
{
    private const PASSWORD = 'correct-horse-battery';

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->createQuery('DELETE FROM '.User::class)->execute();
    }

    public function testAdminCreatesUserWhoCanLogInAndIsHashed(): void
    {
        $this->client->loginUser($this->admin());
        $this->createUser('alice', self::PASSWORD);
        self::assertResponseRedirects('/admin/users');

        $this->entityManager->clear();
        $stored = $this->entityManager->getRepository(User::class)->findOneBy(['username' => 'alice']);
        self::assertInstanceOf(User::class, $stored);
        self::assertStringNotContainsString(self::PASSWORD, $stored->getPassword());
        self::assertTrue(password_verify(self::PASSWORD, $stored->getPassword()));

        static::ensureKernelShutdown();
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');
        $client->submit($crawler->selectButton('Anmelden')->form(['_username' => 'alice', '_password' => self::PASSWORD]));
        self::assertResponseRedirects('/account');
    }

    public function testInvalidUsersAreRejected(): void
    {
        $this->client->loginUser($this->admin());

        $this->createUser('admin', self::PASSWORD);
        self::assertResponseStatusCodeSame(422);
        $this->createUser('Bad Name', self::PASSWORD);
        self::assertResponseStatusCodeSame(422);
        $this->createUser('shortpw', 'too-short');
        self::assertResponseStatusCodeSame(422);

        $this->createUser('bob', self::PASSWORD);
        $this->createUser('bob', self::PASSWORD);
        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $this->entityManager->getRepository(User::class)->findAll());
    }

    public function testRegularUserHasNoAccessToAdminAreas(): void
    {
        $this->client->loginUser($this->persistUser('carol'));

        foreach (['/admin/api-keys', '/admin/users'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseStatusCodeSame(403);
        }

        $this->client->request('POST', '/admin/api-keys/openai/delete');
        self::assertResponseStatusCodeSame(403);
    }

    public function testUserChangesOwnPassword(): void
    {
        $user = $this->persistUser('dave');
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/account/password');
        $this->client->submit($crawler->selectButton('Passwort ändern')->form([
            'change_password[currentPassword]' => self::PASSWORD,
            'change_password[newPassword][first]' => 'another-long-password',
            'change_password[newPassword][second]' => 'another-long-password',
        ]));

        self::assertResponseRedirects('/account');
        $this->entityManager->clear();
        $stored = $this->entityManager->getRepository(User::class)->findOneBy(['username' => 'dave']);
        self::assertInstanceOf(User::class, $stored);
        self::assertTrue(password_verify('another-long-password', $stored->getPassword()));
    }

    public function testWrongCurrentPasswordIsRejected(): void
    {
        $this->client->loginUser($this->persistUser('erin'));

        $crawler = $this->client->request('GET', '/account/password');
        $this->client->submit($crawler->selectButton('Passwort ändern')->form([
            'change_password[currentPassword]' => 'not-my-password',
            'change_password[newPassword][first]' => 'another-long-password',
            'change_password[newPassword][second]' => 'another-long-password',
        ]));

        self::assertResponseStatusCodeSame(422);
        $this->entityManager->clear();
        $stored = $this->entityManager->getRepository(User::class)->findOneBy(['username' => 'erin']);
        self::assertInstanceOf(User::class, $stored);
        self::assertTrue(password_verify(self::PASSWORD, $stored->getPassword()));
    }

    public function testAdminCannotUsePasswordChange(): void
    {
        $this->client->loginUser($this->admin());

        $this->client->request('GET', '/account/password');

        self::assertResponseStatusCodeSame(403);
    }

    private function admin(): InMemoryUser
    {
        $hash = $_SERVER['ADMIN_PASSWORD_HASH'];
        self::assertIsString($hash);

        return new InMemoryUser('admin', $hash, ['ROLE_ADMIN']);
    }

    private function persistUser(string $username): User
    {
        $user = new User();
        $user->setUsername($username);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function createUser(string $username, string $password): void
    {
        $crawler = $this->client->request('GET', '/admin/users');
        $this->client->submit($crawler->selectButton('Benutzer anlegen')->form([
            'user_create[username]' => $username,
            'user_create[plainPassword][first]' => $password,
            'user_create[plainPassword][second]' => $password,
        ]));
    }
}
