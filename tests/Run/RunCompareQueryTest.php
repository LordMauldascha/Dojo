<?php

declare(strict_types=1);

namespace App\Tests\Run;

use App\Run\RunCompareQuery;
use PHPUnit\Framework\TestCase;

final class RunCompareQueryTest extends TestCase
{
    public function testDefaultsToTheLatestVersionAgainstTheOneBefore(): void
    {
        self::assertSame([3, 4], (new RunCompareQuery())->resolve(4));
    }

    public function testASingleVersionHasNothingToCompareAgainst(): void
    {
        self::assertSame([null, 1], (new RunCompareQuery())->resolve(1));
        self::assertSame([null, null], (new RunCompareQuery())->resolve(null));
    }

    public function testExplicitNumbersWin(): void
    {
        self::assertSame([1, 2], (new RunCompareQuery(from: 1, to: 2))->resolve(5));
        self::assertSame([4, 5], (new RunCompareQuery(from: 4))->resolve(5));
        self::assertSame([2, 3], (new RunCompareQuery(to: 3))->resolve(5));
    }
}
