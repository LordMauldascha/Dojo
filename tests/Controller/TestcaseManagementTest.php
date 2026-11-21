<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Project;
use App\Entity\Testcase;
use App\Entity\User;
use App\Form\Help;
use App\Security\UserRole;
use App\Tests\Support\AppWebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class TestcaseManagementTest extends AppWebTestCase
{
    public function testAdminCreatesTestcaseWithoutProject(): void
    {
        $this->client->loginUser($this->persistUser('root', UserRole::Admin));

        $crawler = $this->client->request('GET', '/testcases/new');
        $this->submit($crawler, 'Create testcase', 'testcase_content', [
            'title' => 'Greeting',
            'description' => 'Says hello',
            'prompt' => 'Say hello',
            'expectedOutput' => 'Hello',
        ]);

        $testcase = $this->findTestcase('Greeting');
        self::assertResponseRedirects('/testcases/'.$testcase->getUuid());
        self::assertSame('1', $testcase->getActiveVersion()->getLabel());
        self::assertSame('Say hello', $testcase->getActiveVersion()->getPrompt());
        self::assertSame('Hello', $testcase->getActiveVersion()->getExpectedOutput());
        self::assertCount(0, $testcase->getProjects());
        self::assertSame('root', $testcase->getCreatedBy()?->getUsername());
    }

    public function testNormalUserMustSelectAccessibleProjectsAndMayPickSeveral(): void
    {
        $user = $this->persistUser('alice');
        $own = $this->persistProject('Own', [$user]);
        $second = $this->persistProject('Second', [$user]);
        $foreign = $this->persistProject('Foreign');
        $this->client->loginUser($user);
        $fields = ['title' => 'Greeting', 'prompt' => 'Say hello', 'expectedOutput' => 'Hello'];

        $crawler = $this->client->request('GET', '/testcases/new');
        $this->submit($crawler, 'Create testcase', 'testcase_content', $fields);
        self::assertResponseStatusCodeSame(422);

        $this->submit($crawler, 'Create testcase', 'testcase_content', $fields + ['projects' => [(string) $foreign->getId()]]);
        self::assertResponseStatusCodeSame(422);

        $this->submit($crawler, 'Create testcase', 'testcase_content', $fields + ['projects' => [(string) $own->getId(), (string) $second->getId()]]);
        self::assertResponseRedirects();
        $this->entityManager->clear();
        $testcase = $this->findTestcase('Greeting');
        self::assertSame(['Own', 'Second'], $this->projectTitles($testcase));
        self::assertSame('alice', $testcase->getCreatedBy()?->getUsername());
    }

    public function testPromptFieldWarnsAboutPersonalData(): void
    {
        $this->client->loginUser($this->persistUser('root', UserRole::Admin));

        $this->client->request('GET', '/testcases/new');

        self::assertSelectorTextContains('body', 'Use synthetic data, no personal data.');
    }

    public function testEditingFlowDraftFinalDiscardAndRestore(): void
    {
        $admin = $this->persistUser('root', UserRole::Admin);
        $testcase = $this->persistTestcase('Greeting', null, $admin, 'prompt 1', 'output 1');
        $path = '/testcases/'.$testcase->getUuid();
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', $path);
        self::assertNull($this->tokenOf($crawler, 'form[action*="/restore"]'), 'no restore button while only the active version exists');

        $this->submit($crawler, 'Save as draft', 'testcase_content', ['prompt' => 'prompt 2', 'expectedOutput' => 'output 2']);
        self::assertResponseRedirects($path);
        $crawler = $this->client->request('GET', $path);
        self::assertSelectorTextContains('body', 'Draft 2-draft exists');
        self::assertSame('prompt 2', $crawler->filter('textarea[name="testcase_content[prompt]"]')->text());

        $this->submit($crawler, 'Save as draft', 'testcase_content', ['prompt' => 'prompt 2b']);
        $this->entityManager->clear();
        $testcase = $this->findTestcase('Greeting');
        self::assertCount(2, $testcase->getVersions());
        self::assertSame('prompt 2b', $testcase->getDraft()?->getPrompt());
        self::assertSame('1', $testcase->getActiveVersion()->getLabel());

        $crawler = $this->client->request('GET', $path);
        self::assertSelectorTextContains('body', 'Draft 2-draft exists');
        $this->client->submit($crawler->filter('form[action$="/draft/discard"]')->form());
        self::assertResponseRedirects($path);
        $this->entityManager->clear();
        self::assertNull($this->findTestcase('Greeting')->getDraft());

        $crawler = $this->client->request('GET', $path);
        $this->submit($crawler, 'Save as final', 'testcase_content', ['prompt' => 'prompt 2 final', 'expectedOutput' => 'output 2 final']);
        $this->entityManager->clear();
        $testcase = $this->findTestcase('Greeting');
        self::assertSame('2', $testcase->getActiveVersion()->getLabel());
        self::assertSame('output 2 final', $testcase->getActiveVersion()->getExpectedOutput());

        $crawler = $this->client->request('GET', $path);
        $this->client->submit($crawler->filter('form[action*="/versions/1/restore"]')->form());
        self::assertResponseRedirects($path);
        $this->entityManager->clear();
        $testcase = $this->findTestcase('Greeting');
        self::assertSame('3', $testcase->getActiveVersion()->getLabel());
        self::assertSame('prompt 1', $testcase->getActiveVersion()->getPrompt());
        self::assertCount(3, $testcase->getVersions());
    }

    public function testInvalidFieldsAreRejectedForDraftAndFinal(): void
    {
        $admin = $this->persistUser('root', UserRole::Admin);
        $testcase = $this->persistTestcase('Greeting', null, $admin);
        $this->client->loginUser($admin);

        foreach (['Save as draft', 'Save as final'] as $button) {
            $crawler = $this->client->request('GET', '/testcases/'.$testcase->getUuid());
            $this->submit($crawler, $button, 'testcase_content', ['prompt' => '', 'expectedOutput' => '   ']);
            self::assertResponseStatusCodeSame(422);
        }

        $this->entityManager->clear();
        self::assertCount(1, $this->findTestcase('Greeting')->getVersions());
    }

    public function testEnterInTheTitleFieldCannotSubmitTheFormImplicitly(): void
    {
        $admin = $this->persistUser('root', UserRole::Admin);
        $testcase = $this->persistTestcase('Greeting', null, $admin);
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/testcases/'.$testcase->getUuid());

        $form = $crawler->filter('#testcase_content_title')->closest('form');
        self::assertInstanceOf(Crawler::class, $form);
        $buttons = $form->filter('button[type="submit"]');
        self::assertNotNull($buttons->first()->attr('disabled'), 'the default button must be disabled so Enter submits nothing');
        $enabled = $buttons->reduce(static fn (Crawler $button): bool => null === $button->attr('disabled'));
        self::assertSame(['Save as draft', 'Save as final'], $enabled->each(static fn (Crawler $button): string => trim($button->text())));
    }

    public function testRestoreIsRefusedWhileADraftExists(): void
    {
        $admin = $this->persistUser('root', UserRole::Admin);
        $testcase = $this->persistTestcase('Greeting', null, $admin);
        $testcase->saveFinal('Greeting', null, 'prompt 2', 'output 2');
        $this->entityManager->flush();
        $path = '/testcases/'.$testcase->getUuid();
        $this->client->loginUser($admin);
        $token = $this->tokenOf($this->client->request('GET', $path), 'form[action$="/versions/1/restore"]');
        self::assertNotNull($token);

        $testcase->saveDraft('Greeting', null, 'prompt 3', 'output 3');
        $this->entityManager->flush();
        $this->client->request('POST', $path.'/versions/1/restore', ['_token' => $token]);

        self::assertResponseRedirects($path);
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'A draft exists');
        $this->entityManager->clear();
        self::assertCount(3, $this->findTestcase('Greeting')->getVersions());
    }

    public function testRestoreAndDiscardNeedAValidCsrfToken(): void
    {
        $admin = $this->persistUser('root', UserRole::Admin);
        $testcase = $this->persistTestcase('Greeting', null, $admin);
        $testcase->saveFinal('Greeting', null, 'prompt 2', 'output 2');
        $this->entityManager->flush();
        $this->client->loginUser($admin);

        $this->client->request('POST', '/testcases/'.$testcase->getUuid().'/versions/1/restore', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/testcases/'.$testcase->getUuid().'/draft/discard', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testVersionPageShowsAnOldVersionAndUnknownVersionIs404(): void
    {
        $admin = $this->persistUser('root', UserRole::Admin);
        $testcase = $this->persistTestcase('Greeting', null, $admin, 'old prompt', 'old output');
        $testcase->saveFinal('Greeting', null, 'new prompt', 'new output');
        $this->entityManager->flush();
        $this->client->loginUser($admin);

        $this->client->request('GET', '/testcases/'.$testcase->getUuid().'/versions/1');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'old prompt');
        self::assertSelectorTextContains('body', 'old output');

        $this->client->request('GET', '/testcases/'.$testcase->getUuid().'/versions/9');
        self::assertResponseStatusCodeSame(404);
    }

    public function testUsersSeeAndEditViaDirectAssignmentOrGroupButOutsidersDoNot(): void
    {
        $direct = $this->persistUser('alice');
        $viaGroup = $this->persistUser('bob');
        $outsider = $this->persistUser('carol');
        $group = $this->persistGroup('Team one');
        $group->addUser($viaGroup);
        $project = $this->persistProject('Security', [$direct]);
        $project->addGroup($group);
        $testcase = $this->persistTestcase('Greeting', $project);
        $this->persistTestcase('Secret', null);
        $this->entityManager->flush();
        $path = '/testcases/'.$testcase->getUuid();

        foreach ([$direct, $viaGroup] as $user) {
            $this->client->loginUser($user);
            self::assertSame(['Greeting'], $this->titles($this->client->request('GET', '/testcases')));
            $crawler = $this->client->request('GET', $path);
            self::assertResponseIsSuccessful();
            $this->submit($crawler, 'Save as final', 'testcase_content', ['prompt' => 'edited by '.$user->getUsername()]);
            self::assertResponseRedirects($path);
        }

        $uuid = (string) $testcase->getUuid();
        $this->client->loginUser($outsider);
        self::assertSame([], $this->titles($this->client->request('GET', '/testcases')));
        foreach ([
            ['GET', '/testcases/'.$uuid],
            ['POST', '/testcases/'.$uuid],
            ['POST', '/testcases/'.$uuid.'/projects'],
            ['GET', '/testcases/'.$uuid.'/versions/1'],
            ['POST', '/testcases/'.$uuid.'/versions/1/restore'],
            ['POST', '/testcases/'.$uuid.'/draft/discard'],
            ['POST', '/testcases/'.$uuid.'/delete'],
        ] as [$method, $url]) {
            $this->client->request($method, $url);
            self::assertResponseStatusCodeSame(403, \sprintf('%s %s', $method, $url));
        }
        $this->entityManager->clear();
        self::assertSame('edited by bob', $this->findTestcase('Greeting')->getActiveVersion()->getPrompt());
    }

    public function testAdminSeesTestcasesWithoutProjectAndNormalUsersGetForbidden(): void
    {
        $testcase = $this->persistTestcase('Admin only', null);

        $this->client->loginUser($this->persistUser('root', UserRole::Admin));
        self::assertSame(['Admin only'], $this->titles($this->client->request('GET', '/testcases')));
        $this->client->request('GET', '/testcases/'.$testcase->getUuid());
        self::assertResponseIsSuccessful();

        $this->client->loginUser($this->persistUser('alice'));
        $this->client->request('GET', '/testcases/'.$testcase->getUuid());
        self::assertResponseStatusCodeSame(403);
    }

    public function testUnknownAndDeletedTestcasesReturn404AndAnonymousIsSentToLogin(): void
    {
        $admin = $this->persistUser('root', UserRole::Admin);
        $testcase = $this->persistTestcase('Gone', null, $admin);
        $testcase->softDelete();
        $this->entityManager->flush();
        $this->client->loginUser($admin);

        foreach (['/testcases/018f3a52-0000-7000-8000-000000000000', '/testcases/not-a-uuid', '/testcases/'.$testcase->getUuid()] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(404, $url);
        }
        $this->client->request('POST', '/testcases/'.$testcase->getUuid().'/delete');
        self::assertResponseStatusCodeSame(404);

        static::ensureKernelShutdown();
        $anonymous = static::createClient();
        $anonymous->request('GET', '/testcases');
        self::assertResponseRedirects('http://localhost/login');
    }

    public function testProjectAssignmentKeepsForeignProjectsAndProtectsTheLastOne(): void
    {
        $user = $this->persistUser('alice');
        $own = $this->persistProject('Own', [$user]);
        $other = $this->persistProject('Other');
        $shared = $this->persistTestcase('Shared', [$own, $other]);
        $onlyOwn = $this->persistTestcase('Only own', $own);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/testcases/'.$onlyOwn->getUuid());
        $this->submit($crawler, 'Save projects', 'testcase_projects', ['projects' => []]);
        self::assertResponseRedirects('/testcases/'.$onlyOwn->getUuid());
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'A testcase must keep at least one project.');
        $this->entityManager->clear();
        self::assertSame(['Own'], $this->projectTitles($this->findTestcase('Only own')));

        $crawler = $this->client->request('GET', '/testcases/'.$shared->getUuid());
        $this->submit($crawler, 'Save projects', 'testcase_projects', ['projects' => []]);
        self::assertResponseRedirects('/testcases');
        $this->entityManager->clear();
        self::assertSame(['Other'], $this->projectTitles($this->findTestcase('Shared')));
        $this->client->request('GET', '/testcases/'.$shared->getUuid());
        self::assertResponseStatusCodeSame(403);
    }

    public function testNormalUserCanAddSeveralOwnProjectsButNotAForeignOne(): void
    {
        $user = $this->persistUser('alice');
        $first = $this->persistProject('First', [$user]);
        $second = $this->persistProject('Second', [$user]);
        $foreign = $this->persistProject('Foreign');
        $testcase = $this->persistTestcase('Greeting', $first);
        $path = '/testcases/'.$testcase->getUuid();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', $path);
        $this->submit($crawler, 'Save projects', 'testcase_projects', ['projects' => [(string) $first->getId(), (string) $second->getId()]]);
        self::assertResponseRedirects($path);
        $this->entityManager->clear();
        self::assertSame(['First', 'Second'], $this->projectTitles($this->findTestcase('Greeting')));

        $crawler = $this->client->request('GET', $path);
        $this->submit($crawler, 'Save projects', 'testcase_projects', ['projects' => [(string) $first->getId(), (string) $foreign->getId()]]);
        self::assertResponseRedirects($path);
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'The projects could not be saved.');
        $this->entityManager->clear();
        self::assertSame(['First', 'Second'], $this->projectTitles($this->findTestcase('Greeting')));
    }

    public function testAdminCanClearAllProjects(): void
    {
        $admin = $this->persistUser('root', UserRole::Admin);
        $project = $this->persistProject('Security');
        $testcase = $this->persistTestcase('Greeting', $project);
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/testcases/'.$testcase->getUuid());
        $this->submit($crawler, 'Save projects', 'testcase_projects', ['projects' => []]);

        $this->entityManager->clear();
        self::assertSame([], $this->projectTitles($this->findTestcase('Greeting')));
    }

    public function testTheEditorIsToldAboutProjectsHeCannotSee(): void
    {
        $user = $this->persistUser('alice');
        $visible = $this->persistProject('Own', [$user]);
        $hidden = $this->persistProject('Hidden');
        $shared = $this->persistTestcase('Shared', [$visible, $hidden]);
        $own = $this->persistTestcase('Only own', $visible);
        $this->client->loginUser($user);

        $this->client->request('GET', '/testcases/'.$shared->getUuid());
        self::assertSelectorTextContains('body', 'also assigned to 1 project(s) you cannot see');

        $this->client->request('GET', '/testcases/'.$own->getUuid());
        self::assertSelectorTextNotContains('body', 'you cannot see');
    }

    public function testListShowsVisibleProjectsAndAMultiProjectTestcaseOnlyOnce(): void
    {
        $user = $this->persistUser('alice');
        $one = $this->persistProject('One', [$user]);
        $two = $this->persistProject('Two', [$user]);
        $hidden = $this->persistProject('Hidden');
        $this->persistTestcase('Shared', [$one, $two, $hidden]);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/testcases');
        self::assertSame(['Shared'], $this->titles($crawler));
        self::assertSame('One, Two', trim($crawler->filter('tbody tr td:nth-child(3)')->text()));

        self::assertSame(['Shared'], $this->titles($this->client->request('GET', '/testcases', ['project' => (string) $one->getUuid()])));
        self::assertSame([], $this->titles($this->client->request('GET', '/testcases', ['project' => (string) $hidden->getUuid()])));
    }

    public function testCreatorAndAdminMayDeleteOthersMayNot(): void
    {
        $creator = $this->persistUser('alice');
        $colleague = $this->persistUser('bob');
        $project = $this->persistProject('Security', [$creator, $colleague]);
        $mine = $this->persistTestcase('Mine', $project, $creator);
        $adminDeletes = $this->persistTestcase('Admin deletes', $project, $creator);

        $this->client->loginUser($colleague);
        $this->client->request('GET', '/testcases/'.$mine->getUuid().'/delete');
        self::assertResponseStatusCodeSame(405);
        $this->client->request('POST', '/testcases/'.$mine->getUuid().'/delete');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/testcases/'.$mine->getUuid());
        self::assertSelectorNotExists('form[action$="/delete"]');

        $this->client->loginUser($creator);
        $crawler = $this->client->request('GET', '/testcases/'.$mine->getUuid());
        self::assertResponseIsSuccessful();
        $this->client->request('POST', '/testcases/'.$mine->getUuid().'/delete', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        $this->client->submit($crawler->filter('form[action$="/delete"]')->form());
        self::assertResponseRedirects('/testcases');
        $this->entityManager->clear();
        self::assertTrue($this->findTestcase('Mine')->isDeleted());
        self::assertSame(['Admin deletes'], $this->titles($this->client->request('GET', '/testcases')));

        $this->client->loginUser($this->persistUser('root', UserRole::Admin));
        $crawler = $this->client->request('GET', '/testcases/'.$adminDeletes->getUuid());
        $this->client->submit($crawler->filter('form[action$="/delete"]')->form());
        self::assertResponseRedirects('/testcases');
        self::assertSame([], $this->titles($this->client->request('GET', '/testcases')));
    }

    public function testDeletingTheCreatorKeepsTheTestcaseAndOnlyAdminsCanDeleteIt(): void
    {
        $creator = $this->persistUser('alice');
        $colleague = $this->persistUser('bob');
        $project = $this->persistProject('Security', [$creator, $colleague]);
        $testcase = $this->persistTestcase('Orphan', $project, $creator);

        $this->entityManager->remove($creator);
        $this->entityManager->flush();
        $this->entityManager->clear();

        self::assertNull($this->findTestcase('Orphan')->getCreatedBy());
        $colleague = $this->entityManager->getRepository(User::class)->findOneBy(['username' => 'bob']);
        self::assertInstanceOf(User::class, $colleague);
        $this->client->loginUser($colleague);
        $this->client->request('GET', '/testcases/'.$testcase->getUuid());
        self::assertResponseIsSuccessful();
        $this->client->request('POST', '/testcases/'.$testcase->getUuid().'/delete');
        self::assertResponseStatusCodeSame(403);
    }

    public function testListSearchesActiveVersionSortsAndMarksPendingDrafts(): void
    {
        $admin = $this->persistUser('root', UserRole::Admin);
        $this->persistTestcase('Alpha', null, $admin, 'p', 'o', 'handles GDPR requests');
        $this->persistTestcase('Beta', null, $admin);
        $drafted = $this->persistTestcase('Gamma_x', null, $admin);
        $drafted->saveDraft('Draft title', 'draft description', 'p', 'o');
        $this->entityManager->flush();
        $this->client->loginUser($admin);

        self::assertSame(['Alpha'], $this->titles($this->client->request('GET', '/testcases', ['q' => 'gdpr'])));
        self::assertSame(['Gamma_x'], $this->titles($this->client->request('GET', '/testcases', ['q' => 'a_x'])));
        self::assertSame([], $this->titles($this->client->request('GET', '/testcases', ['q' => 'draft'])));
        self::assertSame([], $this->titles($this->client->request('GET', '/testcases', ['q' => '%'])));

        $crawler = $this->client->request('GET', '/testcases', ['sort' => 'title', 'dir' => 'desc']);
        self::assertSame(['Gamma_x', 'Beta', 'Alpha'], $this->titles($crawler));
        $versions = $crawler->filter('tbody tr td:nth-child(4)')->each(static fn (Crawler $cell): string => trim($cell->text()));
        self::assertSame(['2', '1', '1'], $versions);
        self::assertSame(['Draft', 'Final', 'Final'], $crawler->filter('tbody tr td:nth-child(4) .status-dot')->each(static fn (Crawler $dot): ?string => $dot->attr('aria-label')));
    }

    public function testListShowsTheProjectAndFiltersByAccessibleProject(): void
    {
        $user = $this->persistUser('alice');
        $own = $this->persistProject('Own', [$user]);
        $hidden = $this->persistProject('Hidden');
        $this->persistTestcase('Visible', $own);
        $this->persistTestcase('Hidden one', $hidden);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/testcases');
        self::assertSame(['Visible'], $this->titles($crawler));
        self::assertSame('Own', trim($crawler->filter('tbody tr td:nth-child(3)')->text()));

        self::assertSame(['Visible'], $this->titles($this->client->request('GET', '/testcases', ['project' => (string) $own->getUuid()])));
        self::assertSame([], $this->titles($this->client->request('GET', '/testcases', ['project' => (string) $hidden->getUuid()])));
        $this->client->request('GET', '/testcases', ['project' => 'not-a-uuid']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testListPaginatesOnlyAccessibleTestcasesAndKeepsTheProjectFilterInLinks(): void
    {
        $user = $this->persistUser('alice');
        $own = $this->persistProject('Own', [$user]);
        $other = $this->persistProject('Other');
        for ($i = 1; 25 >= $i; ++$i) {
            $this->persistTestcase(\sprintf('Testcase %02d', $i), 0 === $i % 2 ? $own : $other);
        }
        $this->client->loginUser($user);

        self::assertCount(10, $this->titles($this->client->request('GET', '/testcases')));
        $crawler = $this->client->request('GET', '/testcases', ['page' => 2, 'project' => (string) $own->getUuid()]);
        self::assertSame(['Testcase 22', 'Testcase 24'], $this->titles($crawler));
        self::assertSelectorTextContains('nav[aria-label="Pagination"]', 'Page 2 of 2 (12 entries)');
        $href = $crawler->filter('nav[aria-label="Pagination"] a[rel="prev"]')->attr('href');
        self::assertStringContainsString('project='.$own->getUuid(), (string) $href);
    }

    public function testPromptAndExpectedOutputAreStoredExactlyAsTyped(): void
    {
        $admin = $this->persistUser('root', UserRole::Admin);
        $testcase = $this->persistTestcase('Greeting', null, $admin);
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/testcases/'.$testcase->getUuid());
        $this->submit($crawler, 'Save as final', 'testcase_content', ['prompt' => "  indented prompt\n", 'expectedOutput' => "Done.\n"]);

        $this->entityManager->clear();
        $active = $this->findTestcase('Greeting')->getActiveVersion();
        self::assertSame("  indented prompt\n", $active->getPrompt());
        self::assertSame("Done.\n", $active->getExpectedOutput());
    }

    public function testBlankFieldsAndNullBytesAreRejected(): void
    {
        $admin = $this->persistUser('root', UserRole::Admin);
        $testcase = $this->persistTestcase('Greeting', null, $admin);
        $this->client->loginUser($admin);

        foreach ([['prompt' => " \n "], ['expectedOutput' => "a\0b"], ['title' => "a\0b"]] as $overrides) {
            $crawler = $this->client->request('GET', '/testcases/'.$testcase->getUuid());
            $this->submit($crawler, 'Save as draft', 'testcase_content', $overrides);
            self::assertResponseStatusCodeSame(422, (string) json_encode($overrides));
        }
    }

    public function testFreeTextFieldsRemindAboutPersonalData(): void
    {
        $this->client->loginUser($this->persistUser('root', UserRole::Admin));

        $crawler = $this->client->request('GET', '/testcases/new');

        self::assertSame(3, substr_count($crawler->filter('body')->text(), Help::NO_PERSONAL_DATA));
    }

    private function findTestcase(string $title): Testcase
    {
        foreach ($this->entityManager->getRepository(Testcase::class)->findAll() as $testcase) {
            if ($title === $testcase->getActiveVersion()->getTitle()) {
                return $testcase;
            }
        }
        self::fail('Testcase not found: '.$title);
    }

    public function testTestcaseDetailShowsTilesWithProjectCountAndRunCount(): void
    {
        $user = $this->persistUser('alice');
        $project = $this->persistProject('Own', [$user]);
        $second = $this->persistProject('Second', [$user]);
        $testcase = $this->persistTestcase('Greeting', [$project, $second]);
        $this->persistRun($project, 'Mine', [], [$testcase]);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/testcases/'.$testcase->getUuid());
        $tiles = $crawler->filter('.stats .stat dd')->each(static fn (Crawler $dd): string => trim($dd->text()));
        self::assertSame([$testcase->getActiveVersion()->getLabel(), '2', '1'], $tiles);
        self::assertSelectorTextContains('p.help-text', 'Members of these projects can see and edit this testcase.');

        $admin = $this->persistUser('root', UserRole::Admin);
        $loose = $this->persistTestcase('Loose');
        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/testcases/'.$loose->getUuid());
        self::assertSame('0', trim($crawler->filter('.stats .stat:nth-child(2) dd')->text()));
    }

    /** @return list<string> */
    private function projectTitles(Testcase $testcase): array
    {
        $titles = $testcase->getProjects()->map(static fn (Project $project): string => $project->getTitle())->toArray();
        sort($titles);

        return $titles;
    }
}
