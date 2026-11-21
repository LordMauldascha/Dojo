<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Ai\Credential;
use App\Entity\ProviderApiKey;
use App\Security\UserRole;
use App\Tests\Support\AppWebTestCase;

final class HomeControllerTest extends AppWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->entityManager->createQuery('DELETE FROM '.ProviderApiKey::class)->execute();
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseRedirects('http://localhost/login');
    }

    public function testAdminSeesAdministrationBlockAndAllProjects(): void
    {
        $this->entityManager->persist(new ProviderApiKey(Credential::OpenAi, 'ciphertext', '1234'));
        $this->entityManager->flush();
        $admin = $this->persistUser('root', UserRole::Admin);
        $this->persistGroup('Team');
        $this->persistProject('Foreign');
        $this->client->loginUser($admin);

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Foreign');
        self::assertSelectorTextContains('body', 'Provider keys: 1 of 4 set, missing: Google Gemini, DeepSeek, Claude Code');
        self::assertSelectorTextNotContains('body', 'missing: OpenAI');
        self::assertSelectorTextContains('body', 'Users: 1');
        self::assertSelectorTextContains('body', 'Groups: 1');
        self::assertStringNotContainsString('1234', (string) $this->client->getResponse()->getContent());
    }

    public function testUserSeesOnlyOwnWorkAndNoAdministrationBlock(): void
    {
        $user = $this->persistUser('frank');
        $mine = $this->persistProject('Mine', [$user]);
        $foreign = $this->persistProject('Foreign');
        $mySkill = $this->persistSkill('My skill', [$mine]);
        $myTestcase = $this->persistTestcase('My testcase', $mine);
        $foreignSkill = $this->persistSkill('Foreign skill', [$foreign]);
        $foreignTestcase = $this->persistTestcase('Foreign testcase', $foreign);
        $mySkill->saveDraft('My skill draft', null, 'content');
        $myTestcase->saveDraft('My testcase draft', null, 'prompt', 'output');
        $foreignSkill->saveDraft('Foreign skill draft', null, 'content');
        $foreignTestcase->saveDraft('Foreign testcase draft', null, 'prompt', 'output');
        $this->persistRun($mine, 'My run', [$mySkill], [$myTestcase], true)->start();
        $this->persistRun($foreign, 'Foreign run', [$foreignSkill], [$foreignTestcase], true)->start();
        $this->entityManager->flush();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $text = $crawler->filter('body')->text();
        foreach (['Mine', 'My run', 'My skill draft', 'My testcase draft'] as $expected) {
            self::assertStringContainsString($expected, $text);
        }
        self::assertStringNotContainsString('Foreign', $text);
        self::assertStringNotContainsString('Administration', $text);
    }

    public function testEmptyStatesAndProjectWithoutRun(): void
    {
        $user = $this->persistUser('frank');
        $this->client->loginUser($user);

        $this->client->request('GET', '/');

        self::assertSelectorTextContains('body', 'No projects yet.');
        self::assertSelectorTextContains('body', 'No run has been started yet.');
        self::assertSelectorTextContains('body', 'No open drafts.');

        $this->persistProject('Quiet', [$user]);
        $this->client->request('GET', '/');

        self::assertSelectorTextContains('tbody tr:nth-child(1)', 'Quiet');
        self::assertSelectorTextContains('tbody tr:nth-child(1) td:last-child', '–');
    }
}
