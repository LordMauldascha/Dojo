# Features

Dojo is a web platform to create, evaluate, test and optimize LLM skills. People work in **projects**. A project has
members (users and groups) and owns the **skills** and **testcases** its members work with. A **run** combines skills,
testcases and LLM configurations of one project and is versioned every time it is started.

The whole interface is in English. Each feature has its own page in [features/](features/).

| Feature | What it covers |
| --- | --- |
| [Overview](features/overview.md) | Start page after login: own projects, recent runs, open drafts, admin summary |
| [Authentication and users](features/authentication-and-users.md) | Login, roles, user management, own account and password |
| [API keys](features/api-keys.md) | Provider credentials, stored encrypted, managed by admins |
| [Groups](features/groups.md) | Named sets of users that are assigned to projects |
| [Projects](features/projects.md) | The central unit: members, skills, testcases, runs |
| [Skills](features/skills.md) | SKILL.md texts with version history, assigned to projects |
| [Testcases](features/testcases.md) | Prompt and expected output with version history, belong to one project |
| [Runs](features/runs.md) | LLM configurations plus skills and testcases, snapshot on every start, version compare |
| [Versioning](features/versioning.md) | Draft and final versions of skills and testcases |
| [Access control](features/access-control.md) | Who may see and do what, and how it is enforced |
| [Overview tables](features/overview-tables.md) | Search, sorting and pagination shared by all lists |

## How the parts fit together

```text
User  ── n:m ──  Group
  │                │
  └─ n:m ─ Project ┘          access to a project: via a group or by direct assignment
              │
              ├── Run              exactly one project, hard-deleted with it
              │     ├── skills      (only skills of the run's project)
              │     ├── testcases   (only testcases of the run's project)
              │     └── versions    (snapshot on every start)
              ├── Skill     n:m    0 to n projects, 0 = admin only
              └── Testcase  n:1    0 or 1 project, none = admin only
```

Everything below a project inherits its access rules. Admins see and change everything, normal users only what belongs
to projects they have access to.

## Conventions

- URLs of groups, projects, skills, testcases and runs contain a random `uuid`, not the database id.
- Leaving a page with unsaved form changes asks for confirmation (a dialog for in-app links, the browser prompt otherwise); see
  [Design](Design.md#unsaved-changes).
- A form is submitted only once per page view: a double click or a second Enter press is ignored. Two people saving the same
  item at the same moment get a message to reload instead of an error page.
- Dates are shown as `DD.MM.YYYY HH:MM` in the Europe/Vienna time zone.
- Skills and testcases are never hard-deleted by users. Deleting hides them, their history stays.
- No personal data belongs into skills, testcases, run descriptions or any other free text: prompts are meant to be sent
  to LLM providers later. Use synthetic data.
- Starting a run creates a version only. Calling the LLMs and comparing their output is not built yet.

Console commands (create the first admin, import API keys) are described in [Commands.md](Commands.md).
