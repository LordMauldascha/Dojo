# Runs

A run is a test setup inside one [project](projects.md): LLM configurations, [skills](skills.md) and
[testcases](testcases.md). Every time a run is started, a new immutable version (snapshot) is created. Calling the
LLMs is not built yet, so starting only creates the version.

## Data

- **Title** (required, at most 255 characters) and **description** (optional, at most 2000 characters).
- **Project**: exactly one, fixed at creation.
- **LLM configurations**: any number of entries, each a provider, a model and an optional effort level.
- **Skills** and **testcases** assigned to the run.
- Creator, created and updated time, and the list of versions.

### LLM configurations

An entry is a combination, so a model always belongs to its provider. The model select on the run page is grouped by
provider. The effort is optional and one of `low`, `medium`, `high`, `xhigh`, `max`. The same combination cannot be added
twice; entries are removed one by one, identified by provider, model and effort (not by their position in the list).

Providers, their models and the effort levels are defined in code (`App\Ai\Provider::models()`, `App\Ai\Effort`) and
are available in every project. Adding a model is a code change.

### Skills and testcases of a run

Only skills and testcases of the run's project can be selected, and deleted ones are not offered. Starting a run also leaves out anything deleted or no longer in the project. This also holds
afterwards: a skill or testcase is removed from a run automatically when it

- is removed from the run's project (the run's project is no longer among its projects),
- or is deleted.

Existing run versions are snapshots and keep their content.

## Who may do what

Runs have no own members. Everything derives from the project.

| Action | Who |
| --- | --- |
| See, create, edit, start | Admins and everyone with access to the run's project |
| Delete | The creator (while still having access) or an admin |

## Pages

| Page | URL | Purpose |
| --- | --- | --- |
| Runs of a project | `/projects/{uuid}/runs` | Table of that project's runs; reached from the project page |
| New run | `/projects/{uuid}/runs/new` | Title and description, then continue on the run page |
| Run | `/runs/{uuid}` | Tiles at the top (versions with the Start button, configurations, skills, testcases; a hint says what is missing to start), version list, edit details and configurations, skills and testcases side by side |
| Version | `/runs/{uuid}/versions/{number}` | Read-only snapshot |
| Compare | `/runs/{uuid}/compare?from=1&to=2` | Differences between two versions |

The list has the columns Title, Description, Skills, Testcases, Updated, Created and searches title and description
(see [Overview tables](overview-tables.md)). Skills and testcases are assigned with one checkbox list each.

## Starting a run

*Start* needs at least one testcase and at least one configuration; skills are optional (for example a baseline run
without a skill). Otherwise nothing is created and a message explains what is missing.

## Versions

Each start creates the next version (1, 2, 3, ...). A version stores:

- the title and description of the run at that moment,
- the LLM configurations at that moment,
- for every assigned skill and testcase a reference to its **active (latest final) version**; drafts are ignored.

Because final versions of skills and testcases never change (see [Versioning](versioning.md)), a run version always shows
exactly what was started, even if the skill or testcase is edited or deleted later. Deleted ones are marked
`(deleted)`.

### Compare

The compare page has two selects for the versions (default: the latest against the one before) and lists:

- changed title or description,
- added and removed LLM configurations,
- added and removed skills and testcases,
- skills and testcases that are in both versions but with a different version number (for example `version 1 to
  version 2`).

Comparing the model output will follow once runs execute.

## Deleting

Deleting a run removes it and all its versions for good. Deleting the project does the same for all its runs. Skills and
testcases are not affected.

## Related

[Projects](projects.md), [Skills](skills.md), [Testcases](testcases.md), [Versioning](versioning.md).
