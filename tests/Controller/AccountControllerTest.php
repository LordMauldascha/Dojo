<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Security\UserRole;
use App\Tests\Support\AppWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DomCrawler\Crawler;

final class AccountControllerTest extends AppWebTestCase
{
    public function testAccountShowsProfileCountsAndGroups(): void
    {
        $user = $this->persistUser('sensei');
        $other = $this->persistUser('other');
        $group = $this->persistGroup('Dojo crew');
        $group->addUser($user);
        $group->addUser($other);
        $project = $this->persistProject('Katas', [$user]);
        $skill = $this->persistSkill('Mine', [$project], $user);
        $this->persistSkill('Not mine', [$project], $other);
        $this->persistSkill('Deleted', [$project], $user)->softDelete();
        $this->persistTestcase('Case', $project, $user);
        $this->persistRun($project, 'Nightly', [$skill], [], false, $user);
        $this->entityManager->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/account');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['Projects' => '1', 'Groups' => '1', 'Skills created' => '1', 'Testcases created' => '1', 'Runs created' => '1'],
            $this->stats($crawler),
        );
        self::assertSelectorTextContains('.facts', 'sensei');
        self::assertSelectorTextContains('.facts', UserRole::User->label());
        self::assertSame(['Dojo crew'], $this->titles($crawler->filter('table')->first()));
        self::assertSelectorExists('button[commandfor="password-dialog"]');
    }

    public function testAccountWithoutProjectsAndGroups(): void
    {
        $this->client->loginUser($this->persistUser('newbie'));

        $this->client->request('GET', '/account');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'No groups yet.');
        self::assertSelectorTextContains('main', 'No projects yet.');
        self::assertSelectorTextContains('.facts', 'Last sign-in');
    }

    public function testLastSignInIsStoredOnlyAfterASuccessfulLogin(): void
    {
        $id = $this->persistUser('sensei')->getId();
        self::assertNull($this->lastLoginOf($id));

        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Sign in')->form(['_username' => 'sensei', '_password' => 'wrong']));
        self::assertNull($this->lastLoginOf($id));

        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Sign in')->form(['_username' => 'sensei', '_password' => self::PASSWORD]));
        self::assertNotNull($this->lastLoginOf($id));
    }

    private function lastLoginOf(?int $id): ?\DateTimeImmutable
    {
        // the client reboots the kernel between requests, so read through a fresh entity manager
        $user = static::getContainer()->get(EntityManagerInterface::class)->find(User::class, $id);
        self::assertInstanceOf(User::class, $user);

        return $user->getLastLoginAt();
    }

    /** @return array<string, string> */
    private function stats(Crawler $crawler): array
    {
        $stats = [];
        foreach ($crawler->filter('.stat') as $node) {
            $tile = new Crawler($node);
            $stats[trim($tile->filter('dt')->text())] = trim($tile->filter('dd')->text());
        }

        return $stats;
    }
}
