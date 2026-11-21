<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\HasTimestamps;
use App\Entity\Trait\HasTitleKey;
use App\Entity\Trait\HasUuid;
use App\Repository\GroupRepository;
use App\Validator\NoNulByte;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: GroupRepository::class)]
#[ORM\Table(name: 'app_group')]
#[UniqueEntity(fields: ['titleKey'], errorPath: 'title', message: 'This group title is already taken.')]
class Group
{
    use HasTimestamps;
    use HasTitleKey;
    use HasUuid;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    #[NoNulByte]
    private string $title = '';

    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'app_group_user')]
    #[ORM\JoinColumn(name: 'group_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'user_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $users;

    public function __construct()
    {
        $this->uuid = Uuid::v7();
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
