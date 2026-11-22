# AGENTS.md

This is a Symfony project. Check `composer.json` for the exact Symfony/PHP version
in use, and read `symfony.lock` to see which recipes ran. Don't assume Doctrine,
Twig, API Platform, Messenger, or Lock are installed unless one of those says so.

## Ask before generating

If the task doesn't specify, ask rather than guess:

- Persistence: Doctrine ORM, Doctrine ODM, or none?
- Interface: server-rendered (Twig), API (Serializer, maybe API Platform), or both?
- Auth: SecurityBundle, and which authenticator?

If you can't ask (no interactive channel), state the assumption you're making and
pick the smallest option (e.g. no persistence layer) rather than scaffolding a
full stack nobody asked for.

## Adding features: Flex, not hand-wiring

Install new capabilities with `composer require <package>` (e.g. `symfony/lock`,
`symfony/messenger`, `orm-pack`) and let the Flex recipe register the bundle and
generate its config. Don't hand-edit `config/bundles.php` or hand-write a bundle's
base config; that's what the recipe is for. Don't skip a good-fit component just
because it isn't installed yet; installing it is one command.

## Conventions

Follow https://symfony.com/doc/current/best_practices.html to write idiomatic
Symfony:

- Use PHP attributes for framework metadata, and not only on controllers:
  `#[Route]`, `#[MapRequestPayload]`, `#[IsGranted]` on actions, `#[Assert\...]`
  on properties, `#[AsCommand]`, `#[AsEventListener]`, `#[AsMessageHandler]`, and
  `#[AsAlias]` / `#[AsTaggedItem]` / `#[Autoconfigure]` on services. No YAML or
  XML routing.
- Rely on autowiring and autoconfiguration. Type-hint constructor arguments and
  let the container resolve them. Where a type-hint can't express it, stay in the
  class with `#[Autowire]` (parameters, env vars, expressions) or `#[Target]` (one
  of several implementations of an interface). A YAML service definition is the
  last resort, not the first.
- Controllers extend `AbstractController`, stay thin, and delegate to services.
- Use the framework for what it already does: Form for server-rendered forms,
  Validator for validation, Serializer for JSON, Messenger for async work,
  Security (voters, authenticators) for access control, Twig `path()`/`url()`
  instead of hardcoded URLs.
- Before hand-writing infrastructure (locks, queues, caches, HTTP clients,
  mailers, schedulers) or reaching for a third-party library, check whether a
  Symfony component covers it. It usually does.

Three specifics worth spelling out, because they are easy to get wrong:

- Bind request data with `#[MapRequestPayload]` / `#[MapQueryString]` on action
  arguments, which wires up Serializer and Validator for you, instead of calling
  `json_decode()` or `SerializerInterface` by hand. If neither package is
  installed yet, `composer require` them rather than falling back to manual
  parsing.
- Use constructor property promotion, and `readonly` for DTOs and value objects.
- Use `symfony/lock` (`LockFactory`) for mutual exclusion. A hand-built flag or
  lock file looks fine in review and is usually wrong under concurrency.

## Everyday workflow

- Run the app with `symfony serve -d`, and commands with `symfony console ...`
  (or `bin/console` when the Symfony CLI isn't available).
- When something fails, read `var/log/dev.log` and the web profiler
  (`/_profiler`) before changing code.
- If `maker-bundle` is installed, prefer `bin/console make:*` with every argument
  passed up front and `--no-interaction` where supported: makers prompt on a
  terminal by default, which hangs a non-interactive shell. If a maker still
  needs interactive input, hand-write the code instead.
- If Doctrine ORM is installed, schema changes go through migrations
  (`bin/console make:migration`, then `doctrine:migrations:migrate`), never
  `doctrine:schema:update` or hand-written SQL.
- `.env` is committed and holds defaults only. Real secrets belong in `.env.local`
  (git-ignored) or the secrets vault (`bin/console secrets:set`), read via
  `%env(...)%`.

## Testing

Install `symfony/test-pack` if it isn't already. Functional/HTTP tests extend
`WebTestCase`; service-level tests extend `KernelTestCase`. Run
`php bin/phpunit` (falls back to `vendor/bin/phpunit`). A feature isn't done
until it has a test that exercises it the way a caller would, an HTTP request for
a controller or a service call for a service, not just "it didn't throw."

## Code style

Symfony's coding standard, the `@Symfony` php-cs-fixer ruleset (a PSR-12-derived
superset). Run `vendor/bin/php-cs-fixer fix` if `friendsofphp/php-cs-fixer` is
installed; it isn't part of the skeleton by default.

