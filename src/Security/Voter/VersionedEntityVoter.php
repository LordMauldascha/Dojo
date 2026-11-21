<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Skill;
use App\Entity\Testcase;
use App\Entity\User;
use App\Repository\VersionedEntityRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Admins may do everything; others need access via a project, and only the creator may delete.
 *
 * @template T of Skill|Testcase
 *
 * @extends Voter<string, T>
 */
abstract class VersionedEntityVoter extends Voter
{
    /**
     * @param VersionedEntityRepository<T> $repository
     * @param class-string<T>              $subjectClass
     */
    public function __construct(
        private readonly VersionedEntityRepository $repository,
        private readonly string $subjectClass,
        private readonly string $view,
        private readonly string $edit,
        private readonly string $delete,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [$this->view, $this->edit, $this->delete], true) && $subject instanceof $this->subjectClass;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || $subject->isDeleted()) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        if (!$this->repository->isAccessibleBy($subject, $user)) {
            return false;
        }

        return $this->delete !== $attribute || CreatorCheck::isCreator($subject->getCreatedBy(), $user);
    }
}
