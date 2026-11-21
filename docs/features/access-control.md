# Access control

## Principles

- **Admin** (`ROLE_ADMIN`) sees, creates, edits and deletes everything. The admin role overrides group permissions.
- **User** (`ROLE_USER`) works in the [projects](projects.md) they have access to. Access to a project comes from
  membership in an assigned [group](groups.md) or from a direct assignment.
- Everything else derives from the project: skills, testcases and runs have no members of their own.
- Anything a user has no access to answers with *403 Forbidden*, including calls by direct URL. An unknown uuid answers
  with *404*.

## Who may do what

| Area | Admin | User with project access | Other users |
| --- | --- | --- | --- |
| Users, groups, API keys | everything | no access | no access |
| Projects | create, edit, delete, assign groups and users | view (counts only, no member names) | no access |
| Skills | see, edit, delete all (also without project) | see and edit skills of their projects, delete own | no access |
| Testcases | see, edit, delete all (also without project) | see and edit testcases of their projects, delete own | no access |
| Runs | everything | see, create, edit, start; delete own | no access |
| Own account and password | yes | yes | yes |

*Delete own* means the creator, as long as they still have access. If the creator account was deleted, only admins can
delete the item.

Skills and testcases without a project are admin only. The way to hide an item from normal users is to give it no project
(or projects without groups and users).

## How it is enforced

- Every route is checked on the server, never only hidden in a template.
- Overview tables filter in the database query, so inaccessible entries are neither listed nor counted. Counts shown to
  normal users (for example runs per skill) only include what they may access.
- Project, skill, testcase and run permissions are implemented as Symfony voters in `src/Security/Voter/`. The access rule
  for projects exists once, in `ProjectRepository`, and is reused for skills and testcases.
- Every form and action that changes data is protected by a CSRF token, logout included. A request with a missing or wrong
  token is refused with *403*. Every button that deletes something (users, API keys, groups, projects, skills, testcases, runs, drafts) opens a confirmation dialog first. The `/.../delete` routes accept only `POST`.
- Changes are limited to 120 requests per minute and user (*429* beyond that); the login has its own throttling.
- Responses carry `X-Content-Type-Options`, `Referrer-Policy` and a `Content-Security-Policy` that forbids framing by other
  sites and form targets outside the app.
- Free-text values are escaped by Twig when rendered.

## Data protection

Do not put personal data into skills, testcases, run texts or descriptions. Prompts will be sent to LLM providers, so every
free-text field that can reach a prompt shows the hint "Use synthetic data, no personal data." Versions and run snapshots
are immutable and cannot be purged in the interface; removing personal data from them needs a database intervention.
Usernames are the only personal identifiers the app stores; API keys are stored encrypted (see
[API keys](api-keys.md)).
