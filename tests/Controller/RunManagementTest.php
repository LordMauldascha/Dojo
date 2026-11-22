<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Ai\Credential;
use App\Ai\Effort;
use App\Ai\JudgeSettingStore;
use App\Ai\LlmConfiguration;
use App\Ai\Provider;
use App\Entity\HuggingFaceModel;
use App\Entity\ProviderApiKey;
use App\Entity\Run;
use App\Entity\RunResult;
use App\Entity\Skill;
use App\Entity\Testcase;
use App\Form\Help;
use App\Message\ExecuteRunResult;
use App\Run\RunStarter;
use App\Security\UserRole;
use App\Tests\Support\AppWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class RunManagementTest extends AppWebTestCase
{
    public function testUserCreatesAndEditsARunInTheirProject(): void
    {
        $user = $this->persistUser('alice');
        $project = $this->persistProject('Security', [$user]);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/projects/'.$project->getUuid().'/runs/new');
        $this->submit($crawler, 'Save', 'run', ['title' => 'Nightly', 'description' => 'All reviews']);

        $run = $this->findRun('Nightly');
        self::assertResponseRedirects('/runs/'.$run->getUuid());
        self::assertSame('alice', $run->getCreatedBy()?->getUsername());
        self::assertSame('Security', $run->getProject()->getTitle());

        $crawler = $this->client->request('GET', '/runs/'.$run->getUuid());
        $this->submit($crawler, 'Save', 'run', ['title' => 'Weekly']);
        self::assertResponseRedirects('/runs/'.$run->getUuid());
        $this->entityManager->clear();
        self::assertSame('Weekly', $this->findRun('Weekly')->getTitle());
    }

    public function testBlankTitleIsRejected(): void
    {
        $user = $this->persistUser('alice');
        $project = $this->persistProject('Security', [$user]);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/projects/'.$project->getUuid().'/runs/new');
        $this->submit($crawler, 'Save', 'run', ['title' => '   ']);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->entityManager->getRepository(Run::class)->findAll());
    }

    public function testInvalidEditKeepsTheStoredTitleInTheHeading(): void
    {
        $user = $this->persistUser('alice');
        $project = $this->persistProject('Security', [$user]);
        $run = $this->persistRun($project, 'Nightly');
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/runs/'.$run->getUuid());
        $this->submit($crawler, 'Save', 'run', ['title' => '   ']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('h1', 'Run Nightly');
        self::assertSelectorTextContains('title', 'Run Nightly');
    }

    public function testCreatingARunInAProjectDeletedMeanwhileShowsTheConflictMessage(): void
    {
        $user = $this->persistUser('alice');
        $project = $this->persistProject('Security', [$user]);
        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/projects/'.$project->getUuid().'/runs/new');
        $this->racingStatementBeforeInsertOf(Run::class, 'DELETE FROM project');

        $this->submit($crawler, 'Save', 'run', ['title' => 'Nightly']);

        self::assertResponseRedirects('/projects/'.$project->getUuid().'/runs/new');
        self::assertSame(0, $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM run'));
    }

    public function testUsersWithAccessViaGroupOrDirectlySeeAndEditRunsButOutsidersDoNot(): void
    {
        $direct = $this->persistUser('alice');
        $viaGroup = $this->persistUser('bob');
        $outsider = $this->persistUser('carol');
        $group = $this->persistGroup('Team one');
        $group->addUser($viaGroup);
        $project = $this->persistProject('Security', [$direct]);
        $project->addGroup($group);
        $this->entityManager->flush();
        $run = $this->persistRun($project, title: 'Nightly', configured: true);
        $skill = $this->persistSkill('Review', [$project]);
        $uuid = (string) $run->getUuid();

        foreach ([$direct, $viaGroup] as $user) {
            $this->client->loginUser($user);
            self::assertSame(['Nightly'], $this->titles($this->client->request('GET', '/projects/'.$project->getUuid().'/runs')));
            $crawler = $this->client->request('GET', '/runs/'.$uuid);
            self::assertResponseIsSuccessful();
            $this->submit($crawler, 'Save', 'run', ['description' => 'edited by '.$user->getUsername()]);
            self::assertResponseRedirects('/runs/'.$uuid);
        }

        $this->client->loginUser($outsider);
        foreach ([
            ['GET', '/projects/'.$project->getUuid().'/runs'],
            ['GET', '/projects/'.$project->getUuid().'/runs/new'],
            ['POST', '/projects/'.$project->getUuid().'/runs/new'],
            ['GET', '/runs/'.$uuid],
            ['POST', '/runs/'.$uuid],
            ['POST', '/runs/'.$uuid.'/configurations'],
            ['POST', '/runs/'.$uuid.'/configurations/claude_code:opus:-/remove'],
            ['POST', '/runs/'.$uuid.'/skills'],
            ['POST', '/runs/'.$uuid.'/testcases'],
            ['POST', '/runs/'.$uuid.'/start'],
            ['GET', '/runs/'.$uuid.'/versions/1'],
            ['GET', '/runs/'.$uuid.'/compare'],
            ['POST', '/runs/'.$uuid.'/delete'],
        ] as [$method, $url]) {
            $this->client->request($method, $url);
            self::assertResponseStatusCodeSame(403, \sprintf('%s %s', $method, $url));
        }
        $this->entityManager->clear();
        self::assertSame('edited by bob', $this->findRun('Nightly')->getDescription());
        self::assertNotNull($skill->getId());
    }

    public function testAnonymousUserIsSentToLoginAndUnknownRunsReturn404(): void
    {
        $admin = $this->persistUser('root', UserRole::Admin);
        $project = $this->persistProject('Security');
        $this->client->request('GET', '/runs/018f3a52-0000-7000-8000-000000000000');
        self::assertResponseRedirects('http://localhost/login');

        $this->client->loginUser($admin);
        foreach (['/runs/018f3a52-0000-7000-8000-000000000000', '/runs/not-a-uuid', '/projects/018f3a52-0000-7000-8000-000000000000/runs', '/projects/'.$project->getUuid().'/runs/new-x'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(404, $url);
        }
    }

    public function testCreatorAndAdminMayDeleteOthersMayNot(): void
    {
        $creator = $this->persistUser('alice');
        $colleague = $this->persistUser('bob');
        $project = $this->persistProject('Security', [$creator, $colleague]);
        $mine = $this->persistRun($project, creator: $creator, title: 'Mine');
        $adminDeletes = $this->persistRun($project, creator: $creator, title: 'Admin deletes');

        $this->client->loginUser($colleague);
        $this->client->request('GET', '/runs/'.$mine->getUuid().'/delete');
        self::assertResponseStatusCodeSame(405);
        $this->client->request('POST', '/runs/'.$mine->getUuid().'/delete');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/runs/'.$mine->getUuid());
        self::assertSelectorNotExists('form[action$="/delete"]');

        $this->client->loginUser($creator);
        $crawler = $this->client->request('GET', '/runs/'.$mine->getUuid());
        self::assertResponseIsSuccessful();
        $this->client->request('POST', '/runs/'.$mine->getUuid().'/delete', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        $this->client->submit($crawler->filter('form[action$="/delete"]')->form());
        self::assertResponseRedirects('/projects/'.$project->getUuid().'/runs');
        $this->entityManager->clear();
        self::assertNull($this->entityManager->getRepository(Run::class)->findOneBy(['title' => 'Mine']));

        $this->client->loginUser($this->persistUser('root', UserRole::Admin));
        $crawler = $this->client->request('GET', '/runs/'.$adminDeletes->getUuid());
        $this->client->submit($crawler->filter('form[action$="/delete"]')->form());
        self::assertResponseRedirects('/projects/'.$project->getUuid().'/runs');
        self::assertSame([], $this->titles($this->client->request('GET', '/projects/'.$project->getUuid().'/runs')));
    }

    public function testConfigurationsAreAddedDeduplicatedAndRemoved(): void
    {
        $user = $this->persistUser('alice');
        $project = $this->persistProject('Security', [$user]);
        $run = $this->persistRun($project, creator: $user);
        $path = '/runs/'.$run->getUuid();
        $this->storeKey(Credential::OpenAi);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', $path);
        self::assertSelectorTextContains('body', 'No configurations yet.');
        $this->submit($crawler, 'Add configuration', 'run_configuration', ['model' => 'openai:gpt-5.4', 'effort' => 'high']);
        self::assertResponseRedirects($path);
        $crawler = $this->client->request('GET', $path);
        self::assertSelectorTextContains('body', 'OpenAI / gpt-5.4 / effort high');

        $this->submit($crawler, 'Add configuration', 'run_configuration', ['model' => 'openai:gpt-5.4', 'effort' => 'high']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'This configuration already exists.');

        $crawler = $this->client->request('GET', $path);
        $this->submit($crawler, 'Add configuration', 'run_configuration', ['model' => 'openai:gpt-5.4', 'effort' => '']);
        $this->submit($crawler, 'Add configuration', 'run_configuration', ['model' => 'openai:opus', 'effort' => '']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'The configuration could not be added.');

        $this->entityManager->clear();
        self::assertSame(['openai:gpt-5.4:high', 'openai:gpt-5.4:-'], array_map(static fn (LlmConfiguration $c): string => $c->key(), $this->findRun('Nightly')->getConfigurations()));

        $crawler = $this->client->request('GET', $path);
        $this->client->submit($crawler->filter('form[action*="/configurations/"][action$="/remove"]')->form());
        self::assertResponseRedirects($path);
        $this->entityManager->clear();
        self::assertSame(['openai:gpt-5.4:-'], array_map(static fn (LlmConfiguration $c): string => $c->key(), $this->findRun('Nightly')->getConfigurations()));

        $this->client->request('POST', $path.'/configurations/openai:gpt-5.4:-/remove', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAddingAConfigurationOfAProviderWithoutKeyIsRejected(): void
    {
        $user = $this->persistUser('alice');
        $project = $this->persistProject('Security', [$user]);
        $run = $this->persistRun($project, creator: $user);
        $this->client->loginUser($user);
        $path = '/runs/'.$run->getUuid();

        $crawler = $this->client->request('GET', $path);
        $this->submit($crawler, 'Add configuration', 'run_configuration', ['model' => 'openai:gpt-5.5', 'effort' => '']);
        self::assertResponseRedirects($path);
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'The configuration could not be added.');

        $this->entityManager->clear();
        self::assertSame([], $this->findRun('Nightly')->getConfigurations());
    }

    public function testConfigurationWhoseKeyWasRemovedShowsWarning(): void
    {
        $user = $this->persistUser('alice');
        $project = $this->persistProject('Security', [$user]);
        $run = $this->persistRun($project, creator: $user);
        $this->storeKey(Credential::OpenAi);
        $this->client->loginUser($user);
        $path = '/runs/'.$run->getUuid();

        $crawler = $this->client->request('GET', $path);
        $this->submit($crawler, 'Add configuration', 'run_configuration', ['model' => 'openai:gpt-5.5', 'effort' => '']);
        $this->client->request('GET', $path);
        self::assertSelectorTextNotContains('tbody', 'No API key stored');

        $this->entityManager->createQuery('DELETE FROM '.ProviderApiKey::class)->execute();
        $this->client->request('GET', $path);
        self::assertSelectorTextContains('tbody tr', 'OpenAI / gpt-5.5');
        self::assertSelectorTextContains('tbody tr', 'No API key stored');
    }

    public function testOnlyAddedHuggingFaceModelsAreSelectableAndTheirKeyCanBeRemoved(): void
    {
        $this->entityManager->persist(new HuggingFaceModel('acme/chat-8b'));
        $this->entityManager->flush();
        $this->storeKey(Credential::HuggingFace);
        $user = $this->persistUser('alice');
        $project = $this->persistProject('Security', [$user]);
        $run = $this->persistRun($project, creator: $user);
        $path = '/runs/'.$run->getUuid();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', $path);
        self::assertSame(['huggingface:acme/chat-8b'], $crawler->filter('optgroup[label="Hugging Face"] option')->each(static fn (Crawler $option): string => (string) $option->attr('value')));

        $this->submit($crawler, 'Add configuration', 'run_configuration', ['model' => 'huggingface:acme/chat-8b', 'effort' => '']);
        self::assertResponseRedirects($path);
        $crawler = $this->client->request('GET', $path);
        self::assertSelectorTextContains('body', 'Hugging Face / acme/chat-8b / no effort');

        $this->submit($crawler, 'Add configuration', 'run_configuration', ['model' => 'huggingface:acme/not-added', 'effort' => '']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'The configuration could not be added.');

        $this->client->submit($crawler->filter('form[action*="/configurations/"][action$="/remove"]')->form());
        self::assertResponseRedirects($path);
        $this->entityManager->clear();
        self::assertSame([], $this->findRun('Nightly')->getConfigurations());
    }

    public function testOnlyNonDeletedSkillsAndTestcasesOfTheRunsProjectAreSelectable(): void
    {
        $user = $this->persistUser('alice');
        $project = $this->persistProject('Security', [$user]);
        $other = $this->persistProject('Other', [$user]);
        $own = $this->persistSkill('Own skill', [$project]);
        $foreign = $this->persistSkill('Foreign skill', [$other]);
        $deleted = $this->persistSkill('Deleted skill', [$project]);
        $deleted->softDelete();
        $ownTestcase = $this->persistTestcase('Own testcase', $project);
        $foreignTestcase = $this->persistTestcase('Foreign testcase', $other);
        $this->entityManager->flush();
        $run = $this->persistRun($project, creator: $user);
        $path = '/runs/'.$run->getUuid();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', $path);
        $body = $crawler->filter('body')->text();
        self::assertStringContainsString('Own skill', $body);
        self::assertStringContainsString('Own testcase', $body);
        self::assertStringNotContainsString('Foreign skill', $body);
        self::assertStringNotContainsString('Deleted skill', $body);
        self::assertStringNotContainsString('Foreign testcase', $body);

        $this->submit($crawler, 'Save skills', 'run_skills', ['skills' => [(string) $own->getId()]]);
        $this->submit($crawler, 'Save testcases', 'run_testcases', ['testcases' => [(string) $ownTestcase->getId()]]);
        $this->entityManager->clear();
        $stored = $this->findRun('Nightly');
        self::assertSame(['Own skill'], $stored->getSkills()->map(static fn (Skill $s): string => $s->getActiveVersion()->getTitle())->toArray());
        self::assertSame(['Own testcase'], $stored->getTestcases()->map(static fn (Testcase $t): string => $t->getActiveVersion()->getTitle())->toArray());

        $crawler = $this->client->request('GET', $path);
        foreach ([[(string) $foreign->getId(), 'Save skills', 'run_skills', 'skills'], [(string) $deleted->getId(), 'Save skills', 'run_skills', 'skills']] as [$id, $button, $form, $field]) {
            $this->submit($crawler, $button, $form, [$field => [$id]]);
            $this->client->followRedirect();
            self::assertSelectorTextContains('body', 'The skills could not be saved.');
        }
        $this->submit($crawler, 'Save testcases', 'run_testcases', ['testcases' => [(string) $foreignTestcase->getId()]]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'The testcases could not be saved.');
        $this->entityManager->clear();
        self::assertCount(1, $this->findRun('Nightly')->getSkills());
        self::assertCount(1, $this->findRun('Nightly')->getTestcases());
    }

    public function testStartCreatesNumberedVersionsAndIsRefusedWhenIncomplete(): void
    {
        $user = $this->persistUser('alice');
        $project = $this->persistProject('Security', [$user]);
        $skill = $this->persistSkill('Review', [$project]);
        $testcase = $this->persistTestcase('Greeting', $project);
        $run = $this->persistRun($project, creator: $user);
        $path = '/runs/'.$run->getUuid();
        $this->storeJudge();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', $path);
        $this->client->submit($crawler->filter('form[action$="/start"]')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'A run needs at least one testcase to start.');

        $run = $this->findRun('Nightly');
        $run->setTestcases([$this->reload($testcase)]);
        $this->entityManager->flush();
        $crawler = $this->client->request('GET', $path);
        $this->client->submit($crawler->filter('form[action$="/start"]')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'A run needs at least one LLM configuration to start.');
        $this->entityManager->clear();
        self::assertCount(0, $this->findRun('Nightly')->getVersions());

        $run = $this->findRun('Nightly');
        $run->addConfiguration(new LlmConfiguration(Provider::ClaudeCode, 'opus', Effort::Low));
        $run->setSkills([$this->reload($skill)]);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', $path);
        $this->client->submit($crawler->filter('form[action$="/start"]')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Started as version 1.');
        $this->entityManager->clear();
        $version = $this->findRun('Nightly')->findVersion(1);
        self::assertSame('claude_code:opus:-', $version?->getJudge()?->key());
        self::assertCount(1, $version->getResults());
        $this->finishAllResults();
        $crawler = $this->client->request('GET', $path);
        $this->client->submit($crawler->filter('form[action$="/start"]')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Started as version 2.');

        $this->client->request('POST', $path.'/start', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        $this->entityManager->clear();
        self::assertCount(2, $this->findRun('Nightly')->getVersions());
    }

    public function testStartIsBlockedWithoutJudge(): void
    {
        $user = $this->persistUser('alice');
        $project = $this->persistProject('Security', [$user]);
        $run = $this->persistRun($project, creator: $user, testcases: [$this->persistTestcase('Greeting', $project)], configured: true);
        $this->storeKey(Credential::Anthropic);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/runs/'.$run->getUuid());
        $this->client->submit($crawler->filter('form[action$="/start"]')->form());
        $crawler = $this->client->followRedirect();

        self::assertSelectorTextContains('body', 'No LLM judge is set.');
        self::assertCount(0, $crawler->filter('p.help-text a'), 'only admins can open it');
        $this->entityManager->clear();
        self::assertCount(0, $this->findRun('Nightly')->getVersions());

        $this->client->loginUser($this->persistUser('root', UserRole::Admin));
        $crawler = $this->client->request('GET', '/runs/'.$run->getUuid());
        self::assertSelectorTextContains('p.help-text', 'No LLM judge is set.');
        self::assertCount(1, $crawler->filter('p.help-text a[href="/admin/llm-configuration"]'));
    }

    public function testStartIsBlockedWhenJudgeHasNoKey(): void
    {
        $admin = $this->persistUser('root', UserRole::Admin);
        $project = $this->persistProject('Security');
        $run = $this->persistRun($project, creator: $admin, testcases: [$this->persistTestcase('Greeting', $project)]);
        $run->addConfiguration(new LlmConfiguration(Provider::OpenAi, 'gpt-5.4'));
        $this->entityManager->flush();
        $this->storeJudge();
        $this->storeKey(Credential::OpenAi);
        $this->entityManager->createQuery('DELETE FROM '.ProviderApiKey::class.' k WHERE k.credential = :credential')->setParameter('credential', Credential::Anthropic)->execute();
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/runs/'.$run->getUuid());
        $this->client->submit($crawler->filter('form[action$="/start"]')->form());
        $crawler = $this->client->followRedirect();

        self::assertSelectorTextContains('body', 'The LLM judge has no API key.');
        self::assertCount(1, $crawler->filter('p.help-text a[href="/admin/llm-configuration"]'));
        $this->entityManager->clear();
        self::assertCount(0, $this->findRun('Nightly')->getVersions());
    }

    public function testStartUsesKeyStateAtClickTime(): void
    {
        $user = $this->persistUser('alice');
        $project = $this->persistProject('Security', [$user]);
        $run = $this->persistRun($project, creator: $user, testcases: [$this->persistTestcase('Greeting', $project)], configured: true);
        $this->storeJudge();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/runs/'.$run->getUuid());
        $this->entityManager->createQuery('DELETE FROM '.ProviderApiKey::class)->execute();
        $this->client->submit($crawler->filter('form[action$="/start"]')->form());
        $this->client->followRedirect();

        self::assertSelectorTextContains('body', 'No LLM configuration has an API key.');
        $this->entityManager->clear();
        self::assertCount(0, $this->findRun('Nightly')->getVersions());
    }

    public function testSecondStartIsBlockedWhileAVersionRuns(): void
    {
        $user = $this->persistUser('alice');
        $project = $this->persistProject('Security', [$user]);
        $run = $this->persistRun($project, creator: $user, testcases: [$this->persistTestcase('Greeting', $project)], configured: true);
        $this->storeJudge();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/runs/'.$run->getUuid());
        $this->client->submit($crawler->filter('form[action$="/start"]')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Started as version 1.');

        $crawler = $this->client->request('GET', '/runs/'.$run->getUuid());
        self::assertSelectorTextContains('p.help-text', 'A version of this run is still running.');
        $this->client->submit($crawler->filter('form[action$="/start"]')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'A version of this run is still running.');
        $this->entityManager->clear();
        self::assertCount(1, $this->findRun('Nightly')->getVersions());
    }

    public function testStaleResultUnblocksTheRun(): void
    {
        $user = $this->persistUser('alice');
        $project = $this->persistProject('Security', [$user]);
        $run = $this->persistRun($project, creator: $user, testcases: [$this->persistTestcase('Greeting', $project)], configured: true);
        $this->storeJudge();
        $this->client->loginUser($user);
        $this->startRun($run);
        $this->entityManager->flush();
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE run_result SET status = 'running', status_changed_at = ?",
            [(new \DateTimeImmutable('-31 minutes'))->format('Y-m-d H:i:s')],
        );
        $this->entityManager->clear();

        $crawler = $this->client->request('GET', '/runs/'.$run->getUuid());

        self::assertSelectorTextNotContains('body', 'A version of this run is still running.');
        $this->entityManager->clear();
        $result = $this->firstResultOfVersionOne();
        self::assertSame('failed:timeout', $result->getStatus()->value.':'.$result->getError());
        $this->client->submit($crawler->filter('form[action$="/start"]')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Started as version 2.');
    }

    public function testVersionPageFailsStaleResults(): void
    {
        $user = $this->persistUser('alice');
        $project = $this->persistProject('Security', [$user]);
        $run = $this->persistRun($project, creator: $user, testcases: [$this->persistTestcase('Greeting', $project)], configured: true);
        $this->client->loginUser($user);
        $this->startRun($run);
        $this->entityManager->flush();
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE run_result SET status = 'judging', status_changed_at = ?",
            [(new \DateTimeImmutable('-31 minutes'))->format('Y-m-d H:i:s')],
        );
        $this->entityManager->clear();

        $this->client->request('GET', '/runs/'.$run->getUuid().'/versions/1');

        self::assertResponseIsSuccessful();
        $this->entityManager->clear();
        $result = $this->firstResultOfVersionOne();
        self::assertSame('failed:timeout', $result->getStatus()->value.':'.$result->getError());
    }

    public function testStartDispatchesOneMessagePerResult(): void
    {
        $user = $this->persistUser('alice');
        $project = $this->persistProject('Security', [$user]);
        $run = $this->persistRun($project, creator: $user, testcases: [$this->persistTestcase('One', $project), $this->persistTestcase('Two', $project)], configured: true);
        $this->storeJudge();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/runs/'.$run->getUuid());
        $this->client->submit($crawler->filter('form[action$="/start"]')->form());

        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        self::assertCount(2, $transport->getSent());
        foreach ($transport->getSent() as $envelope) {
            self::assertInstanceOf(ExecuteRunResult::class, $envelope->getMessage());
        }
    }

    public function testStartConfirmationShowsCallCounts(): void
    {
        $user = $this->persistUser('alice');
        $project = $this->persistProject('Security', [$user]);
        $testcases = [$this->persistTestcase('One', $project), $this->persistTestcase('Two', $project), $this->persistTestcase('Three', $project)];
        $run = $this->persistRun($project, creator: $user, testcases: $testcases, configured: true);
        $run->addConfiguration(new LlmConfiguration(Provider::OpenAi, 'gpt-5.4'));
        $this->entityManager->flush();
        $this->storeJudge();
        $this->storeKey(Credential::OpenAi);
        $this->client->loginUser($user);

        $this->client->request('GET', '/runs/'.$run->getUuid());

        self::assertSelectorTextContains('dialog', '6 LLM calls and 6 judge calls');
    }

    public function testStartDialogAsksForABaselineOnlyForTheFirstStartWithSkills(): void
    {
        $user = $this->persistUser('alice');
        $project = $this->persistProject('Security', [$user]);
        $withoutSkills = $this->persistRun($project, 'Plain', creator: $user, testcases: [$this->persistTestcase('One', $project)], configured: true);
        $withSkills = $this->persistRun($project, 'Skilled', skills: [$this->persistSkill('Style', [$project])], testcases: [$this->persistTestcase('Two', $project)], configured: true, creator: $user);
        $this->storeJudge();
        $this->client->loginUser($user);

        $this->client->request('GET', '/runs/'.$withoutSkills->getUuid());
        self::assertSelectorNotExists('dialog button[name="baseline"]');

        $this->client->request('GET', '/runs/'.$withSkills->getUuid());
        self::assertSelectorCount(2, 'dialog button[name="baseline"]');
        self::assertSelectorTextContains('dialog', 'Also start a baseline without skills');
        self::assertSelectorTextContains('dialog', 'Without baseline this makes 1 LLM calls and 1 judge calls, with baseline 2 and 2.');
    }

    public function testStartWithBaselineCreatesAVersionWithoutSkillsAndOneWithSkills(): void
    {
        $user = $this->persistUser('alice');
        $project = $this->persistProject('Security', [$user]);
        $run = $this->persistRun($project, 'Skilled', skills: [$this->persistSkill('Style', [$project])], testcases: [$this->persistTestcase('One', $project)], configured: true, creator: $user);
        $this->storeJudge();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/runs/'.$run->getUuid());
        $this->client->submit($crawler->filter('dialog button[name="baseline"][value="1"]')->form());
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        self::assertCount(2, $transport->getSent());
        $this->client->followRedirect();

        self::assertSelectorTextContains('body', 'Started as version 1 (baseline without skills) and version 2.');
        $this->entityManager->clear();
        $run = $this->findRun('Skilled');
        self::assertCount(0, $run->findVersion(1)?->getSkillVersions() ?? throw new \LogicException('No baseline.'));
        self::assertCount(1, $run->findVersion(2)?->getSkillVersions() ?? throw new \LogicException('No version.'));
    }

    public function testStartWithoutBaselineChoiceCreatesOneVersionWithSkills(): void
    {
        $user = $this->persistUser('alice');
        $project = $this->persistProject('Security', [$user]);
        $run = $this->persistRun($project, 'Skilled', skills: [$this->persistSkill('Style', [$project])], testcases: [$this->persistTestcase('One', $project)], configured: true, creator: $user);
        $this->storeJudge();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/runs/'.$run->getUuid());
        $this->client->submit($crawler->filter('dialog button[name="baseline"][value="0"]')->form());

        $this->entityManager->clear();
        $run = $this->findRun('Skilled');
        self::assertCount(1, $run->getVersions());
        self::assertCount(1, $run->findVersion(1)?->getSkillVersions() ?? throw new \LogicException('No version.'));
    }

    public function testVersionsTileShowsTheStatusOfTheLatestVersion(): void
    {
        $user = $this->persistUser('alice');
        $project = $this->persistProject('Security', [$user]);
        $run = $this->persistRun($project, creator: $user, testcases: [$this->persistTestcase('One', $project)], configured: true);
        $this->storeJudge();
        $this->client->loginUser($user);

        $this->client->request('GET', '/runs/'.$run->getUuid());
        self::assertSelectorNotExists('.stats .stat--wide [data-version-status]');

        $versionId = static::getContainer()->get(RunStarter::class)->start($run)->getId();
        $this->client->request('GET', '/runs/'.$run->getUuid());
        self::assertSelectorTextSame('.stats .stat--wide [data-version-status]', 'Version 1: running');

        // the kernel resets its services between requests, so results are loaded fresh before each change
        $this->changeResults($versionId, static function (RunResult $result): void {
            $result->markRunning();
            $result->markJudging('answer', null, null, 1);
        });
        $this->client->request('GET', '/runs/'.$run->getUuid());
        self::assertSelectorTextSame('.stats .stat--wide [data-version-status]', 'Version 1: judging');

        $this->changeResults($versionId, static fn (RunResult $result): bool => $result->markDone([['criterion' => 'c', 'met' => true, 'reason' => 'r']]));
        $this->client->request('GET', '/runs/'.$run->getUuid());
        self::assertSelectorTextSame('.stats .stat--wide [data-version-status]', 'Version 1: finished');
    }

    public function testStatusEndpointRendersTheStartPanelForLivePolling(): void
    {
        $user = $this->persistUser('alice');
        $project = $this->persistProject('Security', [$user]);
        $run = $this->persistRun($project, creator: $user, testcases: [$this->persistTestcase('One', $project)], configured: true);
        $this->storeJudge();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/runs/'.$run->getUuid());
        $panel = $crawler->filter('[data-progress-url$="/runs/'.$run->getUuid().'/status"]');
        self::assertCount(1, $panel);
        self::assertSame('not_executed', $panel->attr('data-status'));
        self::assertCount(1, $panel->filter('.stats'), 'the polled panel holds the tiles');

        $versionId = static::getContainer()->get(RunStarter::class)->start($run)->getId();
        $crawler = $this->client->request('GET', '/runs/'.$run->getUuid());
        self::assertSame('running', $crawler->filter('[data-progress-url]')->attr('data-status'));

        $this->changeResults($versionId, static function (RunResult $result): void {
            $result->markRunning();
            $result->markJudging('answer', 1234, null, 1500);
        });
        $this->client->request('GET', '/runs/'.$run->getUuid().'/status');
        self::assertResponseIsSuccessful();
        /** @var array{status: string, done: int, total: int, html: string} $progress */
        $progress = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('running', $progress['status']);
        self::assertSame([0, 1], [$progress['done'], $progress['total']]);
        self::assertStringContainsString('Version 1: judging', $progress['html']);
        self::assertStringContainsString('A version of this run is still running.', $progress['html']);
        self::assertSame(['–'], (new Crawler($progress['html']))->filter('#versions + table [data-criteria-score]')->each(static fn (Crawler $td): string => trim($td->text())), 'the versions table is part of the polled panel');

        $this->changeResults($versionId, static fn (RunResult $result): bool => $result->markDone([['criterion' => 'c', 'met' => true, 'reason' => 'r']]));
        $this->client->request('GET', '/runs/'.$run->getUuid().'/status');
        /** @var array{status: string, done: int, total: int, html: string} $progress */
        $progress = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('completed', $progress['status']);
        self::assertStringContainsString('Version 1: finished', $progress['html']);
        self::assertSame(['100 %'], (new Crawler($progress['html']))->filter('#versions + table [data-criteria-score]')->each(static fn (Crawler $td): string => trim($td->text())));
        self::assertSame('Criteria met', trim((new Crawler($progress['html']))->filter('#versions + table thead th')->eq(2)->text()));
        self::assertSame(['Avg. duration', 'Avg. input tokens', 'Avg. output tokens'], array_slice((new Crawler($progress['html']))->filter('#versions + table thead th')->each(static fn (Crawler $th): string => trim($th->text())), 3, 3));
        $averages = (new Crawler($progress['html']))->filter('#versions + table tbody td')->each(static fn (Crawler $td): string => trim($td->text()));
        self::assertSame(['1.5 s', '1,234', '–'], array_slice($averages, 3, 3), 'a missing token count shows a dash');
        self::assertStringContainsString('1 LLM calls and 1 judge calls', $progress['html'], 'the start confirmation is back once the version finished');

        $this->client->loginUser($this->persistUser('mallory'));
        $this->client->request('GET', '/runs/'.$run->getUuid().'/status');
        self::assertResponseStatusCodeSame(403);
    }

    /** @param callable(RunResult): mixed $change */
    private function changeResults(?int $versionId, callable $change): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $results = $entityManager->getRepository(RunResult::class)->findBy(['runVersion' => $versionId]);
        self::assertNotSame([], $results);
        array_map($change, $results);
        $entityManager->flush();
    }

    public function testStartSkipsConfigurationsWithoutKeyAndIsRefusedWhenNoneHasOne(): void
    {
        $user = $this->persistUser('alice');
        $project = $this->persistProject('Security', [$user]);
        $run = $this->persistRun($project, creator: $user, testcases: [$this->persistTestcase('Greeting', $project)], configured: true);
        $run->addConfiguration(new LlmConfiguration(Provider::OpenAi, 'gpt-5.4'));
        $this->entityManager->flush();
        static::getContainer()->get(JudgeSettingStore::class)->save(new LlmConfiguration(Provider::OpenAi, 'gpt-5.4'));
        $this->client->loginUser($user);

        $this->entityManager->createQuery('DELETE FROM '.ProviderApiKey::class)->execute();
        $crawler = $this->client->request('GET', '/runs/'.$run->getUuid());
        $this->client->submit($crawler->filter('form[action$="/start"]')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'No LLM configuration has an API key.');

        $this->storeKey(Credential::OpenAi);
        $crawler = $this->client->request('GET', '/runs/'.$run->getUuid());
        $this->client->submit($crawler->filter('form[action$="/start"]')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Started as version 1.');
        $this->entityManager->clear();
        $version = $this->findRun('Nightly')->findVersion(1);
        self::assertSame(['claude_code:opus:-'], array_map(static fn (LlmConfiguration $c): string => $c->key(), $version?->getSkippedConfigurations() ?? []));
        self::assertSame(['openai:gpt-5.4:-'], array_map(static fn ($r): string => $r->getConfigurationKey(), $version?->getResults()->toArray() ?? []));
    }

    public function testVersionPageShowsTheSnapshotAndCompareHasDefaults(): void
    {
        $user = $this->persistUser('alice');
        $project = $this->persistProject('Security', [$user]);
        $skill = $this->persistSkill('Review', [$project]);
        $testcase = $this->persistTestcase('Greeting', $project);
        $run = $this->persistRun($project, creator: $user, title: 'Nightly', skills: [$skill], testcases: [$testcase], configured: true);
        $this->startRun($run);
        $run->setTitle('Weekly');
        $skill->saveFinal('Review', null, 'content 2');
        $this->startRun($run);
        $this->entityManager->flush();
        $this->client->loginUser($user);

        $this->client->request('GET', '/runs/'.$run->getUuid().'/versions/1');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Nightly – version 1');
        self::assertSelectorTextContains('body', 'Review (version 1)');
        self::assertSelectorTextContains('body', 'Greeting (version 1)');
        self::assertSelectorTextContains('body', 'Claude Code / opus / no effort');

        $this->client->request('GET', '/runs/'.$run->getUuid().'/compare');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Differences from version 1 to version 2');
        self::assertSelectorTextContains('body', 'Nightly');
        self::assertSelectorTextContains('body', 'Weekly');
        self::assertSelectorTextContains('body', 'Changed: Review from version 1 to version 2');

        $this->client->request('GET', '/runs/'.$run->getUuid().'/compare', ['from' => 2, 'to' => 2]);
        self::assertSelectorTextContains('body', 'No differences between version 2 and version 2.');

        $this->client->request('GET', '/runs/'.$run->getUuid().'/versions/9');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/runs/'.$run->getUuid().'/compare', ['from' => 1, 'to' => 9]);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/runs/'.$run->getUuid().'/compare', ['from' => 0]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testCompareShowsScoreChangesAndAverages(): void
    {
        $user = $this->persistUser('alice');
        $project = $this->persistProject('Security', [$user]);
        $run = $this->persistRun($project, testcases: [$this->persistTestcase('Greeting', $project), $this->persistTestcase('Farewell', $project)], configured: true, creator: $user);
        $first = $this->startRun($run);
        $this->entityManager->flush();
        $this->finishAllResults();
        $second = $this->startRun($run);
        $this->entityManager->flush();
        $conn = $this->entityManager->getConnection();
        foreach ([[$first, 'Greeting', 60], [$first, 'Farewell', 80], [$second, 'Greeting', 80], [$second, 'Farewell', 80]] as [$version, $title, $score]) {
            $conn->executeStatement(
                'UPDATE run_result SET score = ? WHERE run_version_id = ? AND testcase_version_id IN (SELECT id FROM testcase_version WHERE title = ?)',
                [$score, $version->getId(), $title],
            );
        }
        $this->entityManager->clear();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/runs/'.$run->getUuid().'/compare', ['from' => 1, 'to' => 2]);

        self::assertResponseIsSuccessful();
        self::assertSame('Greeting', trim($crawler->filter('table tbody th[scope="row"]')->eq(0)->text()));
        self::assertSelectorTextContains('body', '60 → 80 (+20)');
        self::assertSelectorTextContains('body', '80 → 80 (±0)');
        self::assertSelectorTextContains('body', '70.0 → 80.0 (+10.0) · 2 cells');

        $conn->executeStatement('UPDATE run_result SET score = NULL WHERE run_version_id = ? AND score = 80 AND testcase_version_id IN (SELECT id FROM testcase_version WHERE title = ?)', [$second->getId(), 'Farewell']);
        $conn->executeStatement('UPDATE run_result SET score = 50 WHERE run_version_id = ? AND testcase_version_id IN (SELECT id FROM testcase_version WHERE title = ?)', [$second->getId(), 'Greeting']);
        $this->client->request('GET', '/runs/'.$run->getUuid().'/compare', ['from' => 1, 'to' => 2]);
        self::assertSelectorTextContains('body', '60 → 50 (-10)');
        self::assertSelectorTextContains('body', '80 → –');
        // Farewell has no score in version 2, so only Greeting is averaged
        self::assertSelectorTextContains('body', '60.0 → 50.0 (-10.0) · 1 cell');
    }

    public function testCompareDeltaMatchesTheRoundedAverages(): void
    {
        $user = $this->persistUser('alice');
        $project = $this->persistProject('Security', [$user]);
        $titles = ['Alpha', 'Beta', 'Gamma'];
        $run = $this->persistRun($project, testcases: array_map(fn (string $title) => $this->persistTestcase($title, $project), $titles), configured: true, creator: $user);
        $first = $this->startRun($run);
        $this->entityManager->flush();
        $this->finishAllResults();
        $second = $this->startRun($run);
        $this->entityManager->flush();
        $conn = $this->entityManager->getConnection();
        // averages 33.33 and 33.67: the unrounded difference 0.33 would show as +0.3 between 33.3 and 33.7
        foreach ([[$first, [33, 33, 34]], [$second, [34, 33, 34]]] as [$version, $scores]) {
            foreach ($titles as $index => $title) {
                $conn->executeStatement(
                    'UPDATE run_result SET score = ? WHERE run_version_id = ? AND testcase_version_id IN (SELECT id FROM testcase_version WHERE title = ?)',
                    [$scores[$index], $version->getId(), $title],
                );
            }
        }
        $this->entityManager->clear();
        $this->client->loginUser($user);

        $this->client->request('GET', '/runs/'.$run->getUuid().'/compare', ['from' => 1, 'to' => 2]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', '33.3 → 33.7 (+0.4)');
    }

    public function testCompareWithoutFromShowsAnEmptyFromOptionInsteadOfVersionOne(): void
    {
        $user = $this->persistUser('alice');
        $project = $this->persistProject('Security', [$user]);
        $skill = $this->persistSkill('Review', [$project]);
        $run = $this->persistRun($project, skills: [$skill], testcases: [$this->persistTestcase('Greeting', $project)], configured: true, creator: $user);
        $this->startRun($run);
        $this->entityManager->flush();
        $this->startRun($run);
        $this->entityManager->flush();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/runs/'.$run->getUuid().'/compare', ['to' => 1]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Select two versions.');
        self::assertSame('', $crawler->filter('select[name="from"] option[selected]')->attr('value'));
        self::assertSame('1', $crawler->filter('select[name="to"] option[selected]')->attr('value'));

        $this->client->request('GET', '/runs/'.$run->getUuid().'/compare', ['from' => '', 'to' => 1]);
        self::assertResponseIsSuccessful();
    }

    public function testCompareWithoutTwoVersionsExplainsIt(): void
    {
        $user = $this->persistUser('alice');
        $project = $this->persistProject('Security', [$user]);
        $run = $this->persistRun($project, creator: $user);
        $this->client->loginUser($user);

        $this->client->request('GET', '/runs/'.$run->getUuid().'/compare');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'At least two versions are needed to compare.');
    }

    public function testListIsScopedToTheProjectAndSearchesSortsAndPaginates(): void
    {
        $user = $this->persistUser('alice');
        $project = $this->persistProject('Security', [$user]);
        $other = $this->persistProject('Other', [$user]);
        $skill = $this->persistSkill('Review', [$project]);
        $this->persistRun($other, creator: $user, title: 'Elsewhere');
        $this->persistRun($project, creator: $user, title: 'Alpha');
        $this->persistRun($project, creator: $user, title: 'Beta', skills: [$skill]);
        $gamma = $this->persistRun($project, creator: $user, title: 'Gamma_x');
        $gamma->setDescription('handles GDPR requests');
        $big = $this->persistProject('Big', [$user]);
        for ($i = 1; 28 >= $i; ++$i) {
            $this->persistRun($big, creator: $user, title: \sprintf('Run %02d', $i));
        }
        $this->entityManager->flush();
        $this->client->loginUser($user);
        $url = '/projects/'.$project->getUuid().'/runs';

        self::assertSame(['Alpha', 'Beta', 'Gamma_x'], $this->titles($this->client->request('GET', $url)));
        self::assertSame(['Gamma_x'], $this->titles($this->client->request('GET', $url, ['q' => 'gdpr'])));
        self::assertSame(['Gamma_x'], $this->titles($this->client->request('GET', $url, ['q' => 'a_x'])));
        self::assertSame([], $this->titles($this->client->request('GET', $url, ['q' => '%'])));

        $crawler = $this->client->request('GET', $url, ['sort' => 'skills', 'dir' => 'desc']);
        self::assertSame('Beta', $this->titles($crawler)[0]);
        self::assertSame('1', trim($crawler->filter('tbody tr:first-child td:nth-child(3)')->text()));

        $bigUrl = '/projects/'.$big->getUuid().'/runs';
        self::assertCount(10, $this->titles($this->client->request('GET', $bigUrl)));
        $crawler = $this->client->request('GET', $bigUrl, ['page' => 3, 'perPage' => 10]);
        self::assertSelectorTextContains('nav[aria-label="Pagination"]', 'Page 3 of 3 (28 entries)');
        self::assertCount(8, $this->titles($crawler));
    }

    public function testProjectDetailShowsTheRunCountAndProjectDeleteRemovesRunsButKeepsSkillsAndTestcases(): void
    {
        $admin = $this->persistUser('root', UserRole::Admin);
        $project = $this->persistProject('Security', [$admin]);
        $skill = $this->persistSkill('Review', [$project]);
        $testcase = $this->persistTestcase('Greeting', $project);
        $run = $this->persistRun($project, creator: $admin, title: 'Nightly', skills: [$skill], testcases: [$testcase], configured: true);
        $this->startRun($run);
        $this->startRun($run);
        $this->entityManager->flush();
        $this->client->loginUser($admin);

        $this->client->request('GET', '/projects/'.$project->getUuid());
        self::assertSame('1', trim($this->client->getCrawler()->filter('.stats .stat:first-child dd a:first-child')->text()));
        self::assertSelectorExists('a[href$="/runs"]');

        $crawler = $this->client->request('GET', '/projects/'.$project->getUuid());
        self::assertSelectorTextContains('dialog', '1 run(s) and their versions are deleted.');
        $this->client->submit($crawler->filter('form[action$="/delete"]')->form());
        self::assertResponseRedirects('/projects');

        $this->entityManager->clear();
        self::assertCount(0, $this->entityManager->getRepository(Run::class)->findAll());
        foreach (['run', 'run_version', 'run_skill', 'run_testcase', 'run_version_skill', 'run_version_testcase'] as $table) {
            $rows = $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
            self::assertIsNumeric($rows);
            self::assertSame(0, (int) $rows, $table);
        }
        self::assertCount(1, $this->entityManager->getRepository(Skill::class)->findAll());
        self::assertCount(1, $this->entityManager->getRepository(Testcase::class)->findAll());
    }

    public function testSkillOverviewCountsOnlyRunsInAccessibleProjects(): void
    {
        $user = $this->persistUser('alice');
        $own = $this->persistProject('Own', [$user]);
        $hidden = $this->persistProject('Hidden');
        $skill = $this->persistSkill('Review', [$own, $hidden]);
        $this->persistRun($own, creator: $user, title: 'Visible one', skills: [$skill]);
        $this->persistRun($own, creator: $user, title: 'Visible two', skills: [$skill]);
        $this->persistRun($hidden, title: 'Hidden run', skills: [$skill]);

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/skills');
        self::assertSame('2', trim($crawler->filter('tbody tr:first-child td:nth-child(5)')->text()));

        $this->client->loginUser($this->persistUser('root', UserRole::Admin));
        $crawler = $this->client->request('GET', '/skills');
        self::assertSame('3', trim($crawler->filter('tbody tr:first-child td:nth-child(5)')->text()));
    }

    public function testRemovingAConfigurationTwiceFromAStalePageRemovesNothingElse(): void
    {
        $user = $this->persistUser('alice');
        $project = $this->persistProject('Security', [$user]);
        $run = $this->persistRun($project, creator: $user);
        $run->addConfiguration(new LlmConfiguration(Provider::OpenAi, 'gpt-5.4', Effort::High));
        $run->addConfiguration(new LlmConfiguration(Provider::ClaudeCode, 'opus'));
        $this->entityManager->flush();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/runs/'.$run->getUuid());
        $firstRemoval = $crawler->filter('form[action*="/configurations/"][action$="/remove"]')->first()->form();

        $this->client->submit($firstRemoval);
        $this->client->submit($firstRemoval);
        $this->client->followRedirect();

        self::assertSelectorTextContains('body', 'There is no such configuration.');
        $this->entityManager->clear();
        self::assertSame(['claude_code:opus:-'], array_map(static fn (LlmConfiguration $c): string => $c->key(), $this->findRun('Nightly')->getConfigurations()));
    }

    public function testTheRunDescriptionRemindsAboutPersonalData(): void
    {
        $admin = $this->persistUser('root', UserRole::Admin);
        $project = $this->persistProject('Security');
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/projects/'.$project->getUuid().'/runs/new');

        self::assertSame(1, substr_count($crawler->filter('body')->text(), Help::NO_PERSONAL_DATA));
    }

    private function firstResultOfVersionOne(): RunResult
    {
        return $this->findRun('Nightly')->findVersion(1)?->getResults()->first() ?: self::fail('no result');
    }

    private function findRun(string $title): Run
    {
        $run = $this->entityManager->getRepository(Run::class)->findOneBy(['title' => $title]);
        self::assertInstanceOf(Run::class, $run);

        return $run;
    }

    public function testRunDetailLeadsWithVersionsTileAndStartAndExplainsWhatIsMissing(): void
    {
        $user = $this->persistUser('alice');
        $project = $this->persistProject('Security', [$user]);
        $skill = $this->persistSkill('Review', [$project]);
        $testcase = $this->persistTestcase('T1', $project);
        $run = $this->persistRun($project, 'Empty', creator: $user);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/runs/'.$run->getUuid());
        self::assertSame('Versions', trim($crawler->filter('.stats .stat:first-child dt')->text()));
        self::assertSelectorExists('.stats .stat--wide form[action$="/start"]');
        self::assertSelectorTextContains('p.help-text', 'To start, add at least one testcase and one LLM configuration.');
        self::assertSame(['Skills', 'Testcases'], $crawler->filter('.split section h2')->each(static fn (Crawler $h): string => trim($h->text())));
        self::assertSelectorTextContains('.split', 'Only skills assigned to this run');

        $ready = $this->persistRun($project, 'Ready', [$skill], [$testcase], true, $user);
        $crawler = $this->client->request('GET', '/runs/'.$ready->getUuid());
        self::assertSame('0', trim($crawler->filter('.stats .stat--wide dd a')->text()));
        self::assertSame(['1', '1', '1'], $crawler->filter('.stats .stat:not(.stat--wide) dd')->each(static fn (Crawler $dd): string => trim($dd->text())));
        self::assertSelectorTextNotContains('body', 'To start, add');
    }

    public function testStartHintIgnoresDeletedTestcasesLikeTheStartCheck(): void
    {
        $user = $this->persistUser('alice');
        $project = $this->persistProject('Security', [$user]);
        $deleted = $this->persistTestcase('Gone', $project);
        $deleted->softDelete();
        $run = $this->persistRun($project, testcases: [$deleted], configured: true, creator: $user);
        $this->client->loginUser($user);

        $this->client->request('GET', '/runs/'.$run->getUuid());

        self::assertAnySelectorTextSame('p.help-text', 'To start, add at least one testcase.');
    }

    public function testConfigurationListShowsTheEffortNote(): void
    {
        $user = $this->persistUser('alice');
        $project = $this->persistProject('Security', [$user]);
        $run = $this->persistRun($project, creator: $user);
        $run->addConfiguration(new LlmConfiguration(Provider::HuggingFace, 'acme/chat-8b', Effort::High));
        $run->addConfiguration(new LlmConfiguration(Provider::DeepSeek, 'deepseek-chat', Effort::XHigh));
        $run->addConfiguration(new LlmConfiguration(Provider::ClaudeCode, 'opus', Effort::High));
        $this->entityManager->flush();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/runs/'.$run->getUuid());

        $rows = $crawler->filter('tbody tr')->each(static fn (Crawler $row): string => $row->filter('td')->first()->text());
        self::assertCount(3, $rows);
        self::assertStringContainsString('effort not supported', $rows[0]);
        self::assertStringContainsString('effort capped at high', $rows[1]);
        self::assertStringNotContainsString('effort capped', $rows[2]);
        self::assertStringNotContainsString('not supported', $rows[2]);
    }
}
