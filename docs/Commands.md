# Commands

Custom console commands of this app (`src/Command/`). Run them with `symfony console <name>` or `bin/console <name>`.
`bin/console list app` shows the current list, `bin/console <name> --help` the arguments and options.

| Command | Purpose |
| --- | --- |
| `app:user:create` | Create a database user or admin |
| `app:api-keys:import-env` | Copy provider API keys from the environment into the database |
| `app:ask-claude` | Send a prompt to the Claude Code platform |
| `ai:huggingface:model-list` | List Hugging Face Hub models (from `symfony/ai-hugging-face-platform`) |
| `ai:huggingface:model-info` | Show the inference providers of one Hugging Face model (same package) |

## `app:user:create`

Creates a database user. The password is asked twice with hidden input, so the command refuses to run with
`--no-interaction`.

```bash
bin/console app:user:create <username> [--admin]
```

| Argument / option | Description |
| --- | --- |
| `username` | 3 to 64 characters: `a-z`, `0-9`, `.`, `_`, `-`. Must be unique. The password asked afterwards needs at least 12 characters. |
| `--admin` | Create the user with `ROLE_ADMIN` instead of `ROLE_USER` |

There is no built-in admin account. Create the first admin with this command after deploying to a fresh database:

```bash
bin/console app:user:create <username> --admin
```

## `app:api-keys:import-env`

Copies API keys set in the environment (e.g. `.env.local`) into the database, encrypted with
`API_KEY_ENCRYPTION_KEY`. No arguments or options.

```bash
bin/console app:api-keys:import-env
```

| Credential | Environment variable |
| --- | --- |
| OpenAI API key | `OPENAI_API_KEY` |
| Google Gemini API key | `GEMINI_API_KEY` |
| DeepSeek API key | `DEEPSEEK_API_KEY` |
| Anthropic API key (Claude Code) | `ANTHROPIC_API_KEY` |
| Claude OAuth token (Claude Code) | `CLAUDE_CODE_OAUTH_TOKEN` |
| Hugging Face access token | `HUGGINGFACE_API_KEY` |

Per credential: an empty variable is skipped, and a key already stored in the database is kept, not overwritten.
To replace a stored key, use the API keys section of the LLM configuration page at `/admin/llm-configuration`.

## `app:ask-claude`

Sends a prompt to the Claude Code platform and prints the text answer. The CLI's built-in tools are disabled, so it
only generates text. Needs `ANTHROPIC_API_KEY` or `CLAUDE_CODE_OAUTH_TOKEN` in `.env.local`.

```bash
bin/console app:ask-claude "<prompt>" [-m|--model=haiku] [--effort=medium]
```

| Argument / option | Description |
| --- | --- |
| `prompt` | The prompt to send |
| `-m`, `--model` | Model alias, default `haiku`. Suggested: `haiku`, `sonnet`, `opus` |
| `--effort` | `low`, `medium` (default), `high`, `xhigh`, `max` |

The prompt leaves the application and goes to Anthropic, and every call is billed. Never put personal data (names,
e-mail addresses, IDs, customer content) into the prompt.

## `ai:huggingface:model-list` and `ai:huggingface:model-info`

Both commands come from `symfony/ai-hugging-face-platform` and are registered in `config/services.yaml`. They query the
Hugging Face Hub with the access token stored in the LLM configuration (without a token the Hub may limit requests). The web
discovery at `/admin/llm-configuration/huggingface/models` uses the same client. The commands only print: models are added
in the web interface.

```bash
bin/console ai:huggingface:model-list [-p|--provider=groq] [-t|--task=text-generation] [-s|--search=llama] [-w|--warm]
bin/console ai:huggingface:model-info <model-name>
```

Search terms leave the application and go to Hugging Face. Do not use personal data.
