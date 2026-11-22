# Run Execution Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Starting a run sends every testcase (with all skills) to every usable LLM configuration and lets the LLM judge
rate each output per criterion, with progress and scores on the version and compare pages.

**Architecture:** `Run::start()` creates the snapshot plus one `RunResult` per cell. Messenger handlers execute the cell
through one `LlmGateway` and then judge it. Platforms are built per call from the database key. Status is derived from
the results. The version page polls a JSON endpoint.

**Tech Stack:** Symfony (PHP attributes, autowiring), Doctrine ORM + migrations, Messenger (`async`, Doctrine
transport), `symfony/lock` (new), symfony/ai 0.15 bridges, Twig, TypeScript + Vitest.

**Spec:** `docs/superpowers/specs/2026-11-21-run-execution-design.md`

## Global Constraints

- Follow `AGENTS.md`: attributes, autowiring, thin controllers, `#[MapRequestPayload]`/Serializer/Validator, Flex for new packages (`composer require symfony/lock`), migrations only (`make:migration`), no YAML service unless unavoidable.
- Yoda conditions everywhere (`if (null === $x)`), comments only where code is not self-explanatory, at most 2 lines.
- No commit and no push unless the user explicitly asks. Each task ends with the quality gates instead.
- Quality gates (all exit 0): `php bin/phpunit`, `vendor/bin/phpstan analyse --no-progress` (level max, no ignores, no baseline), `vendor/bin/php-cs-fixer fix --dry-run --diff`, `vendor/bin/twig-cs-fixer lint`, `npm test`, `npm run format:check`, `npx tsc --noEmit`.
- No test calls a real provider. Use a stub platform (pattern: `tests/Command/StubPlatform.php`).
- No prompt, skill content or output in logs, exception messages, Messenger messages (IDs only) or the process list.
- Keys for runs come only from `App\Security\ApiKeyStore` (database), never from env.
- Timeout for stuck cells: 30 minutes in `running` or `judging`. `pending` never times out.
- Score: `round(100 * met / total)`, int 0 to 100.
- Error codes stored in `RunResult::error`: `no_api_key`, `invalid_api_key`, `unsupported_model`, `invalid_judge_answer`, `no_criteria`, `timeout`, otherwise `<short exception class>` plus HTTP status if any.
- Synthetic data only in fixtures; no personal data.

## Review Focus

1. A key is deleted between page load and start click: the start check must use the current key state (Task 3 test `testStartUsesKeyStateAtClickTime`).
2. Two start clicks at the same time (double submit, two tabs): exactly one version (Task 3 test `testSecondStartIsBlockedWhileAVersionRuns`).
3. Handler retried by Messenger after a transient error: the retry must execute, not be skipped by the idempotency guard (Task 5 test `testTransientErrorResetsToPendingAndRethrows`).
4. Handler finishing after the 30-minute timeout already set `failed`: the late write must not overwrite (Task 5 test `testLateFinishDoesNotOverwriteTimedOutResult`).
5. Judge answer with a missing, duplicate or extra criterion index, or wrapped in a Markdown code fence: rejected after one retry; the code fence case is accepted (Task 6 tests).

---

### Task 1: Key availability in model selection

**Files:**
- Modify: `src/Ai/ModelCatalog.php`, `src/Form/RunConfigurationType.php`, `src/Controller/RunController.php` (configuration add, show), `templates/runs/show.html.twig`
- Test: `tests/Ai/ModelCatalogTest.php`, `tests/Controller/RunManagementTest.php`

**Interfaces:**
- Consumes: `ApiKeyStore::hasProvider(Provider): bool`
- Produces: `ModelCatalog::isAvailable(Provider $provider): bool`; `ModelCatalog::choices()` returns only available providers; `ModelCatalog::fromChoice()` returns `null` for an unavailable provider.

