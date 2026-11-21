<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\HasTimestamps;
use App\Entity\Trait\HasTitleKey;
use App\Entity\Trait\HasUuid;
use App\Repository\ProjectRepository;
use App\Validator\NoNulByte;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ProjectRepository::class)]
#[ORM\Table(name: 'project')]
#[UniqueEntity(fields: ['titleKey'], errorPath: 'title', message: 'This project title is already taken.')]
class Project
{
    use HasTimestamps {
        touch as public;
    }
    use HasTitleKey;
    use HasUuid;

    public const TITLE_MAX = 255;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: self::TITLE_MAX)]
    #[Assert\NotBlank]
    #[Assert\Length(max: self::TITLE_MAX)]
    #[NoNulByte]
    private string $title = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 5000)]
    #[NoNulByte]
    private ?string $description = null;

    /** @var Collection<int, Group> */
    #[ORM\ManyToMany(targetEntity: Group::class)]
    #[ORM\JoinTable(name: 'project_group')]
    #[ORM\JoinColumn(name: 'project_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'group_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $groups;

    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'project_user')]
    #[ORM\JoinColumn(name: 'project_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'user_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $users;

    public function __construct()
    {
        $this->uuid = Uuid::v7();
        $this->groups = new ArrayCollection();
        $this->users = new ArrayCollection();
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $description = trim($description ?? '');
        $description = '' === $description ? null : $description;
        if ($description !== $this->description) {
            $this->description = $description;
            $this->touch();
        }
    }

    /** @return Collection<int, Group> */
    public function getGroups(): Collection
    {
        return $this->groups;
    }

    public function addGroup(Group $group): void
    {
        if (!$this->groups->contains($group)) {
            $this->groups->add($group);
            $this->touch();
        }
    }

    public function removeGroup(Group $group): void
    {
        if ($this->groups->removeElement($group)) {
            $this->touch();
        }
    }

    /** @return Collection<int, User> */
    public function getUsers(): Collection
    {
        return $this->users;
    }

    public function addUser(User $user): void
    {
        if (!$this->users->contains($user)) {
            $this->users->add($user);
            $this->touch();
        }
    }

    public function removeUser(User $user): void
    {
        if ($this->users->removeElement($user)) {
            $this->touch();
        }
    }
}
