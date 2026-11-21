# Overview

`/` is the page after login. Everyone sees the same blocks, limited to what they may access (admins: everything, see
[Access control](access-control.md)). It contains no personal data.

## Blocks

| Block | Content |
| --- | --- |
| My projects | Up to 10 accessible projects by title: skill, testcase and run counts (each links to the matching list) and the time of the last run, `–` if there is none. Link to all projects. |
| Recent runs | The 5 most recently started run versions: run, project, version number, start time. The run links to that version. |
| Open drafts | Up to 5 skills and up to 5 testcases with an unfinished draft version, newest first: type, title, draft number, saved time. See [Versioning](versioning.md). |
| Administration | Admins only: how many providers have an API key and which are missing ([API keys](api-keys.md)), number of users and groups, each linked to its management page. |

Each block has a text for the empty case.

## Implementation

`HomeController` collects the data from `ProjectRepository::findOverview()`, `RunRepository::findRecentVersions()` and
`SkillRepository` / `TestcaseRepository::findRecentDrafts()`. They reuse the access filters of the lists, so a user never
sees foreign projects, runs or drafts.
