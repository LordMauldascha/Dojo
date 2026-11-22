<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Security\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(name: 'app:user:create', description: 'Create a database user (password is asked interactively)')]
final readonly class CreateUserCommand
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $hasher,
        private ValidatorInterface $validator,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        InputInterface $input,
        #[Argument(description: 'Username: 3-64 characters, a-z, 0-9, ".", "_", "-"')] string $username,
        #[Option(description: 'Create the user with ROLE_ADMIN')] bool $admin = false,
    ): int {
        if (!$input->isInteractive()) {
            $io->error('The password is asked interactively, run the command without --no-interaction.');

            return Command::FAILURE;
        }

        $user = new User();
        $user->setUsername($username);
        $user->setRole($admin ? UserRole::Admin : UserRole::User);

        $violations = $this->validator->validate($user);
        if (0 < \count($violations)) {
            return $this->fail($io, $violations);
        }

        $password = $io->askHidden('Password');
        $repeat = $io->askHidden('Repeat password');
        if (!\is_string($password) || '' === $password || $password !== $repeat) {
            $io->error('The passwords are empty or do not match.');

            return Command::FAILURE;
        }

        $user->setPassword($this->hasher->hashPassword($user, $password));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(\sprintf('User "%s" created with role %s.', $user->getUserIdentifier(), $user->getRole()->value));

        return Command::SUCCESS;
    }

    private function fail(SymfonyStyle $io, ConstraintViolationListInterface $violations): int
    {
        foreach ($violations as $violation) {
            $io->error((string) $violation->getMessage());
        }

        return Command::FAILURE;
    }
}
