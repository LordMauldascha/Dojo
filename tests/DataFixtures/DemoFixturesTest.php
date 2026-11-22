<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures;

use App\Ai\Credential;
use App\DataFixtures\DemoFixtures;
use App\Entity\Group;
use App\Entity\Project;
use App\Entity\ProviderApiKey;
use App\Entity\Run;
use App\Entity\Skill;
use App\Entity\Testcase;
use App\Entity\User;
use App\Security\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class DemoFixturesTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->createQuery('DELETE FROM '.ProviderApiKey::class)->execute();
    }

    protected function tearDown(): void
    {
        $this->entityManager->createQuery('DELETE FROM '.ProviderApiKey::class)->execute();
        parent::tearDown();
    }

    public function testCreatesTheDemoState(): void
    {
        $this->load();

        self::assertSame(3, $this->countRows(Group::class));
        self::assertSame(6, $this->countRows(Project::class));
        self::assertSame(12, $this->countRows(Skill::class));
        self::assertSame(15, $this->countRows(Testcase::class));
        self::assertSame(8, $this->countRows(Run::class));
    }

    public function testCreatesAdminAndUserWithTheirPasswords(): void
    {
        $this->load();

        $admin = $this->findUser('admin');
        $user = $this->findUser('user');
        self::assertSame(UserRole::Admin, $admin->getRole());
        self::assertSame(UserRole::User, $user->getRole());
        self::assertTrue(password_verify('admin', $admin->getPassword()));
        self::assertTrue(password_verify('user', $user->getPassword()));
    }

    public function testUserIsInAllGroupsAndAdminInNone(): void
    {
        $this->load();

        $titles = [];
        foreach ($this->entityManager->getRepository(Group::class)->findAll() as $group) {
            $names = $group->getUsers()->map(static fn (User $member): string => $member->getUsername())->toArray();
            self::assertSame(['user'], $names, $group->getTitle());
            $titles[] = $group->getTitle();
        }
        sort($titles);
        self::assertSame(['Copywriting', 'Development', 'Project Management'], $titles);
    }

    public function testEveryProjectBelongsToExactlyOneGroup(): void
    {
        $this->load();

        foreach ($this->entityManager->getRepository(Project::class)->findAll() as $project) {
            self::assertCount(1, $project->getGroups(), $project->getTitle());
        }
    }

    public function testRunsHaveSkillsAndTestcasesButNoLlmConfigurationOrVersion(): void
    {
        $this->load();

        foreach ($this->entityManager->getRepository(Run::class)->findAll() as $run) {
            self::assertSame([], $run->getConfigurations(), $run->getTitle());
            self::assertCount(0, $run->getVersions(), $run->getTitle());
            self::assertNotCount(0, $run->getTestcases(), $run->getTitle());
        }
    }

    public function testLoadingTwiceDoesNotDuplicate(): void
    {
        $this->load();
        $this->load();

        self::assertSame(3, $this->countRows(Group::class));
        self::assertSame(6, $this->countRows(Project::class));
        self::assertSame(12, $this->countRows(Skill::class));
        self::assertSame(15, $this->countRows(Testcase::class));
        self::assertSame(8, $this->countRows(Run::class));
        self::assertSame(1, $this->countRows(User::class, "e.username = 'admin'"));
    }

    public function testIsRegisteredForTheDemoGroup(): void
    {
        $fixtures = self::getContainer()->get('doctrine.fixtures.loader')->getFixtures(['demo']);

        self::assertCount(1, $fixtures);
        self::assertInstanceOf(DemoFixtures::class, $fixtures[0]);
    }

    public function testKeepsStoredApiKeys(): void
    {
        $this->entityManager->persist(new ProviderApiKey(Credential::OpenAi, 'encrypted', '1234'));
        $this->entityManager->flush();

        $this->load();

        self::assertSame(1, $this->countRows(ProviderApiKey::class));
    }

    private function load(): void
    {
        $this->entityManager->clear();
        new DemoFixtures(self::getContainer()->get(UserPasswordHasherInterface::class))->load($this->entityManager);
        $this->entityManager->clear();
    }

    private function findUser(string $username): User
    {
        return $this->entityManager->getRepository(User::class)->findOneBy(['username' => $username])
            ?? self::fail(\sprintf('User "%s" was not created.', $username));
    }

    /** @param class-string $class */
    private function countRows(string $class, string $where = '1 = 1'): int
    {
        return (int) $this->entityManager->createQuery(\sprintf('SELECT COUNT(e) FROM %s e WHERE %s', $class, $where))->getSingleScalarResult();
    }
}
