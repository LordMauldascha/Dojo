# Testcases

A testcase is a prompt together with the output that is expected for it. Testcases are versioned (see
[Versioning](versioning.md)), belong to at most one [project](projects.md) and are selected on [runs](runs.md). They are
created without a run.

## Data

| Field | Rule | Versioned |
| --- | --- | --- |
| Title | required, at most 255 characters | yes |
| Description | optional, at most 2000 characters | yes |
| Prompt | required, at most 100 000 characters; stored exactly as typed | yes |
| Expected output | required, at most 100 000 characters; stored exactly as typed | yes |
| Project | 0 or 1; controls who may see and edit the testcase; no project means admin only | no |

Also stored: creator, created, updated and deleted time. The free-text fields remind users to use synthetic data and no
personal data, because prompts are later sent to LLM providers.

There is no separate admin-only flag. For an admin-only testcase use no project, or a project without groups and users.

## Who sees and edits a testcase

- Users see and edit testcases of projects they have access to.
- Admins see and edit all testcases, including those without a project.
- Creating: users must choose a project they have access to; admins may choose none.
- Changing the project: users can only move a testcase to a project they have access to and cannot clear the project.
  Admins can move it to any project or clear it.
- Deleting: the creator (while still having access) or an admin. If the creator account was deleted, only admins can
  delete the testcase.

## Pages

| Page | URL | Purpose |
| --- | --- | --- |
| Overview | `/testcases` | Search, sort, project filter; columns Title, Description, Project, Version, Updated, Created |
| New testcase | `/testcases/new` | Create version 1 with all fields and the project |
| Testcase | `/testcases/{uuid}` | Edit and save as draft or final, discard the draft, change the project, version history |
| Version | `/testcases/{uuid}/versions/{number}` | Read-only view of one version |
| Delete | `/testcases/{uuid}/delete` | Confirmation, then soft delete |

The Version column shows the newest version number, for example `3`, with a green dot when it is final and a yellow dot
when it is a draft (also when an earlier final version exists). The same column exists for skills. Search covers title
and description of the active version. See [Overview tables](overview-tables.md).

## Effects on runs and projects

- A testcase can be part of several runs, but only of runs in its own project. Moving it to another project or clearing
  the project removes it from the runs of the old project.
- Deleting a testcase (button *Delete*) is a soft delete (history stays, old run versions still show it as deleted) and removes it from
  all runs.
- Deleting a project does not delete its testcases; they lose the project and become admin only.

## Related

[Versioning](versioning.md), [Runs](runs.md), [Projects](projects.md).
