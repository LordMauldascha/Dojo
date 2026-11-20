<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Run;
use App\Entity\User;
use App\Repository\ProjectRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Runs have no own assignment: everything derives from access to the run's project.
 *
 * @extends Voter<string, Run>
 */
final class RunVoter extends Voter
{
    public const VIEW = 'RUN_VIEW';
    public const EDIT = 'RUN_EDIT';
    public const START = 'RUN_START';
    public const DELETE = 'RUN_DELETE';

    public function __construct(private readonly ProjectRepository $projects)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::EDIT, self::START, self::DELETE], true) && $subject instanceof Run;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        if (!$this->projects->isAccessibleBy($subject->getProject(), $user)) {
            return false;
        }

        return self::DELETE !== $attribute || $subject->getCreatedBy()?->getId() === $user->getId();
    }
}
