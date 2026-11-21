<?php

declare(strict_types=1);

namespace App\Entity\Trait;

use App\Entity\Project;
use Doctrine\Common\Collections\Collection;

/** Requires a mapped `$projects` collection and `touch()` (HasTimestamps) in the using class. */
trait HasProjects
{
    /**
     * Applies a project selection made within $editable (the projects the user may change); other assignments stay.
     * Returns false and changes nothing if that would leave the entity without any project and that is not allowed.
     *
     * @param list<Project> $editable
     * @param list<Project> $chosen
     */
    public function replaceProjects(array $editable, array $chosen, bool $mayBeEmpty): bool
    {
        $untouched = array_filter($this->projects->toArray(), static fn (Project $project): bool => !\in_array($project, $editable, true));
        if (!$mayBeEmpty && [] === $untouched && [] === $chosen) {
            return false;
        }

        foreach ($editable as $project) {
            \in_array($project, $chosen, true) ? $this->addProject($project) : $this->removeProject($project);
        }

        return true;
    }

    /** @return Collection<int, Project> */
    public function getProjects(): Collection
    {
        return $this->projects;
    }

    public function addProject(Project $project): void
    {
        if (!$this->projects->contains($project)) {
            $this->projects->add($project);
            $project->touch();
            $this->touch();
        }
    }

    public function removeProject(Project $project): void
    {
        if ($this->projects->removeElement($project)) {
            $project->touch();
            $this->touch();
        }
    }
}
