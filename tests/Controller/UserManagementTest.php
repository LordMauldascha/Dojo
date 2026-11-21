<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Security\UserRole;
use App\Tests\Support\AppWebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class UserManagementTest extends AppWebTestCase
{
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

        foreach (['/admin/llm-configuration', '/admin/users'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseStatusCodeSame(403);
        }

        $this->client->request('POST', '/admin/llm-configuration/keys/openai/delete');
        self::assertResponseStatusCodeSame(403);
    }

    public function testUserChangesOwnPassword(): void
    {
        $user = $this->persistUser('dave');
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/account');
        $this->client->submit($crawler->filter('dialog')->selectButton('Change password')->form([
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

        $crawler = $this->client->request('GET', '/account');
        $this->client->submit($crawler->filter('dialog')->selectButton('Change password')->form([
            'change_password[currentPassword]' => 'not-my-password',
            'change_password[newPassword][first]' => 'another-long-password',
            'change_password[newPassword][second]' => 'another-long-password',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('"showOnLoad":true', (string) $this->client->getResponse()->getContent());
        self::assertSelectorTextContains('dialog#password-dialog', 'The current password is wrong.');
        $this->entityManager->clear();
        $stored = $this->entityManager->getRepository(User::class)->findOneBy(['username' => 'erin']);
        self::assertInstanceOf(User::class, $stored);
        self::assertTrue(password_verify(self::PASSWORD, $stored->getPassword()));
    }

    public function testAdminCanOpenPasswordChange(): void
    {
        $this->client->loginUser($this->admin());

        $this->client->request('GET', '/account');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('dialog#password-dialog form[name="change_password"]');
        self::assertStringContainsString('"showOnLoad":false', (string) $this->client->getResponse()->getContent());
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
        $this->client->request('GET', '/admin/llm-configuration');
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
            self::assertResponseStatusCodeSame(403);
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

    public function testADisabledAccountIsOnlyRevealedAfterTheCorrectPassword(): void
    {
        $this->persistUser('gina', enabled: false);

        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Sign in')->form(['_username' => 'gina', '_password' => 'a-wrong-password']));
        $this->client->followRedirect();

        self::assertSelectorTextContains('body', 'Invalid credentials.');
        self::assertSelectorTextNotContains('body', 'disabled');
    }

    public function testAnOversizedUserIdIsNotFound(): void
    {
        $this->client->loginUser($this->admin());

        $this->client->request('POST', '/admin/users/99999999999/toggle');

        self::assertResponseStatusCodeSame(404);
    }

    private function findUser(string $username): User
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['username' => $username]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    public function testUserListIsSortedSearchedAndPaginated(): void
    {
        $this->client->loginUser($this->admin());
        $this->persistUser('alice');
        $this->persistUser('bob', UserRole::Admin);
        $this->persistUser('carol', enabled: false);

        self::assertSame(['alice', 'bob', 'carol', 'root'], $this->usernames($this->client->request('GET', '/admin/users')));
        self::assertSame(['root', 'carol', 'bob', 'alice'], $this->usernames($this->client->request('GET', '/admin/users', ['dir' => 'desc'])));
        self::assertSame(['root', 'bob', 'alice', 'carol'], $this->usernames($this->client->request('GET', '/admin/users', ['sort' => 'role'])));
        self::assertSame(['root', 'alice', 'bob', 'carol'], $this->usernames($this->client->request('GET', '/admin/users', ['sort' => 'status', 'dir' => 'desc'])));

        self::assertSame(['alice'], $this->usernames($this->client->request('GET', '/admin/users', ['q' => 'ALI'])));
        self::assertSame([], $this->usernames($this->client->request('GET', '/admin/users', ['q' => '%'])));
        self::assertSelectorTextContains('main', 'No users found.');

        self::assertSelectorExists('.toolbar button[command="show-modal"][commandfor="user-dialog"]');
        self::assertSelectorTextContains('.toolbar', 'New user');
    }

    public function testUserListPaginates(): void
    {
        $this->client->loginUser($this->admin());
        for ($i = 1; $i <= 11; ++$i) {
            $this->persistUser(\sprintf('user%02d', $i));
        }

        self::assertCount(10, $this->usernames($this->client->request('GET', '/admin/users')));
        self::assertSelectorTextContains('nav[aria-label="Pagination"]', 'Page 1 of 2 (12 entries)');
        self::assertSame(['user10', 'user11'], $this->usernames($this->client->request('GET', '/admin/users', ['page' => 2])));
        self::assertCount(12, $this->usernames($this->client->request('GET', '/admin/users', ['perPage' => 20])));
    }

    public function testInvalidListQueryIsRejected(): void
    {
        $this->client->loginUser($this->admin());

        $this->client->request('GET', '/admin/users', ['perPage' => 7]);
        self::assertResponseStatusCodeSame(422);
        $this->client->request('GET', '/admin/users', ['dir' => 'sideways']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testInvalidNewUserReopensTheDialogAndKeepsTheInput(): void
    {
        $this->client->loginUser($this->admin());

        $crawler = $this->client->request('GET', '/admin/users');
        self::assertStringContainsString('"showOnLoad":false', (string) $this->client->getResponse()->getContent());
        self::assertSelectorExists('dialog#user-dialog form[name="user_create"]');

        $this->client->submit($crawler->filter('dialog')->selectButton('Create user')->form([
            'user_create[username]' => 'Bad Name',
            'user_create[plainPassword][first]' => self::PASSWORD,
            'user_create[plainPassword][second]' => self::PASSWORD,
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('"showOnLoad":true', (string) $this->client->getResponse()->getContent());
        self::assertSelectorTextContains('dialog#user-dialog', 'Allowed are 3 to 64 characters');
        self::assertSame('Bad Name', $this->client->getCrawler()->filter('dialog#user-dialog input[name="user_create[username]"]')->attr('value'));
    }

    private function admin(): User
    {
        return $this->persistUser('root', UserRole::Admin);
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

    /** @return list<string> */
    private function usernames(Crawler $crawler): array
    {
        return $crawler->filter('tbody tr td:nth-child(1)')->each(static fn (Crawler $cell): string => trim($cell->text()));
    }
}