- [ ] **Step 1: Write failing tests**
  - `ModelCatalogTest::testChoicesLeaveOutProvidersWithoutCredential`: only OpenAI key stored → `array_keys($catalog->choices()) === ['OpenAI']`.
  - `ModelCatalogTest::testClaudeCodeIsAvailableWithEitherCredential`: only `Credential::ClaudeOAuth` stored → `isAvailable(Provider::ClaudeCode)` true.
  - `RunManagementTest::testAddingAConfigurationOfAProviderWithoutKeyIsRejected`: POST `app_run_configuration_add` with `openai:gpt-5.5` and no OpenAI key → run has 0 configurations, flash error.
  - `RunManagementTest::testConfigurationWhoseKeyWasRemovedShowsWarning`: configuration added, key deleted → run page contains `No API key stored` next to that configuration.
- [ ] **Step 2: Run** `php bin/phpunit --filter 'ModelCatalogTest|RunManagementTest'` → new tests FAIL.
- [ ] **Step 3: Implement** `isAvailable()` via `ApiKeyStore::hasProvider()`; filter `choices()` and `fromChoice()`; pass the set of unavailable configuration keys to the template and render a warning icon with text `No API key stored` (accessible text, not icon-only).
- [ ] **Step 4: Run** the filter again → PASS. Run all quality gates.

### Task 2: Data model (RunResult, judge snapshot, skipped configurations)

**Files:**
- Create: `src/Entity/RunResult.php`, `src/Run/RunResultStatus.php`, `src/Run/RunVersionStatus.php`, `migrations/VersionYYYYMMDDHHMMSS.php` (via `make:migration`)
- Modify: `src/Entity/RunVersion.php`, `src/Entity/Run.php` (`start()`), `src/Ai/LlmConfiguration.php` (tolerate `skipped`)
- Test: `tests/Entity/RunTest.php`, `tests/Entity/RunResultTest.php`

**Interfaces:**
- Produces:
  - `enum RunResultStatus: string { Pending='pending'; Running='running'; Judging='judging'; Done='done'; Failed='failed'; }`
  - `enum RunVersionStatus: string { NotExecuted; Running; Completed; CompletedWithErrors; }` with `RunVersion::getStatus(): RunVersionStatus` (rules: spec "Derived version status").
  - `RunResult::__construct(RunVersion $runVersion, TestcaseVersion $testcaseVersion, LlmConfiguration $configuration)`; columns as in spec table plus `configKey` (string, `LlmConfiguration::key()`), unique (`run_version_id`, `testcase_version_id`, `config_key`); `statusChangedAt`.
  - Transitions, each returns `bool` (false if the current status is not the expected one, nothing changes): `markRunning()` (pending→running), `markPending()` (running→pending), `markJudging(string $output, ?int $inputTokens, ?int $outputTokens, int $durationMs)` (running→judging), `markDone(list<array{criterion: string, met: bool, reason: string}> $criteria)` (judging→done, computes score), `markFailed(string $error, RunResultStatus $expected)`; `isStale(\DateTimeImmutable $now): bool` (running/judging and `statusChangedAt` older than 30 min).
  - `RunVersion`: `getJudge(): LlmConfiguration`, `getResults(): Collection<int, RunResult>`, `getSkippedConfigurations(): list<LlmConfiguration>`; `configurations` entries carry `skipped: null|'no_api_key'`.
  - `Run::start(callable(Provider): bool $isAvailable, LlmConfiguration $judge): RunVersion` creates one `RunResult` per (available configuration × testcase version); throws `\LogicException('No LLM configuration has an API key.')` when none is available.
