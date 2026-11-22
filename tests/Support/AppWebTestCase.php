<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Ai\Credential;
use App\Ai\JudgeSettingStore;
use App\Ai\LlmConfiguration;
use App\Ai\Provider;
use App\Entity\Group;
use App\Entity\HuggingFaceModel;
use App\Entity\JudgeSetting;
use App\Entity\Project;
use App\Entity\ProviderApiKey;
use App\Entity\Run;
use App\Entity\RunVersion;
use App\Entity\Skill;
use App\Entity\Testcase;
use App\Entity\User;
use App\Security\ApiKeyStore;
use App\Security\UserRole;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

abstract class AppWebTestCase extends WebTestCase
{
    protected const PASSWORD = 'correct-horse-battery';

    protected KernelBrowser $client;
    private ?KernelInterface $concurrentKernel = null;
    protected EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Limiter and login throttling state lives in this file-backed pool and would survive test runs.
        $rateLimiterPool = static::getContainer()->get('cache.rate_limiter');
        $rateLimiterPool->clear();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        // refresh tokens only point at access tokens (ON DELETE SET NULL), so they go first; the rest cascades from the client
        foreach (['oauth2_refresh_token', 'oauth2_client'] as $table) {
            $this->entityManager->getConnection()->executeStatement('DELETE FROM '.$table);
        }
        foreach ([Run::class, Testcase::class, Skill::class, Project::class, Group::class, User::class, HuggingFaceModel::class, ProviderApiKey::class, JudgeSetting::class] as $class) {
            $this->entityManager->createQuery('DELETE FROM '.$class)->execute();
        }
    }

    protected function tearDown(): void
    {
        if (null !== $this->concurrentKernel) {
            $connection = $this->concurrentKernel->getContainer()->get('doctrine')->getConnection();
            \assert($connection instanceof Connection);
            $connection->close();
            $this->concurrentKernel->shutdown();
        }
        $this->concurrentKernel = null;
        parent::tearDown();
    }

    protected function storeKey(Credential $credential): void
    {
        static::getContainer()->get(ApiKeyStore::class)->save($credential, 'synthetic-key-0000000000');
    }

    /** Stores a Claude Code key and makes Claude Code opus the judge. */
    protected function storeJudge(): void
    {
        $this->storeKey(Credential::Anthropic);
        static::getContainer()->get(JudgeSettingStore::class)->save(new LlmConfiguration(Provider::ClaudeCode, 'opus'));
    }

    /** Starts the run as if every provider had a key. */
    protected function startRun(Run $run): RunVersion
    {
        return $run->start(static fn (): bool => true, new LlmConfiguration(Provider::ClaudeCode, 'opus'));
    }

    /** Marks every result as done, so the next start is not blocked by a running version. */
    protected function finishAllResults(): void
    {
        $this->entityManager->getConnection()->executeStatement("UPDATE run_result SET status = 'done'");
    }

    protected function persistUser(string $username, UserRole $role = UserRole::User, bool $enabled = true): User
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

    protected function persistGroup(string $title): Group
    {
        $group = new Group();
        $group->setTitle($title);
        $this->entityManager->persist($group);
        $this->entityManager->flush();

        return $group;
    }

    /** @param list<User> $users */
    protected function persistProject(string $title, array $users = [], ?string $description = null): Project
    {
        $project = new Project();
        $project->setTitle($title);
        $project->setDescription($description);
        foreach ($users as $user) {
            $project->addUser($user);
        }
        $this->entityManager->persist($project);
        $this->entityManager->flush();

        return $project;
    }

    /** @param list<Project> $projects */
    protected function persistSkill(string $title, array $projects = [], ?User $creator = null, string $content = 'content', ?string $description = null): Skill
    {
        $skill = new Skill($title, $description, $content, $creator);
        foreach ($projects as $project) {
            $skill->addProject($project);
        }
        $this->entityManager->persist($skill);
        $this->entityManager->flush();

        return $skill;
    }

    /** @param Project|list<Project>|null $projects a single project, several or none (admin-only) */
    protected function persistTestcase(string $title, Project|array|null $projects = null, ?User $creator = null, string $prompt = 'prompt', string $outputCriteria = 'output', ?string $description = null): Testcase
    {
        $testcase = new Testcase($title, $description, $prompt, $outputCriteria, $creator);
        foreach ($projects instanceof Project ? [$projects] : ($projects ?? []) as $project) {
            $testcase->addProject($project);
        }
        $this->entityManager->persist($testcase);
        $this->entityManager->flush();

        return $testcase;
    }

    /**
     * @param list<Skill>    $skills
     * @param list<Testcase> $testcases
     */
    protected function persistRun(Project $project, string $title = 'Nightly', array $skills = [], array $testcases = [], bool $configured = false, ?User $creator = null): Run
    {
        $run = new Run($project, $creator);
        $run->setTitle($title);
        $run->setSkills($skills);
        $run->setTestcases($testcases);
        if ($configured) {
            $run->addConfiguration(new LlmConfiguration(Provider::ClaudeCode, 'opus'));
        }
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        return $run;
    }

    /** @param array<string, mixed> $overrides */
    protected function submit(Crawler $crawler, string $button, string $formName, array $overrides): void
    {
        $form = $crawler->selectButton($button)->form();
        /** @var array<string, array<string, mixed>> $values */
        $values = $form->getPhpValues();
        $values[$formName] = array_replace($values[$formName], $overrides);
        $this->client->request($form->getMethod(), $form->getUri(), $values);
    }

    /** A connection of its own: its writes commit while the code under test holds its transaction open, as another worker's would. */
    protected function concurrentConnection(): Connection
    {
        $kernel = $this->concurrentKernel ??= static::createKernel();
        $kernel->boot();
        $connection = $kernel->getContainer()->get('doctrine')->getConnection();
        \assert($connection instanceof Connection);

        return $connection;
    }

    /** Runs raw SQL right before the entity is inserted, to simulate a concurrent request that wins the race. */
    protected function racingStatementBeforeInsertOf(string $entityClass, string $sql): void
    {
        $connection = $this->entityManager->getConnection();
        $this->client->disableReboot();
        $this->entityManager->getEventManager()->addEventListener(Events::prePersist, new class($connection, $entityClass, $sql) {
            public function __construct(private readonly Connection $connection, private readonly string $entityClass, private readonly string $sql)
            {
            }

            public function prePersist(PrePersistEventArgs $args): void
            {
                if ($args->getObject() instanceof $this->entityClass) {
                    $this->connection->executeStatement($this->sql);
                }
            }
        });
    }

    /** @return list<string> */
    protected function titles(Crawler $crawler): array
    {
        self::assertResponseIsSuccessful();

        return $crawler->filter('tbody tr td:first-child')->each(static fn (Crawler $cell): string => trim($cell->text()));
    }

    protected function tokenOf(Crawler $crawler, string $formSelector): ?string
    {
        $form = $crawler->filter($formSelector);

        return 0 === $form->count() ? null : $form->filter('input[name="_token"]')->attr('value');
    }

    /**
     * @template T of Skill|Testcase
     *
     * @param T $entity
     *
     * @return T
     */
    protected function reload(Skill|Testcase $entity): Skill|Testcase
    {
        $id = $entity->getId();
        self::assertIsInt($id);
        $reloaded = $this->entityManager->find($entity::class, $id);
        self::assertInstanceOf($entity::class, $reloaded);

        return $reloaded;
    }
}
