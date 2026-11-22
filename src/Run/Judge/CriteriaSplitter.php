<?php

declare(strict_types=1);

namespace App\Run\Judge;

/** Turns the output criteria of a testcase into the list the judge rates: one criterion per non-empty line. */
final class CriteriaSplitter
{
    /** @return list<string> */
    public static function split(string $outputCriteria): array
    {
        $lines = array_map(trim(...), preg_split('/\R/', $outputCriteria) ?: []);

        return array_values(array_filter($lines, static fn (string $line): bool => '' !== $line));
    }
}
