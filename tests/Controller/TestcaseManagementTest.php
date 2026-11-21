<?php

declare(strict_types=1);

namespace App\Tests\Controller;

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
        self::assertNull($testcase->getProject());
        self::assertSame('root', $testcase->getCreatedBy()?->getUsername());
    }

    public function testNormalUserMustSelectAnAccessibleProject(): void
    {
        $user = $this->persistUser('alice');
        $own = $this->persistProject('Own', [$user]);
        $foreign = $this->persistProject('Foreign');
        $this->client->loginUser($user);
        $fields = ['title' => 'Greeting', 'prompt' => 'Say hello', 'expectedOutput' => 'Hello'];

        $crawler = $this->client->request('GET', '/testcases/new');
        $this->submit($crawler, 'Create testcase', 'testcase_content', $fields + ['project' => '']);
        self::assertResponseStatusCodeSame(422);

        $this->submit($crawler, 'Create testcase', 'testcase_content', $fields + ['project' => (string) $foreign->getId()]);
        self::assertResponseStatusCodeSame(422);

        $this->submit($crawler, 'Create testcase', 'testcase_content', $fields + ['project' => (string) $own->getId()]);
        self::assertResponseRedirects();
        $this->entityManager->clear();
        $testcase = $this->findTestcase('Greeting');
        self::assertSame('Own', $testcase->getProject()?->getTitle());
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
            ['POST', '/testcases/'.$uuid.'/project'],
            ['GET', '/testcases/'.$uuid.'/versions/1'],
            ['POST', '/testcases/'.$uuid.'/versions/1/restore'],
            ['POST', '/testcases/'.$uuid.'/draft/discard'],
            ['GET', '/testcases/'.$uuid.'/delete'],
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

        foreach (['/testcases/018f3a52-0000-7000-8000-000000000000', '/testcases/not-a-uuid', '/testcases/'.$testcase->getUuid(), '/testcases/'.$testcase->getUuid().'/delete'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(404, $url);
        }

        static::ensureKernelShutdown();
        $anonymous = static::createClient();
        $anonymous->request('GET', '/testcases');
        self::assertResponseRedirects('http://localhost/login');
    }

    public function testNormalUserMovesBetweenOwnProjectsButNotToForeignOrNone(): void
    {
        $user = $this->persistUser('alice');
        $first = $this->persistProject('First', [$user]);
        $second = $this->persistProject('Second', [$user]);
        $foreign = $this->persistProject('Foreign');
        $testcase = $this->persistTestcase('Greeting', $first);
        $path = '/testcases/'.$testcase->getUuid();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', $path);
        $this->submit($crawler, 'Save project', 'testcase_project', ['project' => (string) $second->getId()]);
        self::assertResponseRedirects($path);
        $this->entityManager->clear();
        self::assertSame('Second', $this->findTestcase('Greeting')->getProject()?->getTitle());

        foreach ([(string) $foreign->getId(), ''] as $target) {
            $crawler = $this->client->request('GET', $path);
            $this->submit($crawler, 'Save project', 'testcase_project', ['project' => $target]);
            self::assertResponseRedirects($path);
            $this->client->followRedirect();
            self::assertSelectorTextContains('body', 'The project could not be saved.');
            $this->entityManager->clear();
            self::assertSame('Second', $this->findTestcase('Greeting')->getProject()?->getTitle());
        }
    }

    public function testAdminCanMoveAndClearTheProject(): void
    {
        $admin = $this->persistUser('root', UserRole::Admin);
        $project = $this->persistProject('Security');
        $testcase = $this->persistTestcase('Greeting', $project);
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/testcases/'.$testcase->getUuid());
        $this->submit($crawler, 'Save project', 'testcase_project', ['project' => '']);

        $this->entityManager->clear();
        self::assertNull($this->findTestcase('Greeting')->getProject());
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
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/testcases/'.$mine->getUuid().'/delete');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/testcases/'.$mine->getUuid());
        self::assertSelectorNotExists('a[href$="/delete"]');

        $this->client->loginUser($creator);
        $crawler = $this->client->request('GET', '/testcases/'.$mine->getUuid().'/delete');
        self::assertResponseIsSuccessful();
        $this->client->request('POST', '/testcases/'.$mine->getUuid().'/delete', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        $this->client->submit($crawler->selectButton('Delete')->form());
        self::assertResponseRedirects('/testcases');
        $this->entityManager->clear();
        self::assertTrue($this->findTestcase('Mine')->isDeleted());
        self::assertSame(['Admin deletes'], $this->titles($this->client->request('GET', '/testcases')));

        $this->client->loginUser($this->persistUser('root', UserRole::Admin));
        $crawler = $this->client->request('GET', '/testcases/'.$adminDeletes->getUuid().'/delete');
        $this->client->submit($crawler->selectButton('Delete')->form());
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
        $this->client->request('GET', '/testcases/'.$testcase->getUuid().'/delete');
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
}
