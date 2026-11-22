# Run execution: design

Status: draft, 21.11.2026

## Intent

Starting a run today only creates an immutable `RunVersion` snapshot (`Run::start()`), no LLM is called. This design
makes a start actually execute: every testcase of the snapshot is sent to every LLM configuration of the run, and the
LLM judge rates each output against the testcase's output criteria. The goal is to compare models within one version
and skill changes across versions.

## Goals

- One start runs the complete run once per LLM configuration of the run.
- Every testcase receives all assigned skills at the same time.
- Each output is rated by the global LLM judge: per output criterion "met / not met" with a reason, plus an overall
  score.
- Progress and results are visible on the version page; compare shows score changes between versions.

## Non-goals

- Comparing skills against each other within one version (one call per skill). Skills are always combined.
- Repetitions (several samples per testcase and configuration).
- Quota or daily limits. Decided by the product owner: there is none. `AGENTS.md` still demands a quota per project
  and user ("Add a quota per project and user before a run can start paid calls") and must be updated to match this
  decision.
- Streaming of outputs, tool use by the tested model, live push (Mercure/Turbo).
- Cancelling a running version (can follow later).

## Anti-goals

- A tested model gets tools, file system access or a working directory with secrets.
- Prompts, skill content or outputs end up in logs or the process list.
- A rating that changes when the judge setting changes later.
- Silent partial results: a skipped or failed cell must be visible as such.

## Execution matrix

For one start:

```
cells = usable LLM configurations of the run × testcases of the snapshot
```

- **Configuration**: an entry of the run (provider, model, optional effort). "Configured" means the configurations on
  the run, not every provider that has a stored key.
