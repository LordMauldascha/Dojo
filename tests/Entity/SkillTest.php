<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Skill;
use PHPUnit\Framework\TestCase;

final class SkillTest extends TestCase
{
    public function testCreationStoresFinalVersionOne(): void
    {
        $skill = new Skill('Review', 'Reviews code', 'content 1');

        self::assertSame('1', $skill->getActiveVersion()->getLabel());
        self::assertNull($skill->getDraft());
        self::assertCount(1, $skill->getVersions());
    }

    public function testDraftIsNumberedAfterTheLatestVersionAndOverwrittenInPlace(): void
    {
        $skill = new Skill('Review', null, 'content 1');

        $draft = $skill->saveDraft('Review', null, 'content 2');
        self::assertSame('2-draft', $draft->getLabel());
        self::assertSame('1', $skill->getActiveVersion()->getLabel());

        $same = $skill->saveDraft('Review v2', 'desc', 'content 2b');
        self::assertSame($draft, $same);
        self::assertSame('2-draft', $same->getLabel());
        self::assertSame('content 2b', $same->getContent());
        self::assertCount(2, $skill->getVersions());
        self::assertSame('content 2b', $skill->getEditableVersion()->getContent());
    }

    public function testSavingTheDraftAsFinalPromotesItAndBecomesActive(): void
    {
        $skill = new Skill('Review', null, 'content 1');
        $skill->saveDraft('Review', null, 'content 2');

        $final = $skill->saveFinal('Review', null, 'content 2 final');

        self::assertSame('2', $final->getLabel());
        self::assertSame($final, $skill->getActiveVersion());
        self::assertNull($skill->getDraft());
        self::assertCount(2, $skill->getVersions());
        self::assertSame('3-draft', $skill->saveDraft('Review', null, 'content 3')->getLabel());
    }

    public function testFinalSaveWithoutDraftCreatesNextVersion(): void
    {
        $skill = new Skill('Review', null, 'content 1');

        $final = $skill->saveFinal('Review', null, 'content 2');

        self::assertSame('2', $final->getLabel());
        self::assertSame($final, $skill->getActiveVersion());
    }

    public function testActiveVersionStaysTheLatestFinalWhileADraftExists(): void
    {
        $skill = new Skill('Review', null, 'content 1');
        $skill->saveFinal('Review', null, 'content 2');
        $skill->saveDraft('Review', null, 'content 3');

        self::assertSame('2', $skill->getActiveVersion()->getLabel());
    }

    public function testDiscardingTheDraftKeepsTheActiveVersion(): void
    {
        $skill = new Skill('Review', null, 'content 1');
        $skill->saveDraft('Review', null, 'content 2');

        $skill->discardDraft();

        self::assertNull($skill->getDraft());
        self::assertSame('1', $skill->getActiveVersion()->getLabel());
        self::assertSame('2-draft', $skill->saveDraft('Review', null, 'content 2 again')->getLabel());
    }

    public function testRestoreCopiesAnOldVersionAsNewFinalAndKeepsHistory(): void
    {
        $skill = new Skill('Review', null, 'content 1');
        $skill->saveFinal('Review', null, 'content 2');

        $restored = $skill->restore($skill->findVersion(1) ?? self::fail());

        self::assertSame('3', $restored->getLabel());
        self::assertSame('content 1', $restored->getContent());
        self::assertSame($restored, $skill->getActiveVersion());
        self::assertCount(3, $skill->getVersions());
    }

    public function testRestoreIsRefusedWhileADraftExists(): void
    {
        $skill = new Skill('Review', null, 'content 1');
        $skill->saveDraft('Review', null, 'content 2');

        $this->expectException(\LogicException::class);
        $skill->restore($skill->getActiveVersion());
    }

    public function testDraftCannotBeRestored(): void
    {
        $skill = new Skill('Review', null, 'content 1');
        $draft = $skill->saveDraft('Review', null, 'content 2');

        $this->expectException(\InvalidArgumentException::class);
        $skill->restore($draft);
    }

    public function testSoftDeleteMarksTheSkill(): void
    {
        $skill = new Skill('Review', null, 'content 1');

        $skill->softDelete();

        self::assertTrue($skill->isDeleted());
    }
}
