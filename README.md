# Dojo

Platform to create, evaluate and test LLM skills. Symfony project with a Webpack Encore frontend, PostgreSQL, a Messenger
worker for the run cells and an MCP server.

## Installation

Follow the steps in order. At the end the app runs at http://localhost:8000 and you can sign in as admin.

### 1. Requirements

| Tool | Notes |
| --- | --- |
| Git | |
| Docker with Compose v2 | PHP 8.5, PostgreSQL 16 and Mailpit run in containers, no local PHP needed |
| Node.js | Version in `.nvmrc` (24). Runs on the host, only for the frontend build. With nvm: `nvm install && nvm use` |
| OpenSSL | Used to generate secrets (preinstalled on macOS and Linux) |

Port `8000` on `127.0.0.1` must be free.

### 2. Clone the repository

```bash
git clone <repository-url> dojo
cd dojo
```

### 3. Create `.env.local`

`.env` holds empty defaults only. The app needs four secrets, otherwise `composer setup` and the MCP server fail.
`.env.local` is git-ignored and never committed.

```bash
cat >> .env.local <<EOF
APP_SECRET=$(openssl rand -hex 32)
API_KEY_ENCRYPTION_KEY=$(openssl rand -base64 32)
OAUTH_PASSPHRASE=$(openssl rand -hex 32)
OAUTH_ENCRYPTION_KEY=$(openssl rand -hex 32)
EOF
```

Optional: add provider keys to `.env.local` now. `composer setup` (step 6) copies them encrypted into the database.
You can also enter them later in the web interface (step 9).

```dotenv
OPENAI_API_KEY=
GEMINI_API_KEY=
DEEPSEEK_API_KEY=
HUGGINGFACE_API_KEY=
# Claude Code: set one of both, the API key wins
ANTHROPIC_API_KEY=
CLAUDE_CODE_OAUTH_TOKEN=
```

Do not put real keys of other people or customers here. Never commit `.env.local`.

Losing `API_KEY_ENCRYPTION_KEY` makes the stored provider keys unreadable; they would have to be entered again.

### 4. Start the containers

```bash
docker compose up -d --build
```

This builds the image (the first build takes a few minutes) and starts `app`, `worker`, `database` and `mailer`.
The `worker` restarts by itself until the dependencies and the schema exist (steps 5 and 6). That is expected.

### 5. Install PHP dependencies

```bash
docker compose exec app composer install
```

### 6. Create the OAuth keys and the database schema

```bash
docker compose exec app bin/console league:oauth2-server:generate-keypair
docker compose exec app composer setup
```

The first command writes `config/jwt/private.pem` and `public.pem` (git-ignored) with the passphrase from `.env.local`.
`composer setup` runs the migrations and imports the provider keys from `.env.local`.

### 7. Create the first admin

There is no built-in admin account. The command asks for the password twice.

```bash
docker compose exec app bin/console app:user:create <username> --admin
```

Alternative for a throw-away dev setup: load the demo data. It creates the users `admin` and `user` (the passwords are
the same as the user names), groups, projects, skills, testcases and runs. Dev only, always with `--append`
(see [docs/Commands.md](docs/Commands.md#demo-data)).

```bash
docker compose exec app bin/console doctrine:fixtures:load --append --group=demo
```

### 8. Build the frontend

```bash
npm ci
npm run build
```

For frontend development run `npm run watch` instead of `npm run build`.

### 9. Open the app and configure the LLM

1. Open http://localhost:8000 and sign in.
2. Open `/admin/llm-configuration`. Check that your providers have a green dot, or set the keys there.
3. Choose the **LLM judge** and the **AI helper** on the same page. Without a judge no run can start.

### 10. Enable the pre-commit hook (for contributors)

```bash
git config core.hooksPath .githooks
```

## Check that it works

```bash
docker compose ps
```

`app`, `database`, `mailer` and `worker` must be `running` (the `worker` stays in `restarting` for a moment if you
look too early). If a service is not, read its log with `docker compose logs <service>`.

Mails sent by the app are caught by Mailpit. Its port is random: `docker compose port mailer 8025`.

## Daily use

```bash
docker compose up -d     # start
docker compose down      # stop (the database volume stays)
npm run watch            # frontend development
```

After pulling new code: `docker compose exec app composer install`, `docker compose exec app composer setup` (new
migrations) and `npm ci && npm run build`.

## Troubleshooting

- `API_KEY_ENCRYPTION_KEY must be a base64 encoded 32 byte key`: the variable is missing or has the wrong length in
  `.env.local`. Redo step 3.
- Started runs stay `pending`: the `worker` is not running. Check `docker compose ps` and `docker compose logs worker`.
- Port 8000 is in use: stop the other service or change the port mapping in `compose.override.yaml`.

## Before production

The setup above is for development only. Read the section "Before production and for runs that call LLM providers" in
[AGENTS.md](AGENTS.md) first: the `Dockerfile` runs as root with the development `php.ini`, and several secrets and
host settings are required.

## Documentation

- [Features](docs/Features.md): what the app does, one page per feature in `docs/features/`.
- [Commands](docs/Commands.md): console commands, demo data and OAuth keys.
- [MCP server](docs/features/mcp.md): connect an AI agent such as Claude Code to Dojo.
- [Webpack](docs/webpack.md), [Design](docs/Design.md), [CI](docs/CI.md): frontend, design system and pipeline.
