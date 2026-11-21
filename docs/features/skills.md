# Skills

A skill is an LLM skill: a title, a description and the text of its `SKILL.md`. Skills are versioned (see
[Versioning](versioning.md)), can be assigned to several [projects](projects.md) and are used by [runs](runs.md).

## Data

| Field | Rule | Versioned |
| --- | --- | --- |
| Title | required, at most 255 characters | yes |
| Description | optional, at most 2000 characters | yes |
| SKILL.md | required, at most 100 000 characters; stored exactly as typed (line breaks normalised, no trimming) | yes |
| Projects | 0 to n; the assignment only controls who may see and edit the skill | no |

Also stored: creator, created, updated and deleted time.

## Who sees and edits a skill

- Users see and edit skills that are assigned to at least one project they have access to.
- Admins see and edit all skills, including skills without a project (admin only).
- Creating: users must pick at least one project they have access to; admins may pick none.
- Changing the projects: users can only add or remove projects they have access to. Assignments to other projects stay
  untouched. A user cannot leave a skill without any project. If the skill is also in other projects, a user can remove their
  last accessible project; they then lose access to the skill and land on the overview.
- Deleting: the creator (while still having access) or an admin. If the creator account was deleted, only admins can
  delete the skill.

## Pages

| Page | URL | Purpose |
| --- | --- | --- |
| Overview | `/skills` | Search, sort, project filter; columns Title, Description, Projects, Version, Runs, Updated, Created |
| New skill | `/skills/new` | Create version 1 with title, description, SKILL.md and projects |
| Skill | `/skills/{uuid}` | Edit and save as draft or final, discard the draft, change projects, version history |
| Version | `/skills/{uuid}/versions/{number}` | Read-only view of one version |
| Delete | `/skills/{uuid}/delete` | Confirmation, then soft delete |

The overview searches the title and description of the active version and can be filtered with `?project=<uuid>`
(for non-admins only projects they can access count). The Projects column lists only projects the viewer can access.
The Runs column counts the runs that use the skill, limited to runs in projects the viewer can access. Shared behavior:
[Overview tables](overview-tables.md).

When the skill is also assigned to projects the user cannot see, the page says how many; changes apply there too. The edit form on the detail page starts from the draft if one exists, otherwise from the active version. The version
history lists all versions with their status, offers *View* and, when no draft exists, *Restore*.

## Deleting a skill

Deleting (button *Delete*) is a soft delete: the skill disappears from all lists, project pages and run assignment forms, but its
versions stay in the database so that old run versions can still show it (marked as deleted). The skill is removed from
all runs it was assigned to. There is no undelete in the interface.

## Related

[Versioning](versioning.md), [Runs](runs.md), [Projects](projects.md).
