<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Project;
use App\Entity\User;
use App\Repository\ProjectRepository;
use App\Security\UserRole;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/** @extends Voter<string, Project> */
final class ProjectVoter extends Voter
{
    public const VIEW = 'PROJECT_VIEW';
    public const MANAGE = 'PROJECT_MANAGE';

    public function __construct(private readonly ProjectRepository $projects)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::MANAGE], true) && $subject instanceof Project;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        if (UserRole::Admin === $user->getRole()) {
            return true;
        }

        return self::VIEW === $attribute && $this->projects->isAccessibleBy($subject, $user);
    }
}