- **One cell = one LLM call + one judge call.** 3 configurations × 20 testcases = 60 calls + 60 judge calls.
- **Prompt of a cell**:
  - system prompt: the `SkillVersion::getSkillFile()` of all skill versions of the snapshot, ordered by skill title,
    separated by a blank line and `---` (builder's call on the exact separator). No skills: no system prompt (baseline).
  - user message: `TestcaseVersion::getPrompt()`, exactly as stored.

## Credentials and availability

A provider is **available** when at least one of its credentials is stored (`Provider::credentials()`; for Claude Code
the Anthropic API key or the Claude OAuth token). All Claude calls go through the Claude Code provider; there is no
Anthropic provider and none will be added. Claude Code tries the API key first; if the CLI answers 401 with it and an
OAuth token is stored, the same call is repeated once with the OAuth token (product owner's decision 21.11.2026). Only
when every stored credential is rejected does the cell fail with `invalid_api_key`. Keys come from the database (`ApiKeyStore`), decrypted at
call time. The env-based platform config in `config/packages/ai.yaml` is not used for runs; one source of keys
(database) as required by `AGENTS.md`. Whether the `ai.yaml` platforms and `app:ask-claude` stay is left to the builder,
but runs must not read keys from the environment.

### Selection

- The model select on the run page only offers models of available providers. A provider without credentials does not
  appear at all (same as Hugging Face without added models today).
- The add action validates this server-side too (a crafted POST with an unavailable provider is rejected).
- The judge select on `/admin/llm-configuration`: builder's call whether to filter it the same way; the start check
  below covers it either way.

### Key removed later

- A configuration whose provider lost its credentials stays on the run and is shown with a warning icon and the hint
  "No API key stored".
- On start it is **skipped**: no call goes out for it. The other configurations run normally.
- The snapshot still contains it, marked as skipped with reason `no_api_key`, so compare does not report it as removed
  and the version page shows why the column is empty.

## Start rules

Checked server-side in the start action, at the moment of starting (a key can be deleted between page load and click).
The start is blocked with a message naming the reason when:

1. no testcase (existing rule),
2. no configuration (existing rule),
3. no configuration with an available provider,
4. no judge set, or the judge's provider has no credentials (admins get a link to `/admin/llm-configuration`),
5. a previous version of the same run is still running (prevents double starts and accidental duplicate cost).

The start button opens a confirmation that shows the number of LLM calls (cells) and judge calls. The run page hint
("what is missing to start") includes rules 3 to 5.

Rule 5 needs mutual exclusion under concurrency: use `symfony/lock` (`LockFactory`, keyed by run) around the check and
the version creation, per `AGENTS.md`. The existing optimistic-lock `flushOrConflict` stays.

## Data model

All changes through Doctrine migrations.

### `RunVersion` (extended)

- `judgeProvider`, `judgeModel`: copied from `JudgeSetting` at start. Ratings always use these, never the current
  setting.
- `configurations` JSON: each entry additionally gets `skipped: null | "no_api_key"`. `LlmConfiguration::fromArray()`
  must tolerate old snapshots without the key.
- Status is **derived**, not stored: from the results (see below). Old versions without results show "not executed".

### `RunResult` (new), one per executed cell

| Field | Notes |
| --- | --- |
| `runVersion` | ManyToOne, cascade delete with the version |
| `testcaseVersion` | ManyToOne |
| `configuration` | JSON copy of provider, model, effort |
| `status` | `pending`, `running`, `judging`, `done`, `failed` |
| `output` | text, nullable; the model's answer |
| `error` | short, nullable; error class and HTTP status, no prompt or output content |
| `inputTokens`, `outputTokens`, `durationMs` | nullable; filled when the platform reports them |
| `criteria` | JSON list of `{criterion, met, reason}`, nullable until judged |
| `score` | int 0 to 100, nullable; `round(100 × met / total)` |
| `startedAt`, `finishedAt` | |

Unique per (`runVersion`, `testcaseVersion`, configuration key). Skipped configurations create no rows.

### Derived version status

- `running`: any result `pending`, `running` or `judging`
- `completed`: all results `done`
- `completed with errors`: none open, at least one `failed`
- `not executed`: no results (versions from before this feature)

## Flow

1. **Start** (request, one transaction, inside the lock): `Run::start()` creates the version (with judge copy and
   skipped markers) and one `RunResult` per cell with status `pending`. After the flush, one `ExecuteRunResult(resultId)`
   message per result is dispatched to the existing `async` transport (Doctrine).
2. **Execute** (worker, `#[AsMessageHandler]`): load the result; if it is not `pending`, return (idempotent). Set
   `running`, call the gateway, store output, tokens and duration. Set `judging` and dispatch `JudgeRunResult(resultId)`.
3. **Judge** (worker): load the result; if it is not `judging`, return. Build the judge prompt from the testcase's output
   criteria and the output, call the gateway with the version's judge provider and model, parse and validate the answer,
   store `criteria` and `score`, set `done`.
4. **UI**: the version page polls a small endpoint (status counts plus changed rows) while the status is `running`, for
   example every 3 seconds, and stops when finished. No new frontend package.

## Judge

- **Criteria split**: `outputCriteria` split by line, trimmed, empty lines dropped. Each remaining line is one criterion
  (this turns the existing "one criterion per line" convention into a rule; the testcase form help text should say so).
- **Prompt**: the judge gets the numbered criteria and the output, and must answer JSON only:
  `[{"index": 1, "met": true, "reason": "..."}]`. The tested model's system prompt and the testcase prompt are not
  needed; builder's call whether to include the testcase prompt as context.
- **Validation**: exactly one entry per criterion index, `met` boolean, `reason` non-empty string. Parse with the
  Serializer into a readonly DTO, check with the Validator. Invalid answer: retry the judge call once; then the result
  is `failed` with error `invalid_judge_answer`, the output stays stored.
- **No criteria**: cannot happen through the form (`TestcaseData::$outputCriteria` is `NotBlank` with trim), but
  `total = 0` after the split must not divide by zero: no judge call, result `failed` with error `no_criteria`.
- **Score**: computed, `round(100 × met / total)`. The judge does not give an overall score.
- Structured output (`response_format` via `Symfony\AI\Platform\StructuredOutput\PlatformSubscriber`) may be used where
  the judge's bridge supports it; the validation above stays in any case because support differs per provider.

## LLM gateway

One service (`App\Ai\LlmGateway` or similar) is the only code that calls a platform for runs. It:

- builds the platform per call with the bridge factory and the decrypted key, for example
  `Symfony\AI\Platform\Bridge\OpenAi\Factory::createPlatform($apiKey, $httpClient)` (verified in vendor for OpenAI,
  Gemini, DeepSeek, Hugging Face: first argument is the API key; Claude Code takes `cliBinary`, `workingDirectory`,
  `timeout`, `environment`),
- passes a fixed option whitelist only; never forwards user-controlled option names,
- passes `tools: []`,
- maps `Effort` per provider. Claude Code: `effort` option (as `app:ask-claude` does). For OpenAI, Gemini, DeepSeek and
  Hugging Face no effort option was found in the installed bridges (0.15); the builder must verify the provider's
  parameter (for example OpenAI `reasoning_effort`) before mapping it. Where a provider or model has no effort parameter,
  the call goes out without it and the result shows "effort not supported" (builder's call on the exact display).
- logs nothing of prompt or output.

### Hugging Face specifics

- `Factory::createPlatform($apiKey, provider: null)`: with `null` Hugging Face picks the inference provider (verified in
  the factory docblock). No provider is stored per model.
- Admins can add models of any task. A model the router cannot serve as chat completion makes the cell `failed` with
  error `unsupported_model` (designer's call; no change to the add flow in this iteration).

### Claude Code specifics (verified in `vendor/symfony/ai-claude-code-platform/ModelClient.php`)

- Every option key becomes a CLI flag (`--key value`). The whitelist above is therefore a security boundary.
- The prompt is passed as `-p <prompt>`. `AGENTS.md` requires `--` before the prompt; the gateway must ensure a prompt
  starting with `-` cannot be read as a flag (decorate or replace the model client if the bridge offers no way).
- `Symfony\Component\Process\Process` inherits the worker's environment by default. The child would see
  `DATABASE_URL`, `APP_SECRET`, `API_KEY_ENCRYPTION_KEY` and other keys. Pass an explicit environment that contains
  only the one Claude credential and what the CLI needs (`PATH`, `HOME`), and unset everything else.
- Working directory: a neutral, empty directory (for example under `var/`), never the project root, which holds
  `.env.local` and `.claude/settings.json`.
- Keep the bridge's default `NullLogger`: its info log contains the full command line including the prompt.
- Timeout: bridge default is 300 s; builder's call whether to raise it.

## Failures and retries

- Messenger `async` retries 3 times. Only transient errors are retried (timeouts, 429, 5xx). Permanent errors (4xx
  except 429, unknown model, key rejected) throw `UnrecoverableMessageHandlingException` and set the result `failed`.
- After the last retry the result is set `failed` (handle `WorkerMessageFailedEvent` or catch in the handler, builder's
  call) so the version does not stay `running` forever.
- A key deleted after start but before the cell runs: the cell fails with `no_api_key`.
- Retries must work with the idempotency guard: before rethrowing a transient error the handler resets the result to
  `pending` (execute) or keeps it `judging` (judge), so the retried message is not skipped.
- Stuck cells (worker crash, killed CLI process): a result that has been `running` or `judging` for more than
  30 minutes (measured from `startedAt` or the last status change) is set `failed` with error `timeout` (product owner's
  decision). The check runs when the run page or version page loads and inside the start check (rule 5), no cron. A
  handler that finishes after the timeout must not overwrite the result: it writes only if the status is still the one
  it set. `pending` results do not time out (a long queue is legitimate; a missing worker is an operations problem).
- No compose service runs `messenger:consume` today; the builder adds one for the dev stack and documents it.
- Worker concurrency: builder's call (number of `messenger:consume` processes). No per-provider rate limiting in this
  iteration.

## UI changes

- **Run page**: model select filtered by availability; warning icon on configurations without key; start hint extended;
  confirmation with call counts.
- **Version page**: status and progress (`12 / 60 done`); a matrix with testcases as rows and configurations as columns,
  each cell showing the score (or status / error); a cell opens the output and the criteria list (met / not met with
  reason). Skipped configurations appear as a column with "skipped – no API key".
- **Compare**: per testcase and configuration the score of both versions and the difference; average score per
  configuration. Only cells present in both versions are compared.
- Follow `docs/Design.md` (view transitions, tokens).

## Data protection

- Prompts, skills and outputs leave the application to the providers. The existing hints against personal data stay.
- Outputs are stored in `run_result` and are immutable like the snapshots; a deletion request needs a database
  intervention (as already noted in `AGENTS.md`). Deleting the run deletes its results.
- No prompt, skill or output content in logs, messages (only IDs), errors or the process list.

## Tests

- Functional (`WebTestCase`): start rules 1 to 5, confirmation counts, filtered select, rejected crafted POST, warning
  icon, version page and polling endpoint.
- Service (`KernelTestCase`): matrix creation incl. skipped configurations, system prompt assembly, handlers with a fake
  platform (idempotency, transient vs. permanent errors), judge parsing and validation, score computation, Claude Code
  environment and working directory.
- No test calls a real provider.

## Docs to update

`docs/features/runs.md`, `docs/features/llm-configuration.md` (judge is used, key availability), `docs/features/testcases.md`
(one criterion per line is now a rule), `docs/Features.md`, `AGENTS.md` (quota decision, gateway now exists).

## Decisions

From the product owner: skills combined per testcase; one full pass per configuration; unavailable providers not
selectable; configurations without key show a warning and are skipped; no judge blocks the start; per-criterion met /
not met with reason plus overall score; computed percentage score; no quota; stuck cells fail after 30 minutes.

Calls made by the designer (check these): skill order by title; skipped configurations kept in the snapshot; start
blocked without any usable configuration; server-side start check; judge copied into the version; line-based criteria
split; one judge retry on invalid answer; no second start while a version runs (lock); call count in the start
confirmation; polling instead of push; database as the only key source for runs; Hugging Face provider picked by Hugging Face, non-chat models fail; `no_criteria` guard; `pending` cells never time out.
