# Features

Dojo is a web platform to create, evaluate, test and optimize LLM skills. People work in **projects**. A project has
members (users and groups) and owns the **skills** and **testcases** its members work with. A **run** combines skills,
testcases and LLM configurations of one project and is versioned every time it is started.

The whole interface is in English. Each feature has its own page in [features/](features/).

| Feature | What it covers |
| --- | --- |
| [Overview](features/overview.md) | Start page after login: own projects, recent runs, open drafts, admin summary |
| [Authentication and users](features/authentication-and-users.md) | Login, roles, user management, own account and password |
| [LLM configuration](features/llm-configuration.md) | Provider credentials (stored encrypted), Hugging Face model discovery, the LLM judge model and the effort mapping, managed by admins |
| [Groups](features/groups.md) | Named sets of users that are assigned to projects |
| [Projects](features/projects.md) | The central unit: members, skills, testcases, runs |
| [Skills](features/skills.md) | Skill instructions with generated SKILL.md header, version history, assigned to projects |
| [Testcases](features/testcases.md) | Prompt and output criteria (one per line) with version history, assigned to projects |
| [Runs](features/runs.md) | LLM configurations plus skills and testcases; every start snapshots, executes (LLM calls, judge, score) and shows a result matrix; version compare |
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
              │     └── versions    (snapshot on every start, with one result per testcase and configuration)
              ├── Skill     n:m    0 to n projects, 0 = admin only
              └── Testcase  n:m    0 to n projects, 0 = admin only
```

Everything below a project inherits its access rules. Admins see and change everything, normal users only what belongs
to projects they have access to.

## Conventions

- URLs of groups, projects, skills, testcases and runs contain a random `uuid`, not the database id.
- Leaving a page with unsaved form changes asks for confirmation (a dialog for in-app links, the browser prompt otherwise); see
  [Design](Design.md#unsaved-changes).
- A form ignores a second submit within 5 seconds: a double click or a second Enter press does nothing, and a form stays usable
  after a cancelled navigation. Two people saving the same item at the same moment get a message to reload instead of an
  error page.
- Dates are shown as `DD.MM.YYYY HH:MM` in the Europe/Vienna time zone.
- Skills and testcases are never hard-deleted by users. Deleting hides them, their history stays.
- No personal data belongs into skills, testcases, run descriptions or any other free text: prompts are sent to LLM
  providers when a run starts. Use synthetic data.
- Starting a run executes it: a queue worker (`messenger:consume async`, the `worker` service of the dev stack) calls the
  LLMs and the judge. Without the worker a started version stays pending. There is no quota per project or user; the
  start confirmation shows the number of calls.

Console commands (create the first admin, import API keys) are described in [Commands.md](Commands.md).
