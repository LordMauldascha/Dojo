# Runs

A run is a test setup inside one [project](projects.md): LLM configurations, [skills](skills.md) and
[testcases](testcases.md). Every time a run is started, a new immutable version (snapshot) is created and executed:
every testcase is sent to every LLM configuration and an LLM judge rates each answer against the testcase's output
criteria. This needs the queue worker, see [Worker](#worker).

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

Providers (OpenAI, Google Gemini, DeepSeek, Claude Code, Hugging Face), their models (Claude Code: `opus`, `sonnet`, `haiku`) and the effort
levels are defined in code (`App\Ai\Provider::models()`, `App\Ai\Effort`) and
are available in every project. Adding a model is a code change, except for Hugging Face: its models are the ones an admin added in the
[LLM configuration](llm-configuration.md#hugging-face-models) (`App\Ai\ModelCatalog`).

Only providers with a stored credential are offered in the select (and as judge). A configuration whose key was removed
later stays in the run and is marked *No API key stored* on the run page.

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
| Run | `/runs/{uuid}` | Tiles at the top (versions with the status of the latest version — `running`, `judging`, `finished`, `finished with errors` or `not executed`, linking to it — and the Start button, configurations, skills, testcases; a hint says what is missing to start), version list with the share of met criteria per version (met criteria of all judged cells divided by all their criteria, `–` until a cell is judged) and the average duration, input tokens and output tokens per version (columns `Avg. …`, mean over the cells that have the value, `–` if none has it), edit details and configurations, skills and testcases side by side |
| Version | `/runs/{uuid}/versions/{number}` | The snapshot, the charts against the previous version, its results (progress and the result matrix) |
| Progress | `/runs/{uuid}/versions/{number}/progress` | JSON for the live update of the version page (`status`, `done`, `total`, `html`, `comparison`); same access as the version page |
| Run status | `/runs/{uuid}/status` | JSON for the live update of the run page: status of the latest version, `done`, `total` and the re-rendered tiles with Start button, hints and version list (`html`); polled every 3 seconds while the latest version runs; same access as the run page |
| Compare | `/runs/{uuid}/compare?from=1&to=2` | Differences between two versions |

The list has the columns Title, Description, Skills, Testcases, Updated, Created and searches title and description
(see [Overview tables](overview-tables.md)). Skills and testcases are assigned with one checkbox list each.

## Starting a run

*Start* is blocked, with a message naming the reason, when one of these holds. The rules are checked on the server at
the moment of the click (a key can be deleted between page load and click); the run page lists the open ones as hints
(admins get a link to the [LLM configuration](llm-configuration.md) for rule 4). Testcases that were deleted do not
count for rule 1, in the check and in the hint:

1. the run has no testcase,
2. the run has no LLM configuration,
3. no configuration has a provider with a stored credential,
4. no LLM judge is set, the judge's provider has no stored credential, or the judge's model is no longer offered (a model
   removed from `App\Ai\Provider::models()`, or a Hugging Face model an admin deleted): *The LLM judge model is no longer
   available.* Otherwise every cell would pay for its LLM call and then fail at the judge,
5. a version of the same run is still running (no double start, no duplicate cost).

Skills are optional (a run without skills is a baseline, see also [Baseline](#baseline)). Rule 5 is checked under a lock per run (`symfony/lock`,
`LOCK_DSN`), so two clicks at once create one version. Before it, results stuck for more than 30 minutes are failed (see
[Timeout](#timeout)), so a crashed worker cannot block a run forever.

When a start is possible, the button opens a confirmation with the number of LLM calls (cells) and judge calls; every
call is billed by the provider. There is no quota per project or user (product decision 21.11.2026).

### Baseline

The first start of a run that has skills asks whether to also start a **baseline**: the dialog then has the buttons
*Start without baseline* and *Start with baseline* and shows the number of calls for both. With the baseline, version 1
is a snapshot without skills and version 2 the one with the selected skills; both are created in the same start (one
lock, one flush) and queued together, so they run at the same time. Without the baseline only the version with skills is
created, as before. Later starts, runs without skills and runs that already have a version get the plain dialog, and a
baseline request for them is refused (*A baseline is only possible for the first version of a run with skills.*). The
baseline is an ordinary version, so version 2 has version 1 to be compared with on its [version page](#version-page).
Agents use `start_run` with `withBaseline`, see [MCP](mcp.md).

## Execution

One start executes **cells**: the configurations that can be used (provider with a stored credential) times the
testcases of the snapshot. One cell is one LLM call plus one judge call, so 3 configurations and 20 testcases make 60 and
60 calls.

- **Prompt**: the system prompt is the skill file of all skills of the version (see [Skills](skills.md)), ordered by
  skill title and joined with a blank line, `---` and a blank line. Without skills there is no system prompt. The user
  message is the testcase prompt exactly as stored. Skills are always combined, never compared one by one.
- **Skipped configurations**: a configuration whose provider has no credential at start stays in the version, is marked
  *skipped – no API key* in its matrix column and makes no call and no result. The other configurations run normally.
  Compare does not report a skipped configuration as removed.
- **Judge**: the global judge of the [LLM configuration](llm-configuration.md) is copied into the version at start, so
  later changes of the setting never change the rating. The judge gets the numbered criteria (one per non-empty line of
  the testcase's output criteria) and the output, and answers JSON only: per criterion *met* or *not met* with a short
  reason. The answer is validated (one entry per criterion); an invalid one is asked for once more, then the cell fails.
- **Score**: `round(100 × met / total)`, an integer from 0 to 100, computed by the app, not by the judge.
- **Effort**: sent to the provider where it supports it, otherwise the call goes out without it. The configuration list
  of the run page and the matrix column of a version show *effort not supported* or *effort capped at …*, see [LLM configuration](llm-configuration.md#effort-mapping).
- **Status of a cell**: `pending`, `running`, `judging`, `done` or `failed`. A failed cell shows its error code and
  keeps the output if it was already stored. The version is *running* while any cell is open, *completed* when all
  cells are done, *completed with errors* when none is open and at least one failed, and *not executed* when it has no
  cells (versions from before execution existed).
- **Error codes** (`RunResult::error`; never prompt or output content): `no_api_key` (the key was deleted after the
  start), `invalid_api_key` (every stored credential was rejected), `unsupported_model` (the provider cannot serve the
  model, for example a Hugging Face model without chat completion, or a model that was removed from the list),
  `invalid_judge_answer`, `no_criteria` (the criteria have no non-empty line), `no_judge` (the version has no judge;
  only versions from before execution existed), `timeout`, `dispatch_failed` (the cell could not be queued), otherwise the short exception class plus the HTTP status if there was one.
- **Retries**: timeouts, rate limits (429), server errors (5xx) and network errors are retried up to 3 times with growing
  delay; after the last retry the cell fails. For Claude Code the status comes from `api_error_status` of the CLI's
  result line, so the codes are `RateLimitExceededException 429` or `ServerException <status>` as for the HTTP providers.
  Everything else (other 4xx, unknown model, rejected key) fails at once.
- **Data protection**: prompts, skills and outputs go to the providers and outputs are stored in the database with the
  version. Nothing of them is logged or sent through the queue (messages carry only the id of a cell). The Claude Code
  CLI runs with `--no-session-persistence` (no transcript under `$HOME/.claude/projects`) and `--strict-mcp-config` (no
  MCP servers from user or project settings).

### Timeout

A cell that has been `running` or `judging` for more than 30 minutes is set to `failed` with `timeout`. The check runs
when the run page, its status endpoint, the version page, its progress endpoint or a start is requested; there is no cron job. A worker that
finishes later does not overwrite it. `pending` cells never time out: a long queue is legitimate. A missing worker is an
operations problem.

### Worker

Cells are executed by `bin/console messenger:consume async` (Messenger, Doctrine transport). Without a worker a started
version stays `pending`. The dev stack has a `worker` service for it (`compose.override.yaml`: same image as `app`,
`--time-limit=3600 --memory-limit=256M`, restarts on exit, no published port); start it with
`docker compose up -d worker`. After code changes restart it (`docker compose restart worker`), because a running
worker keeps the old code. On a fresh checkout it restarts in a loop until `composer install` has run. One process runs one cell at a time; run several workers for more parallelism (no per-provider
rate limiting).

The worker runs with `APP_DEBUG=0`: Doctrine logs every SQL statement with its parameters when `kernel.debug` is on, which
would write outputs and judge reasons to `var/log/dev.log`. Without debug the container is not rebuilt when code or config
changes, so after such a change clear the cache (`docker compose exec worker php bin/console cache:clear`) and restart the
worker.

## Versions

Each start creates the next version (1, 2, 3, ...). A version stores:

- the title and description of the run at that moment,
- the LLM configurations at that moment,
- for every assigned skill and testcase a reference to its **active (latest final) version**; drafts are ignored.

Because final versions of skills and testcases never change (see [Versioning](versioning.md)), a run version always shows
exactly what was started, even if the skill or testcase is edited or deleted later. Deleted ones are marked
`(deleted)`. A skill is handed to the LLM as `SkillVersion::getSkillFile()`: generated header plus instructions (see
[Skills](skills.md)).

The version also stores the judge (provider and model) and per configuration whether it was skipped, and owns the
results of its cells; they are deleted with it.

### Version page

The version page shows `done / total` and a matrix with the testcases as rows and the LLM configurations as columns.
Each cell shows the score (for example `80 %`), its status or `failed – <error code>`; once the model answered, a line
below shows the duration and token usage of the model call (for example `12.3 s · 1,234 in / 567 out`, `–` for a count the
provider did not report; the judge call is not included). A cell with output opens (native
`<details>`) to the model's answer and the criteria list with *met* / *not met* and the judge's reason per criterion.
While the version is running the page asks the progress endpoint every 3 seconds and swaps in the new matrix (open
cells, focus and scroll position stay); it stops when the version is no longer running. After three failed requests in a
row it stops and says to reload the page. A version without cells shows *This version was not executed.*

#### Comparison with the previous version

Above the matrix the page shows bar charts of this version against version `number - 1`: score, duration, input
tokens, output tokens and failure rate, each with one pair of bars (previous, this version) per LLM configuration and a
*Total* group. Values are means over the testcases. As on the compare page, cells are matched by testcase (also when its
version changed) and configuration; only cells that exist in both versions count. A value counts only when both sides
have it, so a cell without a score or token count on one side does not move one bar alone. The failure rate is the share of
failed cells over the cells that are finished in both versions. The score is the mean of the cell scores, not the share
of met criteria shown in the version list. While the version runs, the charts follow the live update. Every chart set has
a text alternative (a visually hidden table with *previous → this version* per configuration and metric).

Version 1 shows *There is no previous version to compare against.* instead of the charts, and a version whose previous
version shares no cell with it says so.

### Compare

The compare page has two selects for the versions (default: the latest against the one before) and lists:

- changed title or description,
- added and removed LLM configurations,
- added and removed skills and testcases,
- skills and testcases that are in both versions but with a different version number (for example `version 1 to
  version 2`),
- scores: per testcase and LLM configuration `from → to (±delta)` (for example `60 → 80 (+20)`; a missing score shows as
  `–` without delta) and the average score per configuration (one decimal) over the cells that have a score in both
  versions, with their number (`72.5 → 80.0 (+7.5) · 4 cells`), so a cell that lost its score does not move only one
  side. The delta is computed from
  the displayed (rounded) values, so `33.3 → 33.7` shows `+0.4`. Cells are matched by
  testcase (also when its version changed) and configuration; only cells that exist in both versions and have a score
  in at least one are listed. A configuration skipped in one version is not reported as removed.

Comparing the model output itself is not part of the page.

## Deleting

Deleting a run removes it and all its versions and results for good. Deleting the project does the same for all its runs. Skills and
testcases are not affected.

## Related

[Projects](projects.md), [Skills](skills.md), [Testcases](testcases.md), [Versioning](versioning.md).
