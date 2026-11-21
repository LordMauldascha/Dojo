# Testcases

A testcase is a prompt together with the criteria its output must meet. Testcases are versioned (see
[Versioning](versioning.md)), belong to any number of [projects](projects.md) (none means admin only) and are selected on [runs](runs.md). They are
created without a run.

## Data

| Field | Rule | Versioned |
| --- | --- | --- |
| Title | required, at most 255 characters | yes |
| Description | optional, at most 2000 characters | yes |
| Prompt | required, at most 100 000 characters; stored exactly as typed | yes |
| Output criteria | required, at most 100 000 characters; stored exactly as typed | yes |
| Projects | 0 to n; the assignment only controls who may see and edit the testcase; no project means admin only; it is set on the testcase page, the project page shows only a count | no |

Output criteria describe what the output must meet (one criterion per line is a convention, not enforced). They are the
basis for the LLM judge that will rate run outputs. Admins choose its provider and model in the
[LLM configuration](llm-configuration.md); the judge itself does not run yet.

Also stored: creator, created, updated and deleted time. The free-text fields remind users to use synthetic data and no
personal data, because prompts are later sent to LLM providers.

There is no separate admin-only flag. For an admin-only testcase use no project, or projects without groups and users.

## Who sees and edits a testcase

- Users see and edit testcases that are assigned to at least one project they have access to.
- Admins see and edit all testcases, including those without a project.
- Creating: users must pick at least one project they have access to; admins may pick none.
- Changing the projects: users can only add or remove projects they have access to. Assignments to other projects stay
  untouched. A user cannot leave a testcase without any project. If the testcase is also in other projects, a user can
  remove their last accessible project; they then lose access to it and land on the overview. Admins can pick any
  projects or none.
- Deleting: the creator (while still having access) or an admin. If the creator account was deleted, only admins can
  delete the testcase.

## Pages

| Page | URL | Purpose |
| --- | --- | --- |
| Overview | `/testcases` | Search, sort, project filter; columns Title, Description, Projects, Version, Updated, Created (the Projects column lists only projects the viewer can access) |
| New testcase | `/testcases/new` | Create version 1 with all fields and the projects |
| Testcase | `/testcases/{uuid}` | Tiles (active version, project, runs using it), edit and save as draft or final, discard the draft, change the projects, version history |
| Version | `/testcases/{uuid}/versions/{number}` | Read-only view of one version |

The Version column shows the newest version number, for example `3`, with a green dot when it is final and a yellow dot
when it is a draft (also when an earlier final version exists). The same column exists for skills. Search covers title
and description of the active version. See [Overview tables](overview-tables.md).

## Effects on runs and projects

- A testcase can be part of several runs, but only of runs in one of its projects. Removing a project from the testcase
  removes it from the runs of that project.
- Deleting a testcase (button *Delete*) is a soft delete (history stays, old run versions still show it as deleted) and removes it from
  all runs.
- Deleting a project does not delete its testcases; they lose that project, and a testcase without any other project
  becomes admin only.

## Related

[Versioning](versioning.md), [Runs](runs.md), [Projects](projects.md).
