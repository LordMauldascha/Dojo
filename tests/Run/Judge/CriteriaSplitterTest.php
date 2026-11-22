<?php

declare(strict_types=1);

namespace App\Tests\Run\Judge;

use App\Run\Judge\CriteriaSplitter;
use PHPUnit\Framework\TestCase;

final class CriteriaSplitterTest extends TestCase
{
    public function testLinesAreTrimmedAndEmptyLinesDropped(): void
    {
        self::assertSame(['a', 'b'], CriteriaSplitter::split("a\n\n  b  \n"));
    }

    public function testCrLfLinesAreSplit(): void
    {
        self::assertSame(['a', 'b'], CriteriaSplitter::split("a\r\nb\r\n"));
    }

    public function testBlankInputGivesNoCriteria(): void
    {
        self::assertSame([], CriteriaSplitter::split("\n \n"));
    }
}
