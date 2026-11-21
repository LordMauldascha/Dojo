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
        yield 'reads as non-string: true' => ['a', 'true', "---\nname: a\ndescription: >-\n  true\n---"];
        yield 'reads as non-string: False' => ['a', 'False', "---\nname: a\ndescription: >-\n  False\n---"];
        yield 'reads as non-string: YES' => ['a', 'YES', "---\nname: a\ndescription: >-\n  YES\n---"];
        yield 'reads as non-string: no' => ['a', 'no', "---\nname: a\ndescription: >-\n  no\n---"];
        yield 'reads as non-string: On' => ['a', 'On', "---\nname: a\ndescription: >-\n  On\n---"];
        yield 'reads as non-string: off' => ['a', 'off', "---\nname: a\ndescription: >-\n  off\n---"];
        yield 'reads as non-string: null' => ['a', 'null', "---\nname: a\ndescription: >-\n  null\n---"];
        yield 'reads as non-string: ~' => ['a', '~', "---\nname: a\ndescription: >-\n  ~\n---"];
        yield 'reads as non-string: 1.5' => ['a', '1.5', "---\nname: a\ndescription: >-\n  1.5\n---"];
        yield 'reads as non-string: 0x1F' => ['a', '0x1F', "---\nname: a\ndescription: >-\n  0x1F\n---"];
        yield 'reads as non-string: 1e3' => ['a', '1e3', "---\nname: a\ndescription: >-\n  1e3\n---"];
        yield 'reads as non-string: .5' => ['a', '.5', "---\nname: a\ndescription: >-\n  .5\n---"];
        yield 'reads as non-string: 42' => ['a', '42', "---\nname: a\ndescription: >-\n  42\n---"];
        yield 'reads as non-string: -7' => ['a', '-7', "---\nname: a\ndescription: >-\n  -7\n---"];
        yield 'reads as non-string: +3' => ['a', '+3', "---\nname: a\ndescription: >-\n  +3\n---"];
        yield 'reads as non-string: 1_000' => ['a', '1_000', "---\nname: a\ndescription: >-\n  1_000\n---"];
        yield 'reads as non-string: 1:30' => ['a', '1:30', "---\nname: a\ndescription: >-\n  1:30\n---"];
        yield 'reads as non-string: .inf' => ['a', '.inf', "---\nname: a\ndescription: >-\n  .inf\n---"];
        yield 'plain text stays plain: truest' => ['a', 'truest', "---\nname: a\ndescription: truest\n---"];
        yield 'plain text stays plain: nothing' => ['a', 'nothing', "---\nname: a\ndescription: nothing\n---"];
        yield 'plain text stays plain: 1.5.2' => ['a', '1.5.2', "---\nname: a\ndescription: 1.5.2\n---"];
        yield 'plain text stays plain: 0x' => ['a', '0x', "---\nname: a\ndescription: 0x\n---"];
        yield 'plain text stays plain: 1e' => ['a', '1e', "---\nname: a\ndescription: 1e\n---"];
        yield 'plain text stays plain: Version 2' => ['a', 'Version 2', "---\nname: a\ndescription: Version 2\n---"];
        yield 'plain text stays plain: a1' => ['a', 'a1', "---\nname: a\ndescription: a1\n---"];
        yield 'plain text stays plain: null pointer' => ['a', 'null pointer', "---\nname: a\ndescription: null pointer\n---"];
        yield 'plain text stays plain: 12 monkeys' => ['a', '12 monkeys', "---\nname: a\ndescription: 12 monkeys\n---"];
        yield 'plain text stays plain: 3.14 is pi' => ['a', '3.14 is pi', "---\nname: a\ndescription: 3.14 is pi\n---"];
        yield 'folded lines are checked as one text' => ['a', "true\nfalse", "---\nname: a\ndescription: true\n  false\n---"];
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
