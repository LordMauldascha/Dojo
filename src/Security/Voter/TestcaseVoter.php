<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Testcase;
use App\Entity\User;
use App\Repository\TestcaseRepository;
use App\Security\UserRole;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/** @extends Voter<string, Testcase> */
final class TestcaseVoter extends Voter
{
    public const VIEW = 'TESTCASE_VIEW';
    public const EDIT = 'TESTCASE_EDIT';
    public const DELETE = 'TESTCASE_DELETE';

    public function __construct(private readonly TestcaseRepository $testcases)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::EDIT, self::DELETE], true) && $subject instanceof Testcase;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || $subject->isDeleted()) {
            return false;
        }

        if (UserRole::Admin === $user->getRole()) {
            return true;
        }

        if (!$this->testcases->isAccessibleBy($subject, $user)) {
            return false;
        }

        return self::DELETE !== $attribute || $subject->getCreatedBy()?->getId() === $user->getId();
    }
}
