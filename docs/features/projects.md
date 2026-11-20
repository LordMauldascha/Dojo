# Projects

A project is the central unit. It is the access boundary for skills, testcases and runs. The number of projects is not
limited (for example software development, IT security, data protection).

## Data

- **Title**: required, at most 255 characters, unique regardless of letter case (`Security` and `security` are the same title).
- **Description**: optional, at most 5000 characters.
- **Groups** and **users** with access (see below).
- **Skills** assigned to the project, **testcases** belonging to it, and its **runs**.

## Who has access

A user has access to a project when they are a member of an assigned group or are assigned directly. Admins have access
to all projects. Every other project answers with *403 Forbidden* (an unknown uuid with *404*), also when the URL is
typed directly. A project without groups and users is visible to admins only; admins use that for admin-only skills and
testcases.

## Pages

| Page | URL | Who |
| --- | --- | --- |
| Overview | `/projects` | Everyone, limited to accessible projects (admins: all) |
| New project | `/projects/new` | Admins |
| Project | `/projects/{uuid}` | Everyone with access; admins can change it |
| Delete | `/projects/{uuid}/delete` | Admins |

**Overview** columns: Title, Description, Users, Groups, Skills, Testcases, Updated, Created. Users, groups, skills and
testcases are counts; skills and testcases link to the matching overview filtered by this project. Search covers title
and description. See [Overview tables](overview-tables.md).

**Detail page, admins**: edit title and description; add and remove groups; add and remove users; add and remove skills
(the skill is assigned to the project); counts and links for testcases and runs; delete.

**Detail page, other users**: read-only title and description plus the counts of groups, users, skills and testcases and
links to the filtered skill overview, testcase overview and the runs of the project. Names of other users and groups
are not shown.

## Runs of a project

`/projects/{uuid}/runs` lists the project's runs and is the place to create new ones. See [Runs](runs.md).

## Deleting a project

The confirmation page states what happens, then:

- the project's **runs and their versions are deleted** for good;
- **skills** are kept and only lose the assignment to this project;
- **testcases** are kept but lose their project, so only admins can reach them afterwards;
- groups and users are not touched.

## Related

[Skills](skills.md), [Testcases](testcases.md), [Runs](runs.md), [Access control](access-control.md).