- [ ] **Step 1: Write failing tests**
  - `RunTest::testStartCreatesOneResultPerAvailableConfigurationAndTestcase`: 2 configs (1 available) × 3 testcases → 3 results, 1 skipped config with reason `no_api_key`.
  - `RunTest::testStartIsRefusedWithoutAvailableConfiguration`.
  - `RunTest::testStartCopiesJudge`: `$version->getJudge()->key()` equals the passed judge.
  - `RunResultTest::testScoreIsPercentageOfMetCriteria`: 4 of 5 met → `80`; 1 of 3 → `33`.
  - `RunResultTest::testTransitionFromWrongStatusChangesNothing`.
  - `RunResultTest::testStaleAfterThirtyMinutesOnlyWhenRunningOrJudging`.
  - `RunTest::testVersionStatusIsDerivedFromResults` (all four statuses; old version without results → `NotExecuted`).
- [ ] **Step 2: Run** `php bin/phpunit tests/Entity` → FAIL.
- [ ] **Step 3: Implement**; update existing callers and tests of `Run::start()`. Generate migration with `bin/console make:migration --no-interaction`, review it, run `bin/console doctrine:migrations:migrate --no-interaction`. Existing `run_version` rows get judge columns nullable; `getJudge()` returns `?LlmConfiguration`.
- [ ] **Step 4: Run** tests → PASS; `bin/console doctrine:schema:validate` OK; quality gates.

### Task 3: Start rules, lock, confirmation, timeout sweep

**Files:**
- Create: `src/Run/RunStarter.php`, `src/Run/StartCheck.php`, `src/Run/StaleResultSweeper.php`, `src/Message/ExecuteRunResult.php`
- Modify: `src/Controller/RunController.php` (`start`, `show`), `src/Controller/RunVersionController.php` (`show`), `templates/runs/show.html.twig`, `config/packages/messenger.yaml` (routing; `when@test` transport `in-memory://`)
- Test: `tests/Run/RunStarterTest.php`, `tests/Controller/RunManagementTest.php`

**Interfaces:**
- Consumes: Task 1 `ModelCatalog::isAvailable()`, Task 2 `Run::start()`, `JudgeSettingStore::get(): ?LlmConfiguration`
- Produces:
  - `final readonly class StartCheck { public function __construct(public list<string> $problems, public int $llmCalls, public int $judgeCalls) {} public function canStart(): bool }`
  - `RunStarter::check(Run $run): StartCheck` (rules 1 to 5 of the spec, messages: `A run needs at least one testcase to start.`, `A run needs at least one LLM configuration to start.`, `No LLM configuration has an API key.`, `No LLM judge is set.` / `The LLM judge has no API key.`, `A version of this run is still running.`)
  - `RunStarter::start(Run $run): RunVersion` inside `LockFactory::createLock('run-start-'.$run->getId())`, re-checks, flushes, then dispatches one `ExecuteRunResult` per result; throws `\LogicException` with the first problem.
  - `final readonly class ExecuteRunResult { public function __construct(public int $resultId) {} }` routed to `async`.
  - `StaleResultSweeper::sweep(RunVersion|Run $subject): int` sets stale results `failed` with `timeout`, returns count.
- [ ] **Step 1:** `composer require symfony/lock` (Flex recipe; lock store from `LOCK_DSN`, use the database or `flock` per recipe default).
- [ ] **Step 2: Write failing tests**
  - `testStartIsBlockedWithoutJudge`, `testStartIsBlockedWhenJudgeHasNoKey` (admin sees link to `/admin/llm-configuration`).
  - `testStartUsesKeyStateAtClickTime`: page loaded, key deleted, POST start → blocked, no version.
  - `testSecondStartIsBlockedWhileAVersionRuns`.
  - `testStaleResultUnblocksTheRun`: result `running` with `statusChangedAt` 31 min ago → run page load sets it `failed`/`timeout`, start allowed.
  - `testStartDispatchesOneMessagePerResult`: in-memory transport holds N `ExecuteRunResult`.
  - `testStartConfirmationShowsCallCounts`: run page contains `6 LLM calls and 6 judge calls` for 2 × 3.
