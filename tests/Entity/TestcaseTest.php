<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Project;
use App\Entity\Testcase;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;

final class TestcaseTest extends PhpUnitTestCase
{
    public function testCreationStoresFinalVersionOne(): void
    {
        $testcase = new Testcase('Greeting', 'Says hello', 'Say hello', 'Hello');

        self::assertSame('1', $testcase->getActiveVersion()->getLabel());
        self::assertNull($testcase->getDraft());
        self::assertCount(0, $testcase->getProjects());
        self::assertCount(1, $testcase->getVersions());
    }

    public function testDraftIsNumberedAfterTheLatestVersionAndOverwrittenInPlace(): void
    {
        $testcase = new Testcase('Greeting', null, 'prompt 1', 'output 1');

        $draft = $testcase->saveDraft('Greeting', null, 'prompt 2', 'output 2');
        self::assertSame('2-draft', $draft->getLabel());
        self::assertSame('1', $testcase->getActiveVersion()->getLabel());

        $same = $testcase->saveDraft('Greeting v2', 'desc', 'prompt 2b', 'output 2b');
        self::assertSame($draft, $same);
        self::assertSame('2-draft', $same->getLabel());
        self::assertSame('prompt 2b', $same->getPrompt());
        self::assertSame('output 2b', $same->getExpectedOutput());
        self::assertCount(2, $testcase->getVersions());
        self::assertSame('prompt 2b', $testcase->getEditableVersion()->getPrompt());
    }

    public function testSavingTheDraftAsFinalPromotesItAndBecomesActive(): void
    {
        $testcase = new Testcase('Greeting', null, 'prompt 1', 'output 1');
        $testcase->saveDraft('Greeting', null, 'prompt 2', 'output 2');

        $final = $testcase->saveFinal('Greeting', null, 'prompt 2 final', 'output 2 final');

        self::assertSame('2', $final->getLabel());
        self::assertSame($final, $testcase->getActiveVersion());
        self::assertNull($testcase->getDraft());
        self::assertCount(2, $testcase->getVersions());
        self::assertSame('3-draft', $testcase->saveDraft('Greeting', null, 'prompt 3', 'output 3')->getLabel());
    }

    public function testFinalSaveWithoutDraftCreatesNextVersion(): void
    {
        $testcase = new Testcase('Greeting', null, 'prompt 1', 'output 1');

        $final = $testcase->saveFinal('Greeting', null, 'prompt 2', 'output 2');

        self::assertSame('2', $final->getLabel());
        self::assertSame($final, $testcase->getActiveVersion());
    }

    public function testActiveVersionStaysTheLatestFinalWhileADraftExists(): void
    {
        $testcase = new Testcase('Greeting', null, 'prompt 1', 'output 1');
        $testcase->saveFinal('Greeting', null, 'prompt 2', 'output 2');
        $testcase->saveDraft('Greeting', null, 'prompt 3', 'output 3');

        self::assertSame('2', $testcase->getActiveVersion()->getLabel());
    }

    public function testDiscardingTheDraftKeepsTheActiveVersion(): void
    {
        $testcase = new Testcase('Greeting', null, 'prompt 1', 'output 1');
        $testcase->saveDraft('Greeting', null, 'prompt 2', 'output 2');

        $testcase->discardDraft();

        self::assertNull($testcase->getDraft());
        self::assertSame('1', $testcase->getActiveVersion()->getLabel());
        self::assertSame('2-draft', $testcase->saveDraft('Greeting', null, 'prompt 2 again', 'output 2')->getLabel());
    }

    public function testRestoreCopiesAnOldVersionAsNewFinalAndKeepsHistory(): void
    {
        $testcase = new Testcase('Greeting', null, 'prompt 1', 'output 1');
        $testcase->saveFinal('Greeting', null, 'prompt 2', 'output 2');

        $restored = $testcase->restore($testcase->findVersion(1) ?? self::fail());

        self::assertSame('3', $restored->getLabel());
        self::assertSame('prompt 1', $restored->getPrompt());
        self::assertSame('output 1', $restored->getExpectedOutput());
        self::assertSame($restored, $testcase->getActiveVersion());
        self::assertCount(3, $testcase->getVersions());
    }

    public function testRestoreIsRefusedWhileADraftExists(): void
    {
        $testcase = new Testcase('Greeting', null, 'prompt 1', 'output 1');
        $testcase->saveDraft('Greeting', null, 'prompt 2', 'output 2');

        $this->expectException(\LogicException::class);
        $testcase->restore($testcase->getActiveVersion());
    }

    public function testDraftCannotBeRestored(): void
    {
        $testcase = new Testcase('Greeting', null, 'prompt 1', 'output 1');
        $draft = $testcase->saveDraft('Greeting', null, 'prompt 2', 'output 2');

        $this->expectException(\InvalidArgumentException::class);
        $testcase->restore($draft);
    }

    public function testSoftDelete(): void
    {
        $testcase = new Testcase('Greeting', null, 'prompt 1', 'output 1');

        $testcase->softDelete();

        self::assertTrue($testcase->isDeleted());
    }

    public function testAssigningAndRemovingAProjectTouchesTheProject(): void
    {
        $project = new Project();
        $testcase = new Testcase('Greeting', null, 'prompt 1', 'output 1');
        $before = $project->getUpdatedAt();

        usleep(2000);
        $testcase->addProject($project);
        $afterAdding = $project->getUpdatedAt();
        usleep(2000);
        $testcase->removeProject($project);

        self::assertGreaterThan($before, $afterAdding);
        self::assertGreaterThan($afterAdding, $project->getUpdatedAt());
    }

    public function testReplacingProjectsKeepsTheAssignmentsTheEditorCannotChange(): void
    {
        $mine = new Project();
        $foreign = new Project();
        $testcase = new Testcase('Greeting', null, 'prompt 1', 'output 1');
        $testcase->addProject($mine);
        $testcase->addProject($foreign);

        self::assertTrue($testcase->replaceProjects([$mine], [], false));

        self::assertSame([$foreign], array_values($testcase->getProjects()->toArray()));
    }

    public function testReplacingProjectsRefusesToLeaveTheTestcaseWithoutAnyProjectUnlessAllowed(): void
    {
        $mine = new Project();
        $testcase = new Testcase('Greeting', null, 'prompt 1', 'output 1');
        $testcase->addProject($mine);

        self::assertFalse($testcase->replaceProjects([$mine], [], false));
        self::assertSame([$mine], array_values($testcase->getProjects()->toArray()));

        self::assertTrue($testcase->replaceProjects([$mine], [], true));
        self::assertCount(0, $testcase->getProjects());
    }
}
