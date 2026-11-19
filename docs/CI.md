# CI checks

There is **no CI pipeline configured in this repository yet** (no `.github/`, no `.gitlab-ci.yml`).
This file lists the checks a pipeline must run and the exact commands, so a pipeline can be set up without guessing.
Update it when a pipeline is added.

All commands run from the repository root. The Docker image (`Dockerfile`) is PHP 8.5 without Node, so a pipeline
needs a PHP 8.5 job and a Node job (version in `.nvmrc`).

## PHP checks

| Check                 | Command                                           | Notes                                                                                                                   |
| --------------------- | ------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------- |
| Install               | `composer install --no-interaction --prefer-dist` |                                                                                                                         |
| PHPStan (level `max`) | `vendor/bin/phpstan analyse --no-progress`        | Needs the dev container XML, run `php bin/console cache:warmup` first (`var/cache/dev/App_KernelDevDebugContainer.xml`) |
| PHP CS Fixer          | `vendor/bin/php-cs-fixer fix --dry-run --diff`    | Check only, violations fail the job                                                                                     |
| Twig CS Fixer         | `vendor/bin/twig-cs-fixer lint`                   | Check only, violations fail the job                                                                                     |
| PHPUnit               | `php bin/phpunit`                                 |                                                                                                                         |

The minimum PHP version in `composer.json` is `>=8.5`. PHP CS Fixer warns when it runs on a newer PHP than that
minimum, so keep the job's PHP version equal to the minimum.

## Frontend checks

| Check      | Command                | Notes                                                 |
| ---------- | ---------------------- | ----------------------------------------------------- |
| Install    | `npm ci`               |                                                       |
| Prettier   | `npm run format:check` | `assets/` only                                        |
| Type check | `npx tsc --noEmit`     |                                                       |
| Vitest     | `npm test`             |                                                       |
| Build      | `npm run build`        | Production build, fails on TypeScript and Sass errors |

See [webpack.md](webpack.md) for the frontend setup.

## Suggested order

1. Install dependencies (`composer install`, `npm ci`).
2. Static checks in parallel: PHPStan, PHP CS Fixer, Twig CS Fixer, Prettier, type check.
3. Tests in parallel: PHPUnit, Vitest.
4. Production build.

## Local equivalents

The pre-commit hook (`.githooks/pre-commit`) runs the fixers in **write** mode on staged files:
Prettier for `assets/`, PHP CS Fixer for `*.php`, Twig CS Fixer for `templates/*.twig`. The hook is the first line of
defence, the pipeline checks the whole repository in **check** mode.

Fix violations locally with `npm run format`, `vendor/bin/php-cs-fixer fix` and `vendor/bin/twig-cs-fixer fix`.