- [ ] **Step 3: Run** → FAIL. **Step 4: Implement**; controller stays thin (calls `RunStarter`, catches `\LogicException` → flash). Start button opens the existing confirm dialog (`assets/js/components/dialog.ts`) with the counts.
- [ ] **Step 5: Run** → PASS; quality gates.

### Task 4: LLM gateway and per-call platforms

**Files:**
- Create: `src/Ai/LlmGateway.php`, `src/Ai/PlatformProvider.php` (interface), `src/Ai/KeyedPlatformProvider.php`, `src/Ai/LlmAnswer.php`, `src/Ai/MissingApiKeyException.php`, `src/Ai/InvalidApiKeyException.php`, `src/Ai/EffortMapper.php`
- Modify: `config/services.yaml` only if the Claude Code environment cannot be expressed in PHP (prefer PHP)
- Test: `tests/Ai/LlmGatewayTest.php`, `tests/Ai/KeyedPlatformProviderTest.php`

**Interfaces:**
- Produces:
  - `interface PlatformProvider { public function forCredential(Credential $credential): PlatformInterface; }` (throws `MissingApiKeyException` if that credential is not stored). Tests replace it with a stub returning `StubPlatform`.
  - `LlmGateway` tries the stored credentials of the provider in `Provider::credentials()` order (Claude Code: `Anthropic`, then `ClaudeOAuth`). On `InvalidApiKeyException` (CLI result `api_error_status` 401, HTTP 401 for the other bridges) it moves to the next stored credential; no other error triggers the fallback. All rejected → `InvalidApiKeyException` (handler stores `invalid_api_key`, no retry).
  - `final readonly class LlmAnswer { public function __construct(public string $text, public ?int $inputTokens, public ?int $outputTokens, public int $durationMs) {} }`
  - `LlmGateway::ask(LlmConfiguration $configuration, ?string $systemPrompt, string $userPrompt): LlmAnswer`
  - `LlmGateway::systemPrompt(iterable<SkillVersion> $skills): ?string` (ordered by title, joined with `"\n\n---\n\n"`, `null` when empty).
