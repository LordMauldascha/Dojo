<?php

declare(strict_types=1);

namespace App\Tests\Skill;

use App\Skill\SkillHeader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The cases are the same as in assets/js/tests/services/skillHeader.test.ts, so both implementations stay in step. */
final class SkillHeaderTest extends TestCase
{
    /** @return iterable<string, array{string, ?string}> */
    public static function names(): iterable
    {
        yield 'words' => ['Commit Writer', 'commit-writer'];
        yield 'umlauts and sharp s' => ['Über Größe', 'ueber-groesse'];
        yield 'upper case umlauts' => ['ÄÖÜ Straße', 'aeoeue-strasse'];
        yield 'blank runs and edges' => ["  a \t b  ", 'a-b'];
        yield 'hyphen and digits' => ['v2-b 3', 'v2-b-3'];
        yield 'empty' => ['', null];
        yield 'double hyphen' => ['a--b', null];
        yield 'leading hyphen' => ['-a', null];
        yield 'trailing hyphen' => ['a-', null];
        yield 'punctuation' => ['a,b', null];
        yield 'special characters' => ['a,._<>?!"§$%&/()=`´*+\'#b', null];
        yield 'other accents' => ['café', null];
    }

    #[DataProvider('names')]
    public function testName(string $title, ?string $expected): void
    {
        self::assertSame($expected, SkillHeader::name($title));
    }

    /** @return iterable<string, array{?string, ?string, string}> */
    public static function headers(): iterable
    {
        yield 'single line' => ['Commit Writer', 'Writes commits.', "---\nname: commit-writer\ndescription: Writes commits.\n---"];
        yield 'quotes inside stay plain' => ['a', 'Say "hi" now', "---\nname: a\ndescription: Say \"hi\" now\n---"];
        yield 'lines are trimmed and indented' => ['a', "Line one\n\n  line two  \r\nline three", "---\nname: a\ndescription: Line one\n  line two\n  line three\n---"];
        yield 'colon needs the block form' => ['a', "Use when: asked\nsecond", "---\nname: a\ndescription: >-\n  Use when: asked\n  second\n---"];
        yield 'comment sign needs the block form' => ['a', "one\n# two", "---\nname: a\ndescription: >-\n  one\n  # two\n---"];
        yield 'leading quote needs the block form' => ['a', '"quoted" start', "---\nname: a\ndescription: >-\n  \"quoted\" start\n---"];
        yield 'no description' => ['a', null, "---\nname: a\ndescription: \"\"\n---"];
        yield 'blank description' => ['a', " \n ", "---\nname: a\ndescription: \"\"\n---"];
        yield 'invalid title gives an empty name' => ['a,b', 'x', "---\nname: \ndescription: x\n---"];
        yield 'no title' => [null, 'x', "---\nname: \ndescription: x\n---"];
    }

    #[DataProvider('headers')]
    public function testRender(?string $title, ?string $description, string $expected): void
    {
        self::assertSame($expected, SkillHeader::render($title, $description));
    }
}
