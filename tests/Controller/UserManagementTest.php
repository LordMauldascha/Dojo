<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Security\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

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
        $client->submit($crawler->selectButton('Sign in')->form(['_username' => 'alice', '_password' => self::PASSWORD]));
        self::assertResponseRedirects('/');
    }

    public function testInvalidUsersAreRejected(): void
    {
        $this->client->loginUser($this->admin());

        $this->createUser('Bad Name', self::PASSWORD);
        self::assertResponseStatusCodeSame(422);
        $this->createUser('shortpw', 'too-short');
        self::assertResponseStatusCodeSame(422);

        $this->createUser('bob', self::PASSWORD);
        $this->createUser('bob', self::PASSWORD);
        self::assertResponseStatusCodeSame(422);
        self::assertCount(2, $this->entityManager->getRepository(User::class)->findAll());
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
        $this->client->submit($crawler->selectButton('Change password')->form([
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
        $this->client->submit($crawler->selectButton('Change password')->form([
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

    public function testAdminCanOpenPasswordChange(): void
    {
        $this->client->loginUser($this->admin());

        $this->client->request('GET', '/account/password');

        self::assertResponseIsSuccessful();
    }

    public function testAdminCanCreateAdminWhoGetsAdminAccess(): void
    {
        $this->client->loginUser($this->admin());
        $this->createUser('boss', self::PASSWORD, 'ROLE_ADMIN');
        self::assertResponseRedirects('/admin/users');

        $this->entityManager->clear();
        $boss = $this->entityManager->getRepository(User::class)->findOneBy(['username' => 'boss']);
        self::assertInstanceOf(User::class, $boss);
        self::assertSame(UserRole::Admin, $boss->getRole());

        $this->client->loginUser($boss);
        $this->client->request('GET', '/admin/api-keys');
        self::assertResponseIsSuccessful();
    }

    public function testDeactivatedUserCannotLogIn(): void
    {
        $this->persistUser('gina', enabled: false);

        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Sign in')->form(['_username' => 'gina', '_password' => self::PASSWORD]));

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'This account is disabled.');
    }

    public function testDeactivationEndsExistingSession(): void
    {
        $user = $this->persistUser('hank');
        $this->client->loginUser($user);
        $this->client->request('GET', '/account');
        self::assertResponseIsSuccessful();

        $user->setEnabled(false);
        $this->entityManager->flush();

        $this->client->request('GET', '/account');
        self::assertResponseRedirects('http://localhost/login');
    }

    public function testAdminDeactivatesReactivatesAndDeletesUser(): void
    {
        $this->persistUser('ivy');
        $this->client->loginUser($this->admin());

        $crawler = $this->client->request('GET', '/admin/users');
        $this->client->submit($crawler->filter('form[action$="/toggle"]')->form());
        self::assertResponseRedirects('/admin/users');
        $this->entityManager->clear();
        self::assertFalse($this->findUser('ivy')->isEnabled());

        $crawler = $this->client->request('GET', '/admin/users');
        self::assertSelectorTextContains('body', 'disabled');
        $this->client->submit($crawler->filter('form[action$="/toggle"]')->form());
        $this->entityManager->clear();
        self::assertTrue($this->findUser('ivy')->isEnabled());

        $crawler = $this->client->request('GET', '/admin/users');
        $this->client->submit($crawler->filter('form[action$="/delete"]')->form());
        self::assertResponseRedirects('/admin/users');
        $this->entityManager->clear();
        self::assertNull($this->entityManager->getRepository(User::class)->findOneBy(['username' => 'ivy']));
    }

    public function testAdminCannotDeactivateOrDeleteOwnAccount(): void
    {
        $boss = $this->persistUser('jules', UserRole::Admin);
        $this->client->loginUser($boss);

        foreach (['toggle', 'delete'] as $action) {
            $this->client->request('POST', \sprintf('/admin/users/%d/%s', $boss->getId(), $action));
            self::assertResponseRedirects('/admin/users');
        }

        $this->entityManager->clear();
        self::assertTrue($this->findUser('jules')->isEnabled());
    }

    public function testUserActionsRequireValidCsrfToken(): void
    {
        $target = $this->persistUser('kim');
        $this->client->loginUser($this->admin());

        $this->client->request('POST', \sprintf('/admin/users/%d/delete', $target->getId()), ['_token' => 'invalid']);

        self::assertResponseStatusCodeSame(403);
    }

    private function findUser(string $username): User
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['username' => $username]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function admin(): User
    {
        return $this->persistUser('root', UserRole::Admin);
    }

    private function persistUser(string $username, UserRole $role = UserRole::User, bool $enabled = true): User
    {
        $user = new User();
        $user->setUsername($username);
        $user->setRole($role);
        $user->setEnabled($enabled);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function createUser(string $username, string $password, string $role = 'ROLE_USER'): void
    {
        $crawler = $this->client->request('GET', '/admin/users');
        $this->client->submit($crawler->selectButton('Create user')->form([
            'user_create[username]' => $username,
            'user_create[plainPassword][first]' => $password,
            'user_create[plainPassword][second]' => $password,
            'user_create[role]' => $role,
        ]));
    }
}
