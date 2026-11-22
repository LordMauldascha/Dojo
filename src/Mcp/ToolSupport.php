<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Controller\ConflictMessage;
use App\Doctrine\SafeFlush;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * What every MCP tool needs: the signed-in user, the same access checks as the controllers (voters), validation and a safe flush.
 * Problems are thrown as ToolCallException, so the agent sees the message and can correct itself; any other exception is masked by the SDK.
 */
final readonly class ToolSupport
{
    public function __construct(
        private Security $security,
        private ValidatorInterface $validator,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function user(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new ToolCallException('Not signed in.');
        }

        return $user;
    }

    public function requireAdmin(): void
    {
        if (!$this->user()->isAdmin()) {
            throw new ToolCallException('Forbidden: this needs an administrator.');
        }
    }

    /** Same attribute as the controller action checks; unknown and forbidden are told apart like 404 and 403 in the UI. */
    public function grant(string $attribute, object $subject): void
    {
        if (!$this->security->isGranted($attribute, $subject)) {
            throw new ToolCallException('Forbidden: you may not do this.');
        }
    }

    /**
     * @template T of object
     *
     * @param ObjectRepository<T> $repository
     *
     * @return T
     */
    public function find(ObjectRepository $repository, string $uuid, string $what): object
    {
        $this->requireUuid($uuid, $what);

        return $repository->findOneBy(['uuid' => $uuid]) ?? throw new ToolCallException(\sprintf('%s not found.', $what));
    }

    public function requireUuid(string $uuid, string $what): void
    {
        if (!Uuid::isValid($uuid)) {
            throw new ToolCallException(\sprintf('"%s" is not a valid uuid for %s.', $uuid, lcfirst($what)));
        }
    }

    /** @param list<string>|null $groups */
    public function validate(object $subject, ?array $groups = null): void
    {
        $violations = $this->validator->validate($subject, null, $groups);
        if (0 === \count($violations)) {
            return;
        }

        $messages = [];
        foreach ($violations as $violation) {
            $path = $violation->getPropertyPath();
            $messages[] = ('' === $path ? '' : $path.': ').$violation->getMessage();
        }

        throw new ToolCallException(implode(' ', $messages));
    }

    /** Flushes in one transaction; when a concurrent change won, the agent is told to reload (the entity manager is closed then). */
    public function flush(?\Closure $after = null): void
    {
        if (!SafeFlush::run($this->entityManager, $after)) {
            throw new ToolCallException(ConflictMessage::TEXT);
        }
    }

    /** Aggregate columns of DQL rows come back as int or numeric string. */
    public function count(mixed $value): int
    {
        return \is_numeric($value) ? (int) $value : 0;
    }

    public function iso(?\DateTimeInterface $date): ?string
    {
        return $date?->format(\DATE_ATOM);
    }

    /** Free text of a tool call: trimmed, empty means none. */
    public function text(?string $value): ?string
    {
        $value = trim($value ?? '');

        return '' === $value ? null : $value;
    }
}
