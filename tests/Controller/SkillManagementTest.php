<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Project;
use App\Entity\Skill;
use App\Entity\User;
use App\Form\Help;
use App\Repository\SkillRepository;
use App\Security\UserRole;
use App\Tests\Support\AppWebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class SkillManagementTest extends AppWebTestCase
{
    public function testAdminCreatesSkillWithoutProject(): void
    {
        $this->client->loginUser($this->persistUser('root', UserRole::Admin));

        $crawler = $this->client->request('GET', '/skills/new');
        $this->submit($crawler, 'Create skill', 'skill_content', ['title' => 'Code review', 'description' => 'Reviews code', 'content' => '# Review']);

        $skill = $this->findSkill('Code review');
        self::assertResponseRedirects('/skills/'.$skill->getUuid());
        self::assertSame('1', $skill->getActiveVersion()->getLabel());
        self::assertCount(0, $skill->getProjects());
        self::assertSame('root', $skill->getCreatedBy()?->getUsername());
    }

    public function testNormalUserMustSelectAnAccessibleProject(): void
    {
        $user = $this->persistUser('alice');
        $own = $this->persistProject('Own', [$user]);
        $foreign = $this->persistProject('Foreign');
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/skills/new');
        $this->submit($crawler, 'Create skill', 'skill_content', ['title' => 'No project', 'content' => 'x']);
        self::assertResponseStatusCodeSame(422);

        $this->submit($crawler, 'Create skill', 'skill_content', ['title' => 'Tampered', 'content' => 'x', 'projects' => [(string) $foreign->getId()]]);
        self::assertResponseStatusCodeSame(422);

        $this->submit($crawler, 'Create skill', 'skill_content', ['title' => 'Fine', 'content' => 'x', 'projects' => [(string) $own->getId()]]);
        self::assertResponseRedirects();
        $this->entityManager->clear();
        self::assertSame(['Own'], $this->projectTitles($this->findSkill('Fine')));
        self::assertNull($this->entityManager->getRepository(Skill::class)->findOneBy(['createdBy' => null]));
    }

    public function testEditingFlowDraftFinalDiscardAndRestore(): void
    {
        $admin = $this->persistUser('root', UserRole::Admin);
        $skill = $this->persistSkill('Review', [], $admin, 'content 1');
        $path = '/skills/'.$skill->getUuid();
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', $path);
        $restoreToken = $this->tokenOf($this->client->request('GET', $path), 'form[action*="/restore"]');
        self::assertNull($restoreToken, 'no restore button while only the active version exists');

        $this->submit($crawler, 'Save as draft', 'skill_content', ['content' => 'content 2']);
        self::assertResponseRedirects($path);
        $crawler = $this->client->request('GET', $path);
        self::assertSelectorTextContains('body', 'Draft 2-draft exists');
        self::assertSame('content 2', $crawler->filter('textarea[name="skill_content[content]"]')->text());

        $this->submit($crawler, 'Save as draft', 'skill_content', ['content' => 'content 2b']);
        $this->entityManager->clear();
        $skill = $this->findSkill('Review');
        self::assertCount(2, $skill->getVersions());
        self::assertSame('content 2b', $skill->getDraft()?->getContent());
        self::assertSame('1', $skill->getActiveVersion()->getLabel());

        $crawler = $this->client->request('GET', $path);
        $this->client->submit($crawler->filter('form[action$="/draft/discard"]')->form());
        self::assertResponseRedirects($path);
        $this->entityManager->clear();
        self::assertNull($this->findSkill('Review')->getDraft());

        $crawler = $this->client->request('GET', $path);
        $this->submit($crawler, 'Save as final', 'skill_content', ['content' => 'content 2 final']);
        $this->entityManager->clear();
        $skill = $this->findSkill('Review');
        self::assertSame('2', $skill->getActiveVersion()->getLabel());
        self::assertSame('content 2 final', $skill->getActiveVersion()->getContent());

        $crawler = $this->client->request('GET', $path);
        $this->client->submit($crawler->filter('form[action*="/versions/1/restore"]')->form());
        self::assertResponseRedirects($path);
        $this->entityManager->clear();
        $skill = $this->findSkill('Review');
        self::assertSame('3', $skill->getActiveVersion()->getLabel());
        self::assertSame('content 1', $skill->getActiveVersion()->getContent());
        self::assertCount(3, $skill->getVersions());
    }

    public function testEnterInTheTitleFieldCannotSubmitTheFormImplicitly(): void
    {
        $admin = $this->persistUser('root', UserRole::Admin);
        $skill = $this->persistSkill('Review', [], $admin);
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/skills/'.$skill->getUuid());

        $form = $crawler->filter('#skill_content_title')->closest('form');
        self::assertInstanceOf(Crawler::class, $form);
        $buttons = $form->filter('button[type="submit"]');
        self::assertNotNull($buttons->first()->attr('disabled'), 'the default button must be disabled so Enter submits nothing');
        $enabled = $buttons->reduce(static fn (Crawler $button): bool => null === $button->attr('disabled'));
        self::assertSame(['Save as draft', 'Save as final'], $enabled->each(static fn (Crawler $button): string => trim($button->text())));
    }

    public function testRestoreIsRefusedWhileADraftExists(): void
    {
        $admin = $this->persistUser('root', UserRole::Admin);
        $skill = $this->persistSkill('Review', [], $admin);
        $skill->saveFinal('Review', null, 'content 2');
        $this->entityManager->flush();
        $path = '/skills/'.$skill->getUuid();
        $this->client->loginUser($admin);
        $token = $this->tokenOf($this->client->request('GET', $path), 'form[action$="/versions/1/restore"]');
        self::assertNotNull($token);

        $skill->saveDraft('Review', null, 'content 3');
        $this->entityManager->flush();
        $this->client->request('POST', $path.'/versions/1/restore', ['_token' => $token]);

        self::assertResponseRedirects($path);
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'A draft exists');
        $this->entityManager->clear();
        self::assertCount(3, $this->findSkill('Review')->getVersions());
    }

    public function testRestoreAndDiscardNeedAValidCsrfToken(): void
    {
        $admin = $this->persistUser('root', UserRole::Admin);
        $skill = $this->persistSkill('Review', [], $admin);
        $skill->saveFinal('Review', null, 'content 2');
        $this->entityManager->flush();
        $this->client->loginUser($admin);

        $this->client->request('POST', '/skills/'.$skill->getUuid().'/versions/1/restore', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/skills/'.$skill->getUuid().'/draft/discard', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testVersionPageShowsAnOldVersionAndUnknownVersionIs404(): void
    {
        $admin = $this->persistUser('root', UserRole::Admin);
        $skill = $this->persistSkill('Review', [], $admin, 'old content');
        $skill->saveFinal('Review', null, 'new content');
        $this->entityManager->flush();
        $this->client->loginUser($admin);

        $this->client->request('GET', '/skills/'.$skill->getUuid().'/versions/1');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('pre', 'old content');

        $this->client->request('GET', '/skills/'.$skill->getUuid().'/versions/9');
        self::assertResponseStatusCodeSame(404);
    }

    public function testUsersSeeAndEditSkillsViaDirectAssignmentOrGroupButOutsidersDoNot(): void
    {
        $direct = $this->persistUser('alice');
        $viaGroup = $this->persistUser('bob');
        $outsider = $this->persistUser('carol');
        $group = $this->persistGroup('Team one');
        $group->addUser($viaGroup);
        $project = $this->persistProject('Security', [$direct]);
        $project->addGroup($group);
        $skill = $this->persistSkill('Review', [$project]);
        $this->persistSkill('Secret', []);
        $this->entityManager->flush();
        $path = '/skills/'.$skill->getUuid();

        foreach ([$direct, $viaGroup] as $user) {
            $this->client->loginUser($user);
            self::assertSame(['Review'], $this->titles($this->client->request('GET', '/skills')));
            $crawler = $this->client->request('GET', $path);
            self::assertResponseIsSuccessful();
            $this->submit($crawler, 'Save as final', 'skill_content', ['content' => 'edited by '.$user->getUsername()]);
            self::assertResponseRedirects($path);
        }

        $uuid = (string) $skill->getUuid();
        $this->client->loginUser($outsider);
        self::assertSame([], $this->titles($this->client->request('GET', '/skills')));
        foreach ([
            ['GET', '/skills/'.$uuid],
            ['POST', '/skills/'.$uuid],
            ['POST', '/skills/'.$uuid.'/projects'],
            ['GET', '/skills/'.$uuid.'/versions/1'],
            ['POST', '/skills/'.$uuid.'/versions/1/restore'],
            ['POST', '/skills/'.$uuid.'/draft/discard'],
            ['POST', '/skills/'.$uuid.'/delete'],
        ] as [$method, $url]) {
            $this->client->request($method, $url);
            self::assertResponseStatusCodeSame(403, \sprintf('%s %s', $method, $url));
        }
        $this->entityManager->clear();
        self::assertSame('edited by bob', $this->findSkill('Review')->getActiveVersion()->getContent());
    }

    public function testAdminSeesSkillsWithoutProjectAndNormalUsersGetForbidden(): void
    {
        $skill = $this->persistSkill('Admin only', []);

        $this->client->loginUser($this->persistUser('root', UserRole::Admin));
        self::assertSame(['Admin only'], $this->titles($this->client->request('GET', '/skills')));
        $this->client->request('GET', '/skills/'.$skill->getUuid());
        self::assertResponseIsSuccessful();

        $this->client->loginUser($this->persistUser('alice'));
        $this->client->request('GET', '/skills/'.$skill->getUuid());
        self::assertResponseStatusCodeSame(403);
    }

    public function testUnknownAndDeletedSkillsReturn404AndAnonymousIsSentToLogin(): void
    {
        $admin = $this->persistUser('root', UserRole::Admin);
        $skill = $this->persistSkill('Gone', [], $admin);
        $skill->softDelete();
        $this->entityManager->flush();
        $this->client->loginUser($admin);

        foreach (['/skills/018f3a52-0000-7000-8000-000000000000', '/skills/not-a-uuid', '/skills/'.$skill->getUuid()] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(404, $url);
        }
        $this->client->request('POST', '/skills/'.$skill->getUuid().'/delete');
        self::assertResponseStatusCodeSame(404);

        static::ensureKernelShutdown();
        $anonymous = static::createClient();
        $anonymous->request('GET', '/skills');
        self::assertResponseRedirects('http://localhost/login');
    }

    public function testProjectAssignmentKeepsForeignProjectsAndProtectsTheLastOne(): void
    {
        $user = $this->persistUser('alice');
        $own = $this->persistProject('Own', [$user]);
        $other = $this->persistProject('Other');
        $skill = $this->persistSkill('Review', [$own, $other]);
        $onlyOwn = $this->persistSkill('Only own', [$own]);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/skills/'.$onlyOwn->getUuid());
        $this->submit($crawler, 'Save projects', 'skill_projects', ['projects' => []]);
        self::assertResponseRedirects('/skills/'.$onlyOwn->getUuid());
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'A skill must keep at least one project.');
        $this->entityManager->clear();
        self::assertSame(['Own'], $this->projectTitles($this->findSkill('Only own')));

        $crawler = $this->client->request('GET', '/skills/'.$skill->getUuid());
        $this->submit($crawler, 'Save projects', 'skill_projects', ['projects' => []]);
        self::assertResponseRedirects('/skills');
        $this->entityManager->clear();
        self::assertSame(['Other'], $this->projectTitles($this->findSkill('Review')));
        $this->client->request('GET', '/skills/'.$skill->getUuid());
        self::assertResponseStatusCodeSame(403);
    }

    public function testNormalUserCannotAssignAnInaccessibleProject(): void
    {
        $user = $this->persistUser('alice');
        $own = $this->persistProject('Own', [$user]);
        $foreign = $this->persistProject('Foreign');
        $skill = $this->persistSkill('Review', [$own]);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/skills/'.$skill->getUuid());
        $this->submit($crawler, 'Save projects', 'skill_projects', ['projects' => [(string) $own->getId(), (string) $foreign->getId()]]);

        self::assertResponseRedirects('/skills/'.$skill->getUuid());
        $this->entityManager->clear();
        self::assertSame(['Own'], $this->projectTitles($this->findSkill('Review')));
    }

    public function testAdminCanChangeProjectsAndRemoveTheLastOne(): void
    {
        $admin = $this->persistUser('root', UserRole::Admin);
        $project = $this->persistProject('Security');
        $skill = $this->persistSkill('Review', [$project]);
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/skills/'.$skill->getUuid());
        $this->submit($crawler, 'Save projects', 'skill_projects', ['projects' => []]);

        $this->entityManager->clear();
        self::assertSame([], $this->projectTitles($this->findSkill('Review')));
    }

    public function testCreatorAndAdminMayDeleteOthersMayNot(): void
    {
        $creator = $this->persistUser('alice');
        $colleague = $this->persistUser('bob');
        $project = $this->persistProject('Security', [$creator, $colleague]);
        $mine = $this->persistSkill('Mine', [$project], $creator);
        $adminSkill = $this->persistSkill('Admin deletes', [$project], $creator);

        $this->client->loginUser($colleague);
        $this->client->request('GET', '/skills/'.$mine->getUuid().'/delete');
        self::assertResponseStatusCodeSame(405);
        $this->client->request('POST', '/skills/'.$mine->getUuid().'/delete');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/skills/'.$mine->getUuid());
        self::assertSelectorNotExists('form[action$="/delete"]');

        $this->client->loginUser($creator);
        $crawler = $this->client->request('GET', '/skills/'.$mine->getUuid());
        self::assertResponseIsSuccessful();
        $this->client->request('POST', '/skills/'.$mine->getUuid().'/delete', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        $this->client->submit($crawler->filter('form[action$="/delete"]')->form());
        self::assertResponseRedirects('/skills');
        $this->entityManager->clear();
        self::assertTrue($this->findSkill('Mine')->isDeleted());
        self::assertSame(['Admin deletes'], $this->titles($this->client->request('GET', '/skills')));

        $this->client->loginUser($this->persistUser('root', UserRole::Admin));
        $crawler = $this->client->request('GET', '/skills/'.$adminSkill->getUuid());
        $this->client->submit($crawler->filter('form[action$="/delete"]')->form());
        self::assertResponseRedirects('/skills');
        self::assertSame([], $this->titles($this->client->request('GET', '/skills')));
    }

    public function testDeletingTheCreatorKeepsTheSkillAndOnlyAdminsCanDeleteIt(): void
    {
        $creator = $this->persistUser('alice');
        $colleague = $this->persistUser('bob');
        $project = $this->persistProject('Security', [$creator, $colleague]);
        $skill = $this->persistSkill('Orphan', [$project], $creator);

        $this->entityManager->remove($creator);
        $this->entityManager->flush();
        $this->entityManager->clear();

        self::assertNull($this->findSkill('Orphan')->getCreatedBy());
        $colleague = $this->entityManager->getRepository(User::class)->findOneBy(['username' => 'bob']);
        self::assertInstanceOf(User::class, $colleague);
        $this->client->loginUser($colleague);
        $this->client->request('GET', '/skills/'.$skill->getUuid());
        self::assertResponseIsSuccessful();
        $this->client->request('POST', '/skills/'.$skill->getUuid().'/delete');
        self::assertResponseStatusCodeSame(403);
    }

    public function testListSearchesActiveVersionSortsAndKeepsDraftsOutOfIt(): void
    {
        $admin = $this->persistUser('root', UserRole::Admin);
        $this->persistSkill('Alpha', [], $admin, 'x', 'handles GDPR requests');
        $this->persistSkill('Beta', [], $admin);
        $drafted = $this->persistSkill('Gamma_x', [], $admin);
        $drafted->saveDraft('Draft title', 'draft description', 'x');
        $this->entityManager->flush();
        $this->client->loginUser($admin);

        self::assertSame(['Alpha'], $this->titles($this->client->request('GET', '/skills', ['q' => 'gdpr'])));
        self::assertSame(['Gamma_x'], $this->titles($this->client->request('GET', '/skills', ['q' => 'a_x'])));
        self::assertSame([], $this->titles($this->client->request('GET', '/skills', ['q' => 'draft'])));
        self::assertSame([], $this->titles($this->client->request('GET', '/skills', ['q' => '%'])));

        $crawler = $this->client->request('GET', '/skills', ['sort' => 'title', 'dir' => 'desc']);
        self::assertSame(['Gamma_x', 'Beta', 'Alpha'], $this->titles($crawler));
        self::assertSame(['2', '1', '1'], $crawler->filter('tbody tr td:nth-child(4)')->each(static fn (Crawler $cell): string => trim($cell->text())));
        self::assertSame(['Draft', 'Final', 'Final'], $crawler->filter('tbody tr td:nth-child(4) .status-dot')->each(static fn (Crawler $dot): ?string => $dot->attr('aria-label')));
    }

    public function testListShowsOnlyAccessibleProjectTitlesAndFiltersByProject(): void
    {
        $user = $this->persistUser('alice');
        $own = $this->persistProject('Own', [$user]);
        $hidden = $this->persistProject('Hidden');
        $this->persistSkill('Shared', [$own, $hidden]);
        $this->persistSkill('Hidden only', [$hidden]);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/skills');
        self::assertSame(['Shared'], $this->titles($crawler));
        self::assertSame('Own', trim($crawler->filter('tbody tr td:nth-child(3)')->text()));

        self::assertSame(['Shared'], $this->titles($this->client->request('GET', '/skills', ['project' => (string) $own->getUuid()])));
        self::assertSame([], $this->titles($this->client->request('GET', '/skills', ['project' => (string) $hidden->getUuid()])));
        $this->client->request('GET', '/skills', ['project' => 'not-a-uuid']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testListPaginatesOnlyAccessibleSkillsAndKeepsTheProjectFilterInLinks(): void
    {
        $user = $this->persistUser('alice');
        $own = $this->persistProject('Own', [$user]);
        $other = $this->persistProject('Other');
        for ($i = 1; 25 >= $i; ++$i) {
            $this->persistSkill(\sprintf('Skill %02d', $i), [0 === $i % 2 ? $own : $other]);
        }
        $this->client->loginUser($user);

        self::assertCount(10, $this->titles($this->client->request('GET', '/skills')));
        $crawler = $this->client->request('GET', '/skills', ['page' => 2, 'project' => (string) $own->getUuid()]);
        self::assertSame(['Skill 22', 'Skill 24'], $this->titles($crawler));
        self::assertSelectorTextContains('nav[aria-label="Pagination"]', 'Page 2 of 2 (12 entries)');
        $href = $crawler->filter('nav[aria-label="Pagination"] a[rel="prev"]')->attr('href');
        self::assertStringContainsString('project='.$own->getUuid(), (string) $href);
    }

    public function testContentIsStoredExactlyAsTyped(): void
    {
        $admin = $this->persistUser('root', UserRole::Admin);
        $skill = $this->persistSkill('Review', [], $admin);
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/skills/'.$skill->getUuid());
        $this->submit($crawler, 'Save as final', 'skill_content', ['content' => "  indented\nsecond line\n"]);

        $this->entityManager->clear();
        self::assertSame("  indented\nsecond line\n", $this->findSkill('Review')->getActiveVersion()->getContent());
    }

    public function testBlankContentAndNullBytesAreRejected(): void
    {
        $admin = $this->persistUser('root', UserRole::Admin);
        $skill = $this->persistSkill('Review', [], $admin);
        $this->client->loginUser($admin);

        foreach ([['content' => "   \n  "], ['content' => "a\0b"], ['title' => "a\0b"], ['description' => "a\0b"]] as $overrides) {
            $crawler = $this->client->request('GET', '/skills/'.$skill->getUuid());
            $this->submit($crawler, 'Save as draft', 'skill_content', $overrides);
            self::assertResponseStatusCodeSame(422, (string) json_encode($overrides));
        }

        $this->entityManager->clear();
        self::assertCount(1, $this->findSkill('Review')->getVersions());
    }

    public function testSkillsWithTheSameTitleKeepTheirCreationOrder(): void
    {
        $project = $this->persistProject('Security');
        $ids = [];
        for ($i = 0; 3 > $i; ++$i) {
            $ids[] = $this->persistSkill('Review', [$project])->getId();
        }

        $found = array_map(static fn (Skill $skill): ?int => $skill->getId(), static::getContainer()->get(SkillRepository::class)->findByProject($project));

        self::assertSame($ids, $found);
    }

    public function testTheEditorIsToldAboutProjectsHeCannotSee(): void
    {
        $user = $this->persistUser('alice');
        $visible = $this->persistProject('Own', [$user]);
        $hidden = $this->persistProject('Hidden');
        $shared = $this->persistSkill('Shared', [$visible, $hidden]);
        $own = $this->persistSkill('Only own', [$visible]);
        $this->client->loginUser($user);

        $this->client->request('GET', '/skills/'.$shared->getUuid());
        self::assertSelectorTextContains('body', 'also assigned to 1 project(s) you cannot see');

        $this->client->request('GET', '/skills/'.$own->getUuid());
        self::assertSelectorTextNotContains('body', 'you cannot see');
    }

    public function testAnInvalidCsrfTokenIsRefusedWithoutLoggingTheUserOut(): void
    {
        $admin = $this->persistUser('root', UserRole::Admin);
        $skill = $this->persistSkill('Review', [], $admin);
        $this->client->loginUser($admin);

        $this->client->request('POST', '/skills/'.$skill->getUuid().'/draft/discard', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/skills');
        self::assertResponseIsSuccessful();
    }

    public function testFreeTextFieldsRemindAboutPersonalData(): void
    {
        $this->client->loginUser($this->persistUser('root', UserRole::Admin));

        $crawler = $this->client->request('GET', '/skills/new');

        self::assertSame(2, substr_count($crawler->filter('body')->text(), Help::NO_PERSONAL_DATA));
    }

    public function testAnOversizedVersionNumberIsNotFound(): void
    {
        $admin = $this->persistUser('root', UserRole::Admin);
        $skill = $this->persistSkill('Review', [], $admin);
        $this->client->loginUser($admin);

        $this->client->request('GET', '/skills/'.$skill->getUuid().'/versions/99999999999');

        self::assertResponseStatusCodeSame(404);
    }

    /** @return list<string> */
    private function projectTitles(Skill $skill): array
    {
        $titles = $skill->getProjects()->map(static fn (Project $project): string => $project->getTitle())->toArray();
        sort($titles);

        return $titles;
    }

    private function findSkill(string $title): Skill
    {
        foreach ($this->entityManager->getRepository(Skill::class)->findAll() as $skill) {
            if ($title === $skill->getActiveVersion()->getTitle()) {
                return $skill;
            }
        }
        self::fail('Skill not found: '.$title);
    }
}