- [ ] **Step 1: Write failing tests**
  - `testOptionsAreOnlyWhitelisted`: `StubPlatform::$options` keys ⊆ `['tools', 'effort']`, `tools === []`.
  - `testSystemPromptJoinsSkillsByTitle` and `testNoSkillsMeansNoSystemPrompt` (input is a `MessageBag` with only a user message).
  - `testEffortIsPassedForClaudeCodeOnly` (until other providers are verified, see Step 3).
  - `testMissingKeyThrows`.
  - `testClaudeCodeFallsBackToOAuthTokenOn401`: API-key platform stub throws `InvalidApiKeyException`, OAuth stub answers → `LlmAnswer` from the OAuth call, exactly 2 invocations.
  - `testNoFallbackOnOtherErrors`: API-key stub throws a timeout → rethrown, OAuth stub not called.
  - `testAllCredentialsRejectedThrowsInvalidApiKey`.
  - `KeyedPlatformProviderTest::testClaudeCodeChildGetsOnlyItsCredential`: the environment built for a credential contains only that credential's variable (`ANTHROPIC_API_KEY` or `CLAUDE_CODE_OAUTH_TOKEN`), and `false` for every name asserted in `tests/Ai/ClaudeCodePlatformTest.php`; working directory is `%kernel.project_dir%/var/claude-runs` (empty, created if missing), not the project root.
  - `testPromptStartingWithDashIsNotReadAsFlag`: the Claude Code command built for prompt `--help` ends with `'--', '--help'` or passes the prompt so the CLI cannot parse it as an option (decorate `ModelClient::buildCommand` if needed).
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement.** Factories: `Bridge\OpenAi\Factory::createPlatform($key)`, `Bridge\Gemini\Factory::createPlatform($key)`, `Bridge\DeepSeek\Factory::createPlatform($key)`, `Bridge\HuggingFace\Factory::createPlatform($key, null)`, `Bridge\ClaudeCode\Factory::createPlatform(workingDirectory: ..., environment: ..., logger: new NullLogger())`. Effort mapping (all four HTTP bridges pass unknown options unchanged into the request body, verified in vendor; parameters verified in provider docs via context7 on 21.11.2026):

  | Provider | Option sent | `low` | `medium` | `high` | `xhigh` | `max` |
  | --- | --- | --- | --- | --- | --- | --- |
  | Claude Code | `effort` (CLI flag `--effort`) | low | medium | high | xhigh | max |
  | OpenAI (Responses API) | `reasoning: {effort: …}` | low | medium | high | xhigh | max |
  | DeepSeek | `reasoning_effort` | low | high¹ | high | high¹ | max |
  | Gemini 3.x | `thinkingConfig: {thinkingLevel: …}` (bridge puts options into `generationConfig`) | low | medium | high | high² | high² |
  | Gemini 2.5 | not supported (only numeric `thinkingBudget`) | – | – | – | – | – |
  | Hugging Face | not supported (depends on the inference provider) | – | – | – | – | – |

  ¹ DeepSeek maps `medium`/`xhigh` to `high` itself; send the mapped value so the stored config shows what was sent.
  ² Gemini 3 has no level above `high`; the result shows "effort capped at high".
  Probe on 21.11.2026 (real calls, prompt "Antworte nur mit OK", values `low` and `max`, Gemini `low` and `high`):

  | Model | Result |
  | --- | --- |
  | `gpt-5.5`, `gpt-5.4`, `gpt-5.4-mini`, `gpt-5.4-nano` | `low` OK; `max` 400 "Supported values are: 'none', 'low', 'medium', 'high', and 'xhigh'" → map `max` to `xhigh`, result shows "effort capped at xhigh" |
  | `gpt-4.1` | 400 "'reasoning.effort' is not supported with this model" → effort not supported |
  | `deepseek-chat`, `deepseek-reasoner`, `deepseek-v4-pro`, `deepseek-v4-flash` | `low` and `max` OK |
  | `gemini-3.5-flash` | `low` and `high` OK |
  | `gemini-3.1-pro-preview` | not verified (429 quota of the stored key) |
  | `gemini-2.5-pro`, `gemini-2.5-flash` | 404 "no longer available to new users" → remove from `Provider::models()` in Task 1 (existing run configurations keep them and fail with `unsupported_model`) |
  | Claude Code `haiku` | `--effort low` OK with the OAuth token (exit 0). The stored Anthropic API key is rejected (401 `authentication_error`, result subtype still `success`, CLI exit 1); `sonnet`/`opus` and `max` not probed. The bridge hides the reason (`Claude Code CLI process failed: "success"`): the gateway must classify it as permanent (no retry) and store error `invalid_api_key` when the CLI result has `api_error_status` 401 (read the last `type=result` line or decorate `RawProcessResult`). |

  `App\Ai\EffortMapper::map(LlmConfiguration $c): array{options: array<string, mixed>, note: ?string}` holds the table above; unsupported → no option and note `effort not supported`; capped → note `effort capped at <value>`. The run page shows the same note next to such a configuration.
  Measure duration with `hrtime`. Token usage from the result's metadata if present, else `null`.
- [ ] **Step 4: Run** → PASS; quality gates.

### Task 5: Execute handler

**Files:**
- Create: `src/MessageHandler/ExecuteRunResultHandler.php`, `src/Message/JudgeRunResult.php`, `src/Run/TransientFailure.php` (classifier)
- Modify: `config/packages/messenger.yaml` (route `JudgeRunResult` to `async`)
- Test: `tests/MessageHandler/ExecuteRunResultHandlerTest.php`

