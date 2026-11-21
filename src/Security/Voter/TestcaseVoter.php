<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Testcase;
use App\Repository\TestcaseRepository;

/** @extends VersionedEntityVoter<Testcase> */
final class TestcaseVoter extends VersionedEntityVoter
{
    public const VIEW = 'TESTCASE_VIEW';
    public const EDIT = 'TESTCASE_EDIT';
    public const DELETE = 'TESTCASE_DELETE';

    public function __construct(TestcaseRepository $repository)
    {
        parent::__construct($repository, Testcase::class, self::VIEW, self::EDIT, self::DELETE);
    }
}
