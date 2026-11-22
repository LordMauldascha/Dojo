<?php

declare(strict_types=1);

namespace App\Run\Judge;

/** Carries no message and no previous exception: both could hold the judge's answer, which may quote the output. */
final class InvalidJudgeAnswer extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The judge answer is not a valid rating.');
    }
}