**Interfaces:**
- Consumes: Task 2 transitions, Task 4 `LlmGateway::ask()`, `LlmGateway::systemPrompt()`
- Produces: `final readonly class JudgeRunResult { public function __construct(public int $resultId) {} }`; `TransientFailure::isTransient(\Throwable $e): bool` (timeouts, HTTP 429, 5xx, process timeout → true; else false).
- [ ] **Step 1: Write failing tests**
  - `testSuccessStoresOutputAndDispatchesJudge`: status `judging`, output `stub answer`, one `JudgeRunResult`.
  - `testNonPendingResultIsSkipped`: result `done` → gateway not called.
  - `testTransientErrorResetsToPendingAndRethrows`: status back to `pending`, exception rethrown.
  - `testPermanentErrorFailsAndThrowsUnrecoverable`: status `failed`, `UnrecoverableMessageHandlingException`.
  - `testMissingKeyFailsWithNoApiKey`; `testUnsupportedHuggingFaceModelFailsWithUnsupportedModel`.
  - `testLateFinishDoesNotOverwriteTimedOutResult`: gateway stub sets the result `failed`/`timeout` before returning → result stays `failed`, no judge message.
  - `testLastRetryMarksFailed`: a `WorkerMessageFailedEvent` with `willRetry() === false` for `ExecuteRunResult` sets `failed`.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement** with `#[AsMessageHandler]` and an `#[AsEventListener]` for `WorkerMessageFailedEvent` (covers both message types). Re-read status before writing (`refresh`), write via the transition methods.
- [ ] **Step 4: Run** → PASS; quality gates.

### Task 6: Judge

**Files:**
- Create: `src/Run/Judge/CriteriaSplitter.php`, `src/Run/Judge/JudgePrompt.php`, `src/Run/Judge/CriterionVerdict.php` (readonly DTO), `src/Run/Judge/JudgeAnswerParser.php`, `src/MessageHandler/JudgeRunResultHandler.php`
- Modify: `templates/testcases/*` form help text: "One criterion per line."
- Test: `tests/Run/Judge/CriteriaSplitterTest.php`, `tests/Run/Judge/JudgeAnswerParserTest.php`, `tests/MessageHandler/JudgeRunResultHandlerTest.php`

**Interfaces:**
- Produces:
  - `CriteriaSplitter::split(string $outputCriteria): list<string>` (trim lines, drop empty).
  - `JudgePrompt::build(list<string> $criteria, string $output): string` (numbered criteria from 1, demands JSON only: `[{"index": 1, "met": true, "reason": "..."}]`).
  - `final readonly class CriterionVerdict { public function __construct(#[Assert\Positive] public int $index, public bool $met, #[Assert\NotBlank] public string $reason) {} }`
  - `JudgeAnswerParser::parse(string $answer, int $criteriaCount): list<CriterionVerdict>` via Serializer (`CriterionVerdict[]`) + Validator; throws `InvalidJudgeAnswer` unless indices are exactly `1..criteriaCount`; strips one surrounding Markdown code fence.
