# Overview tables

All list pages (groups, projects, skills, testcases, runs) share the same behavior. Search, sorting and pagination run
on the server.

## URL parameters

State is part of the URL, so a list can be bookmarked and shared.

| Parameter | Meaning | Allowed |
| --- | --- | --- |
| `q` | Search term | up to 100 characters, no NUL characters |
| `sort` | Column to sort by | per list, see below; an unknown value falls back to the title |
| `dir` | Sort direction | `asc` (default) or `desc` |
| `page` | Page number | 1 or higher; a page beyond the last is shown as the last page |
| `perPage` | Entries per page | 10, 20, 50 or 100 |
| `project` | Filter by project | a project uuid; skills and testcases only |

Invalid values answer with *422*. The default order is the title, ascending.

## Page size

The default is 10 entries. Choosing another size in the *Entries per page* select puts it into the URL and stores it in
the session for that overview only, so each list can have its own size. Precedence: URL, then session, then 10.

## Search

Search is case-insensitive and matches anywhere in the text. `%` and `_` are searched literally, not as wildcards.

## Sorting

A click on a column header sorts by it; another click reverses the direction. Lists start sorted by title, so the first click on *Title* already reverses it. Sorting resets to page 1 and keeps search,
page size and project filter.

## Per list

| List | Searches | Sortable columns |
| --- | --- | --- |
| Groups | title | Title, Users, Updated, Created |
| Projects | title, description | Title, Users, Groups, Skills, Testcases, Updated, Created |
| Skills | title and description of the active version | Title, Updated, Created |
| Testcases | title and description of the active version | Title, Updated, Created |
| Runs (of one project) | title, description | Title, Skills, Testcases, Updated, Created |

The project filter on skills and testcases only offers projects the user has access to; for other projects the list
stays empty. Lists only contain entries the user may see, and the total in the pagination line counts only those.
