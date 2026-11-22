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
| `league:oauth2-server:generate-keypair` | Create the key pair that signs the OAuth access tokens of the [MCP server](features/mcp.md) (`league/oauth2-server-bundle`) |
| `league:oauth2-server:clear-expired-tokens` | Delete expired access tokens, refresh tokens and authorization codes (same package; run it from a daily cron job) |
| `doctrine:fixtures:load --append --group=demo` | Reset the demo data (dev only, from `doctrine/doctrine-fixtures-bundle`), see [Demo data](#demo-data) |

## `app:user:create`

Creates a database user. The password is asked twice with hidden input and has no requirements (only non-empty), so the command refuses to run with
`--no-interaction`.

```bash
bin/console app:user:create <username> [--admin]
```

| Argument / option | Description |
| --- | --- |
| `username` | 3 to 64 characters: `a-z`, `0-9`, `.`, `_`, `-`. Must be unique. |
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

## Demo data

`src/DataFixtures/DemoFixtures.php` (group `demo`, package `doctrine/doctrine-fixtures-bundle`, installed with `--dev`
only) creates the demo state: the users `admin` (admin) and `user`, the groups `Development`, `Project Management` and
`Copywriting` (`user` is in all three, `admin` in none), six projects with their skills and testcases, and eight runs. The runs
have no LLM configuration and were never started.

```bash
bin/console doctrine:fixtures:load --append --group=demo
```

- The passwords are `admin` and `user`. Because the bundle is a dev dependency, they cannot be loaded in production.
- The fixture deletes all groups, projects, skills, testcases and runs and the two users `admin` and `user` first, then
  recreates them. Other users stay (they lose their group memberships).
- Always pass `--append`. Without it the bundle purges every table, including the stored API keys, the LLM settings and the
  OAuth clients of the [MCP server](features/mcp.md).
- API keys are not part of the demo data: add them with `app:api-keys:import-env` or in the LLM configuration.

## OAuth keys for the MCP server

The commands `league:oauth2-server:generate-keypair` and `league:oauth2-server:clear-expired-tokens` come from
`league/oauth2-server-bundle`. After a fresh checkout:

1. Put `OAUTH_PASSPHRASE` and `OAUTH_ENCRYPTION_KEY` into `.env.local` (any long random strings, for example
   `openssl rand -hex 32`). `.env` only holds empty defaults.
2. Run `bin/console league:oauth2-server:generate-keypair`. It writes `config/jwt/private.pem` and `public.pem`
   (git-ignored). Existing keys are kept unless you pass `--overwrite`, which signs out every connected agent.

The test environment has its own throw-away values in `.env.test` and its own files `config/jwt/test-*.pem`
(`bin/console league:oauth2-server:generate-keypair --env=test`).