- [ ] **Step 1: Write failing tests**
  - Splitter: `"a\n\n  b  \n"` → `['a', 'b']`; `"\n \n"` → `[]`.
  - Parser: valid answer; missing index, duplicate index, extra index, `met` not bool, empty reason → `InvalidJudgeAnswer`; answer in ```` ```json ```` fence → accepted.
  - Handler: `testVerdictsAreStoredAndScoreComputed` (2 of 3 → 67, `done`); `testInvalidAnswerIsRetriedOnceThenFails` (gateway called twice, `failed`/`invalid_judge_answer`, output kept); `testNoCriteriaFailsWithoutJudgeCall`; `testUsesJudgeOfTheVersionNotTheCurrentSetting`; `testNonJudgingResultIsSkipped`.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement**; transient/permanent handling as in Task 5 (status stays `judging` on transient). Judge call has no system prompt and no effort.
- [ ] **Step 4: Run** → PASS; quality gates.

### Task 7: Version page matrix and polling

**Files:**
- Create: `src/Controller/RunResultController.php` (or action in `RunVersionController`), `templates/runs/_result_matrix.html.twig`, `assets/js/components/runProgress.ts`
- Modify: `templates/runs/version.html.twig`, `src/Controller/RunVersionController.php`
- Test: `tests/Controller/RunVersionPageTest.php`, `assets/js/tests/components/runProgress.test.ts`

**Interfaces:**
- Consumes: Task 2 `RunVersion::getStatus()`, `getResults()`, `getSkippedConfigurations()`; Task 3 `StaleResultSweeper::sweep()`
- Produces: `GET /runs/{uuid}/versions/{number}/progress` (`app_run_version_progress`, `#[IsGranted(RunVoter::VIEW)]`) → JSON `{status: string, done: int, total: int, html: string}`; JS `startRunProgress(root: HTMLElement, fetchFn?: typeof fetch): () => void` polls every 3000 ms while `status === 'running'`, replaces the matrix, stops otherwise; returns a stop function.
- [ ] **Step 1: Write failing tests**
  - PHP: matrix shows rows per testcase, columns per configuration, cell `80 %` for a done result, `failed – timeout` for a failed one, column `skipped – no API key`; cell detail lists each criterion with `met`/`not met` and reason; progress endpoint returns `{"status":"running","done":1,"total":3,...}`; user without project access gets 403.
  - Vitest: polls while running, stops on `completed`, does not poll when the page loads with a finished status, honors the returned stop function.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement** following `docs/Design.md` (tokens, view transitions; no new package). Output and reason rendered escaped (Twig default), never `|raw`.
- [ ] **Step 4: Run** → PASS; quality gates.

### Task 8: Compare scores

**Files:**
- Modify: `src/Run/RunVersionDiffer.php`, `src/Run/RunVersionDiff.php`, `templates/runs/compare.html.twig`
- Test: `tests/Run/RunVersionDifferTest.php`

**Interfaces:**
- Produces: `RunVersionDiff::$scores: list<array{testcase: string, configuration: string, from: ?int, to: ?int}>` for cells present in both versions (matched by testcase ID and `configKey`); `RunVersionDiff::$averages: array<string, array{from: ?float, to: ?float}>` keyed by configuration label. Skipped configurations are not reported as removed.
- [ ] **Step 1: Write failing tests**: score delta per cell; cell only in one version is not in `$scores`; a configuration skipped in `to` but configured in both is not listed under removed configurations; averages ignore `null` scores.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement**; template shows `from → to (±delta)`.
- [ ] **Step 4: Run** → PASS; quality gates.

### Task 9: Worker service and documentation

**Files:**
- Modify: `compose.yaml` or `compose.override.yaml` (service `worker`: same image, `bin/console messenger:consume async -vv --time-limit=3600`, restart policy, no published ports), `docs/features/runs.md`, `docs/features/llm-configuration.md`, `docs/features/testcases.md`, `docs/Features.md`, `docs/Commands.md` (if a command was added), `AGENTS.md`
- Test: manual check below

- [ ] **Step 1:** Add the worker service; `docker compose up -d worker` and `docker compose logs worker` shows the consumer waiting.
- [ ] **Step 2:** Update the feature docs to the spec (matrix, start rules, skipped configurations, judge, score, timeout, pages incl. progress endpoint); in `docs/features/llm-configuration.md` replace "if both are set, the API key wins" with the 401 fallback to the OAuth token, and list the effort mapping table from Task 4.
- [ ] **Step 3:** `AGENTS.md`: replace the quota sentence with the decision "No quota per project or user (product decision 21.11.2026); the start confirmation shows the number of calls"; update the "Runs that call an LLM" block to name `App\Ai\LlmGateway` as the existing gateway.
- [ ] **Step 4:** End-to-end check with a stub-free dev run only if the user provides a test key: start a run with 1 configuration × 1 synthetic testcase, version page reaches `completed`, score shown. Otherwise report this step as not done.
- [ ] **Step 5:** All quality gates.
