# LLM configuration

Credentials for the LLM providers, the Hugging Face models admins add, and the model of the LLM judge. Admins manage them at `/admin/llm-configuration`; nobody else has access.

## Providers and credentials

A **provider** is what a run uses; a **credential** is what an admin stores. Both are defined in code (`App\Ai\Provider`,
`App\Ai\Credential`) and are global, available in every project.

| Provider | Credentials | Models |
| --- | --- | --- |
| OpenAI | API key | `gpt-*` |
| Google Gemini | API key | `gemini-*` |
| DeepSeek | API key | `deepseek-*` |
| Claude Code | Anthropic API key or Claude OAuth token | `opus`, `sonnet`, `haiku` |
| Hugging Face | Access token | the models an admin added, see [Hugging Face models](#hugging-face-models) |

A provider is **available** when at least one of its credentials is stored. Only available providers are offered in the
run's model select and as judge, and the start checks it again on the server (see [Runs](runs.md#starting-a-run)).

Claude Code (`symfony/ai-claude-code-platform`) runs the Claude Code CLI. It needs one of the two credentials. A call
uses the Anthropic API key first; if the CLI answers 401 with it and an OAuth token is stored, the same call is repeated
once with the OAuth token. Only when every stored credential is rejected does the cell fail with `invalid_api_key`. There
is no separate Anthropic provider: `symfony/ai-anthropic-platform` is not installed, the Anthropic API key is a credential
of Claude Code.

Runs read the keys only from the database (decrypted per call), never from the environment, and build a platform per
call (`App\Ai\LlmGateway`, `App\Ai\KeyedPlatformProvider`). The Claude Code CLI child gets an empty working directory
(`var/claude-runs`), the prompt on stdin, the skills as a system prompt file with mode 0600, no CLI tools, no saved
session (`--no-session-persistence`), no MCP servers from settings (`--strict-mcp-config`) and an environment with only the
one credential in use plus `PATH` and `HOME`. A CLI result with `api_error_status` 429 or 5xx is retried like the HTTP
providers.

## Effort mapping

The optional effort of a run configuration is sent as the option the provider knows; the option names are fixed in code
(`App\Ai\EffortMapper`), nothing user-controlled reaches a provider. Verified against the provider docs and probed with
real calls on 21.11.2026.

| Provider | Option sent | `low` | `medium` | `high` | `xhigh` | `max` |
| --- | --- | --- | --- | --- | --- | --- |
| Claude Code | `effort` (CLI flag `--effort`) | low | medium | high | xhigh | max |
| OpenAI `gpt-5.*` | `reasoning.effort` | low | medium | high | xhigh | xhigh (capped) |
| OpenAI `gpt-4.1` | not supported | – | – | – | – | – |
| DeepSeek | `reasoning_effort` | low | high | high | high (capped) | max |
| Gemini 3.x | `thinkingConfig.thinkingLevel` | low | medium | high | high (capped) | high (capped) |
| Hugging Face | not supported (depends on the inference provider) | – | – | – | – | – |

Where a provider or model has no effort parameter, the call goes out without it and the version page shows *effort not
supported* in the column header. Where the provider has no level as high as asked, the nearest lower one is sent and the
column shows *effort capped at <level>*. DeepSeek maps `medium` and `xhigh` to `high` itself; the mapped value is sent,
flagged as capped for `xhigh` but not for `medium`. The run page shows the same note next to each configuration. Models listed in `App\Ai\Provider::models()` that were not probed
(`gemini-3.1-pro-preview`, Claude Code `sonnet` and `opus`) use the same mapping. The Gemini 2.5 models are no longer
offered (the API answers 404 for new users); runs that still hold them fail with `unsupported_model`.

## Hugging Face models

Hugging Face (`symfony/ai-hugging-face-platform`) has no fixed model list, so its models are added by an admin and stored in
the table `huggingface_model` (model ID only, for example `meta-llama/Llama-3.1-8B-Instruct`). Hugging Face picks the
inference provider when a model runs. Only added models are offered in run configurations and as judge; with none added the
*Hugging Face* group is missing from the selects.

- The Hugging Face card on `/admin/llm-configuration` lists the added models, each with *Remove*, and links to *Discover
  models*. Removing a model does not change existing runs: they keep their stored configuration.
- *Discover models* (`/admin/llm-configuration/huggingface/models`) searches the Hub. Filters: search term, task (default
  `text-generation`, empty means any), inference provider and *Warm only*. It shows the first 50 matches with their task and
  live inference providers. *Add* checks that the Hub knows the model, then stores it. Models already added show *Added*.
- The stored access token is sent to the Hub if one is set. Without a token the Hub still answers but may limit requests.
- Search terms go to Hugging Face. Do not enter personal data.

The same discovery is available on the command line: `ai:huggingface:model-list` (options `--provider`, `--task`,
`--search`, `--warm`) and `ai:huggingface:model-info <model>`. They come from the bridge and use the token stored here. See
[Commands.md](../Commands.md).

## LLM judge

The judge rates run outputs against the output criteria of a testcase. The page has one select, grouped by provider, with
the models of all available providers (the same list as for run configurations). *Save* stores the choice in the table
`judge_setting`, a single global row. Until a choice is saved the page shows *Not set*, and no run can start (see
[Runs](runs.md#starting-a-run)); the same holds while the saved model is no longer offered (removed from the code list or,
for Hugging Face, deleted by an admin). The judge is global, not per project, and has no effort setting. A start copies the
setting into the run version, which keeps rating with that model even if the setting changes later. How the judge rates
is described in [Runs](runs.md#execution) and [Testcases](testcases.md).

## API keys page section

Below the judge form, `/admin/llm-configuration` shows one card per provider. Each credential has a status (`••••` plus the last four characters and the
time of the last change, or *Not set*) and the buttons *Set key* / *Replace key* (opens a dialog with the key field; an
invalid key reopens it with the error) and *Delete*. A provider with at least one stored credential gets a green dot.

## Handling

- Saving a key for a credential replaces the existing one (at least 16 characters). Deleting removes it.
- A stored key is never shown again. The page only shows `••••` plus the last four characters and the time of the last
  change.
- The [overview](overview.md) shows admins how many providers have a credential and which ones are missing.

## Storage

Keys are stored encrypted with libsodium (`secretbox`, XSalsa20-Poly1305) using the key in the environment variable
`API_KEY_ENCRYPTION_KEY`. It must be a base64 encoded 32 byte key. Generate one with:

```bash
php -r 'echo base64_encode(sodium_crypto_secretbox_keygen());'
```

The encryption is reversible on purpose, because a provider needs the plain key on every request. Losing the
encryption key means the stored keys cannot be read anymore and have to be entered again.

## Import from the environment

`bin/console app:api-keys:import-env` copies provider keys set in the environment (for example `.env.local`) into the
database. `composer setup` runs it during installation. See [Commands.md](../Commands.md).

## Current limits

The stored keys are used by the queue worker (`messenger:consume`), which decrypts them per call; the web app itself only stores
them (and sends the Hugging Face token to the Hub for model discovery). A key is checked only when a call uses it: a wrong key shows up as `invalid_api_key` on the cells.
