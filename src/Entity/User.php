<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'app_user')]
#[UniqueEntity(fields: ['username'], message: 'Dieser Benutzername ist bereits vergeben.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    /** Username of the in-memory administrator, must not be taken by a database user. */
    public const RESERVED_USERNAME = 'admin';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[a-z0-9._-]{3,64}$/', message: 'Erlaubt sind 3 bis 64 Zeichen: Kleinbuchstaben, Ziffern, ".", "_" und "-".')]
    #[Assert\NotEqualTo(value: self::RESERVED_USERNAME, message: 'Dieser Benutzername ist reserviert.')]
    private string $username = '';

    #[ORM\Column]
    private string $password = '';

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function setUsername(?string $username): void
    {
        $this->username = $username ?? '';
    }

    public function getUserIdentifier(): string
    {
        if ('' === $this->username) {
            throw new \LogicException('User has no username.');
        }

        return $this->username;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $hashedPassword): void
    {
        $this->password = $hashedPassword;
    }

    /** Keeps the password hash out of the session while still invalidating sessions on a password change. */
    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0".self::class."\0password"] = hash('crc32c', $this->password);

        return $data;
    }
}