## Tooling and quality checks

Run all of these before declaring a task done. They must exit with code 0.

- `php bin/phpunit`: PHP tests.
- `vendor/bin/phpstan analyse --no-progress`: PHPStan at level `max` (`phpstan.dist.neon`). It needs the dev
  container, run `bin/console cache:warmup` first if `var/cache/dev/App_KernelDevDebugContainer.xml` is missing.
  Fix the cause, do not add ignores or baselines.
- `vendor/bin/php-cs-fixer fix` / `vendor/bin/twig-cs-fixer fix`: code style for PHP (`@Symfony`) and Twig. Add
  `--dry-run --diff` (PHP) or use `lint` (Twig) to only check.
- `npm test`: Vitest. Tests live in `assets/js/tests/` (`*.test.ts`) and are not picked up elsewhere.
- `npm run format:check` / `npm run format`: Prettier, applies to `assets/` only.
- `npx tsc --noEmit`: TypeScript type check.

The Docker image contains Node only for the Claude Code CLI (`symfony/ai-claude-code-platform`, key via
`ANTHROPIC_API_KEY` or `CLAUDE_CODE_OAUTH_TOKEN` in `.env.local`). Frontend npm commands still run on the host (version in `.nvmrc`).

### Pre-commit hook

`.githooks/pre-commit` formats staged files (Prettier for `assets/`, PHP CS Fixer for `*.php`, Twig CS Fixer for
`templates/*.twig`) and re-stages them. If PHP files or `phpstan.dist.neon` are staged, it then runs PHPStan over the
whole project and aborts the commit on errors. It is enabled per clone:

```bash
git config core.hooksPath .githooks
```

### More documentation

- [docs/webpack.md](docs/webpack.md): Encore setup, components, commands, troubleshooting. Keep it up to date when
  `webpack.config.js` or the frontend setup changes.
- [docs/Design.md](docs/Design.md): design tokens, app shell and page transitions. **All page navigation uses
  cross-document view transitions**; read it before adding a page or touching `base.html.twig` or the CSS. Keep it up to
  date when tokens or the transition setup change.
- [docs/Features.md](docs/Features.md): feature overview, with one page per feature in `docs/features/`. Keep it up to
  date when a feature's behavior, rules or pages change.
- [docs/Commands.md](docs/Commands.md): the app's console commands (`src/Command/`). Keep it up to date when a
  command is added or changed.
- [docs/CI.md](docs/CI.md): the GitHub Actions pipeline (`.github/workflows/ci.yml`). Keep it up to date when
  `.github/` changes.

## Before production and for runs that call LLM providers

Runs call LLM providers now. These points are rules for the deployment and for changes to the run code; some of them
are not covered by code yet:

- **Production setup**: set `APP_ENV=prod`, a real `APP_SECRET` (the kernel refuses to boot in prod without one),
  `API_KEY_ENCRYPTION_KEY` and the database password (`POSTGRES_PASSWORD`). Behind a reverse proxy that terminates TLS set
  `TRUSTED_PROXIES` (comma separated) and configure trusted hosts. Do not ship the `Dockerfile` as it is: it uses the
  development `php.ini`, runs as root and bind-mounts the code. Add a production stage (`php.ini-production`, non-root
  user, `composer install --no-dev --classmap-authoritative`).
