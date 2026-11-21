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

Claude Code (`symfony/ai-claude-code-platform`) runs the Claude Code CLI. It needs one of the two credentials; if both are
set, the API key wins. There is no separate Anthropic provider: `symfony/ai-anthropic-platform` is not installed, the
Anthropic API key is a credential of Claude Code.

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
all models of all providers (the same list as for run configurations). *Save* stores the choice in the table
`judge_setting`, a single global row. Until a choice is saved the page shows *Not set*. The judge is global, not per
project, and has no effort setting. The choice is only stored so far: nothing calls the judge yet.

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

Runs do not call the providers yet, so the stored keys are not used by the web app so far.
