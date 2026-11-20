<?php

declare(strict_types=1);

namespace App\Tests\Versioning;

use App\Entity\Skill;
use App\Entity\Testcase;
use App\Versioning\VersionWorkflow;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class VersionWorkflowTest extends PhpUnitTestCase
{
    public function testRestoreCopiesTheOldVersionAndSaysSo(): void
    {
        $skill = new Skill('Review', null, 'one');
        $skill->saveFinal('Review', null, 'two');

        self::assertSame(['success', 'Version 1 restored as version 3.'], (new VersionWorkflow())->restore($skill, 1));
        self::assertSame('one', $skill->getActiveVersion()->getContent());
    }

    public function testRestoreWorksForTestcasesToo(): void
    {
        $testcase = new Testcase('Greeting', null, 'p1', 'o1');
        $testcase->saveFinal('Greeting', null, 'p2', 'o2');

        self::assertSame(['success', 'Version 1 restored as version 3.'], (new VersionWorkflow())->restore($testcase, 1));
    }

    public function testRestoreIsRefusedWhileADraftExistsAndForTheDraftItself(): void
    {
        $skill = new Skill('Review', null, 'one');
        $skill->saveFinal('Review', null, 'two');
        $skill->saveDraft('Review', null, 'three');

        foreach ([1, 3] as $number) {
            [$type, $message] = (new VersionWorkflow())->restore($skill, $number);
            self::assertSame('error', $type);
            self::assertStringContainsString('A draft exists', $message);
        }
        self::assertCount(3, $skill->getVersions());
    }

    public function testRestoreOfAnUnknownVersionIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);

        (new VersionWorkflow())->restore(new Skill('Review', null, 'one'), 9);
    }

    public function testDiscardRemovesTheDraftOrExplainsThereIsNone(): void
    {
        $skill = new Skill('Review', null, 'one');
        $workflow = new VersionWorkflow();

        self::assertSame(['error', 'There is no draft to discard.'], $workflow->discardDraft($skill));

        $skill->saveDraft('Review', null, 'two');
        self::assertSame(['success', 'Draft discarded.'], $workflow->discardDraft($skill));
        self::assertNull($skill->getDraft());
    }
}