- **Dev stack**: `compose.override.yaml` publishes the app, database and mail catcher on `127.0.0.1` only. Keep it that way;
  the web profiler has no authentication and shows request data. Do not keep real provider keys in `.env.local` while
  developing, the keys stored in the database are enough. The `worker` service runs with `APP_DEBUG=0`, because Doctrine's
  SQL logging (on with `kernel.debug`) would write outputs and judge reasons to `var/log/dev.log`; keep it that way.
- **Runs that call an LLM**: every call goes through one gateway service, `App\Ai\LlmGateway`, which passes a fixed option
  whitelist (`tools: []` for Claude Code plus the effort option of `App\Ai\EffortMapper`) to a platform that
  `App\Ai\KeyedPlatformProvider` builds per call from the key stored in the database (no key is read from the
  environment, one source only). Never forward user-controlled option names. Run the container as a non-root user (the
  `Dockerfile` and the dev `worker` still run as root). Prompts must not reach logs or the process list (the Claude Code
  platform logger is a `NullLogger` on purpose). No quota per project or user (product decision 21.11.2026); the start
  confirmation shows the number of calls. The cells run in the `worker` service (`messenger:consume async`); in production
  that process must run, otherwise started versions stay `pending`. The lock that prevents a double start uses
  `LOCK_DSN=flock`, which is local to one host: with several app replicas point it at a shared store (for example Redis or
  the database).
- **CLI child**: `App\Ai\ClaudeCodeModelClient` sends the prompt via stdin (never as an argument, so a prompt starting
  with `-` cannot be read as a flag and nothing shows up in the process list) and the skills as a system prompt file made
  with `tempnam()` (mode 0600) in the system temp directory. It runs in the neutral working directory `var/claude-runs`
  (not the project root, which holds `.env.local` and `.claude/settings.json`) and with an environment that contains only
  the one credential in use plus `PATH` and `HOME`. Every call gets the fixed flags `--no-session-persistence` (no
  transcript under `$HOME/.claude/projects`) and `--strict-mcp-config` (no MCP servers from user or project settings);
  they are set in the client, not through options, so no caller can drop them. The temp file is removed after the call; a SIGKILL of the worker can
  leave one behind in the system temp directory.
- **Shared skills**: a skill in several projects is editable by members of any one of them and changes the input of runs in
  all of them. The skill page shows how many projects the editor cannot see. Runs execute now, so this is an open
  decision: confirm that it is acceptable or restrict editing.
- **Personal data**: free text may contain personal data despite the hints. Versions and run snapshots are immutable and
  there is no purge in the interface, so a deletion request needs a database intervention.
- **Supply chain**: run `composer audit` and `npm audit` regularly. GitHub Actions are pinned by tag, not by commit.
- **Kept on purpose**: the versioning code exists twice (`Skill`, `Testcase`); packages for mail and notifications stay
  installed for planned features. Messenger is in use: it queues the run cells.

## AI usage and data protection

Prompts sent via `app:ask-claude` or the `PlatformInterface` leave the application and go to Anthropic. Never put
personal data (names, e-mail addresses, IDs, customer content) into prompts, fixtures or logs unless a data processing
agreement covers it. Use pseudonymized or synthetic data instead.

Permissions for Claude Code live in `.claude/settings.json` (secrets, destructive commands, git, paid calls). Personal
overrides go to `.claude/settings.local.json`, which is git-ignored.

## Discover, don't guess

Framework APIs change between versions and your training data may be stale. Look
things up in the project instead of relying on memory:

- `bin/console about`: versions, environment, paths.
- `bin/console debug:router`, `debug:container`, `debug:autowiring <name>`,
  `debug:config <bundle>`, `config:dump-reference <bundle>`: what exists and how
  it is configured.
- `bin/console lint:container`, plus `lint:twig templates/` and
  `lint:yaml config/` where those packages are installed: validate before running.
- Read the installed source and docblocks under `vendor/`.
- Docs: https://symfony.com/doc/current/ (switch to the version matching
  `composer.json` if it differs).
