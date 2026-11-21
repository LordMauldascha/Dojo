<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Ai\Provider;
use App\Entity\ProviderApiKey;
use App\Security\UserRole;
use App\Tests\Support\AppWebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/** Every button that deletes something opens a modal first; the POST form with its CSRF token lives inside the dialog. */
final class ConfirmDialogTest extends AppWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->entityManager->createQuery('DELETE FROM '.ProviderApiKey::class)->execute();
    }

    public function testEveryDeleteButtonOpensADialogWithTheTokenizedForm(): void
    {
        $admin = $this->persistUser('root', UserRole::Admin);
        $this->persistUser('alice');
        $this->entityManager->persist(new ProviderApiKey(Provider::OpenAi, 'ciphertext', '1234'));
        $this->entityManager->flush();
        $group = $this->persistGroup('Team');
        $project = $this->persistProject('Security');
        $skill = $this->persistSkill('Review', [$project], $admin);
        $skill->saveDraft('Review draft', null, 'content');
        $testcase = $this->persistTestcase('Greeting', $project, $admin);
        $testcase->saveDraft('Greeting draft', null, 'prompt', 'output');
        $run = $this->persistRun($project);
        $this->entityManager->flush();
        $this->client->loginUser($admin);

        $expected = [
            '/admin/users' => 1,
            '/admin/api-keys' => 1,
            '/admin/groups/'.$group->getUuid() => 1,
            '/projects/'.$project->getUuid() => 1,
            '/skills/'.$skill->getUuid() => 2,
            '/testcases/'.$testcase->getUuid() => 2,
            '/runs/'.$run->getUuid() => 1,
        ];
        foreach ($expected as $path => $count) {
            $crawler = $this->client->request('GET', $path);

            self::assertResponseIsSuccessful($path);
            self::assertCount($count, $crawler->filter('button[command="show-modal"][commandfor]'), $path);
            self::assertCount($count, $crawler->filter('dialog[aria-labelledby]:not(#unsaved-dialog)'), $path);
            self::assertCount($count, $crawler->filter('dialog:not(#unsaved-dialog) form[method="post"] input[name="_token"]'), $path);
            self::assertCount($count, $crawler->filter('dialog:not(#unsaved-dialog) button[command="close"][autofocus]'), $path);
            $targets = $crawler->filter('button[command="show-modal"]')->each(static fn (Crawler $button): ?string => $button->attr('commandfor'));
            foreach ($targets as $target) {
                self::assertCount(1, $crawler->filter('dialog#'.$target), $path);
            }
        }
    }

    public function testEverySignedInPageCarriesTheUnsavedChangesDialog(): void
    {
        $admin = $this->persistUser('root', UserRole::Admin);
        $project = $this->persistProject('Security');
        $this->client->loginUser($admin);

        foreach (['/', '/projects', '/projects/'.$project->getUuid()] as $path) {
            $crawler = $this->client->request('GET', $path);

            self::assertResponseIsSuccessful($path);
            self::assertCount(1, $crawler->filter('dialog#unsaved-dialog button[command="close"][autofocus]'), $path);
            self::assertCount(1, $crawler->filter('dialog#unsaved-dialog button[data-unsaved-leave]'), $path);
        }
    }
}
