<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Skill;
use App\Repository\SkillRepository;

/** @extends VersionedEntityVoter<Skill> */
final class SkillVoter extends VersionedEntityVoter
{
    public const VIEW = 'SKILL_VIEW';
    public const EDIT = 'SKILL_EDIT';
    public const DELETE = 'SKILL_DELETE';

    public function __construct(SkillRepository $repository)
    {
        parent::__construct($repository, Skill::class, self::VIEW, self::EDIT, self::DELETE);
    }
}
