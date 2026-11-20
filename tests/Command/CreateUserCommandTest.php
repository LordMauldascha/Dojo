<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\User;
use App\Security\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class CreateUserCommandTest extends KernelTestCase
{
    private const PASSWORD = 'correct-horse-battery';

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->createQuery('DELETE FROM '.User::class)->execute();
    }

    public function testCreatesAdminWithHashedPassword(): void
    {
        $tester = $this->createViaCommand(['username' => 'root', '--admin' => true], [self::PASSWORD, self::PASSWORD]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringNotContainsString(self::PASSWORD, $tester->getDisplay());
        $stored = $this->findUser('root');
        self::assertInstanceOf(User::class, $stored);
        self::assertSame(UserRole::Admin, $stored->getRole());
        self::assertNotSame(self::PASSWORD, $stored->getPassword());
        self::assertTrue(password_verify(self::PASSWORD, $stored->getPassword()));
    }

    public function testDefaultRoleIsUser(): void
    {
        $tester = $this->createViaCommand(['username' => 'alice'], [self::PASSWORD, self::PASSWORD]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(UserRole::User, $this->findUser('alice')?->getRole());
    }

    public function testRejectsDuplicateUsername(): void
    {
        $this->createViaCommand(['username' => 'bob'], [self::PASSWORD, self::PASSWORD]);

        $tester = $this->createViaCommand(['username' => 'bob'], [self::PASSWORD, self::PASSWORD]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertCount(1, $this->entityManager->getRepository(User::class)->findAll());
    }

    public function testRejectsInvalidUsername(): void
    {
        $tester = $this->createViaCommand(['username' => 'Bad Name'], [self::PASSWORD, self::PASSWORD]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertCount(0, $this->entityManager->getRepository(User::class)->findAll());
    }

    public function testRejectsPasswordShorterThanTwelveCharacters(): void
    {
        $tester = $this->createViaCommand(['username' => 'carol', '--admin' => true], ['short-pw-11', 'short-pw-11']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('too short', $tester->getDisplay());
        self::assertNull($this->findUser('carol'));
    }

    public function testRejectsMismatchingRepeat(): void
    {
        $tester = $this->createViaCommand(['username' => 'dave'], [self::PASSWORD, self::PASSWORD.'x']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertNull($this->findUser('dave'));
    }

    public function testFailsWithoutInteraction(): void
    {
        $tester = new CommandTester(new Application(self::bootKernel())->find('app:user:create'));
        $tester->execute(['username' => 'erin'], ['interactive' => false]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertNull($this->findUser('erin'));
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string>         $answers
     */
    private function createViaCommand(array $input, array $answers): CommandTester
    {
        $tester = new CommandTester(new Application(self::bootKernel())->find('app:user:create'));
        $tester->setInputs($answers);
        $tester->execute($input);

        return $tester;
    }

    private function findUser(string $username): ?User
    {
        $this->entityManager->clear();
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['username' => $username]);

        return $user instanceof User ? $user : null;
    }
}
