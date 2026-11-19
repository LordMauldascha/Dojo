# CI

The pipeline is a GitHub Actions workflow on GitHub-hosted runners (`ubuntu-latest`, no self-hosted runner).

| File | Purpose |
| --- | --- |
| `.github/workflows/ci.yml` | The pipeline |
| `.github/actions/setup-php-app/action.yml` | Composite action: PHP 8.5, Composer cache, `composer install` |

Triggers: pull requests, pushes to `main` and manual runs (`gh workflow run ci.yml`).
A `concurrency` group per ref cancels the running pipeline when a new commit is pushed.

## Jobs

```
ci.yml
 ├─ Check (parallel)
 │   ├─ PHP checks        cache:warmup, PHPStan, PHP CS Fixer (dry run), Twig CS Fixer (lint)
 │   └─ Frontend checks   Prettier, type check, assets build
 └─ Test (needs Check, parallel)
     ├─ PHPUnit           builds the assets first
     └─ Vitest
```

The test jobs only start when both check jobs succeeded.

| Check | Command |
| --- | --- |
| PHPStan (level `max`) | `vendor/bin/phpstan analyse --no-progress` |
| PHP CS Fixer | `vendor/bin/php-cs-fixer fix --dry-run --diff` |
| Twig CS Fixer | `vendor/bin/twig-cs-fixer lint` |
| Prettier (`assets/` only) | `npm run format:check` |
| Type check | `npx tsc --noEmit` |
| Assets build | `npm run build` (fails on TypeScript and Sass errors) |
| PHPUnit | `php bin/phpunit` |
| Vitest | `npm test` |

## Notes

- PHPStan reads `var/cache/dev/App_KernelDevDebugContainer.xml`, so the PHP checks job runs
  `php bin/console cache:warmup` first.
- The home page renders the Encore entrypoints. PHPUnit fails with `Could not find the entrypoints file` when
  `public/build/entrypoints.json` is missing, so the PHPUnit job runs `npm run build` before the tests.
- PHP version: `php-version: '8.5'` in the composite action, equal to the minimum in `composer.json` (`>=8.5`).
  PHP CS Fixer warns when it runs on a newer PHP than that minimum.
- Node version: from `.nvmrc`.
- PHPStan and the Symfony cache warmup run with `memory_limit=-1`.
- No database service: the current tests do not use the database. Add a Postgres 16 service container (as in
  `compose.yaml`) once tests or migrations need one.

## Local equivalents

The pre-commit hook (`.githooks/pre-commit`) runs the fixers in **write** mode on staged files: Prettier for
`assets/`, PHP CS Fixer for `*.php`, Twig CS Fixer for `templates/*.twig`. The pipeline checks the whole repository in
**check** mode.

Fix violations locally with `npm run format`, `vendor/bin/php-cs-fixer fix` and `vendor/bin/twig-cs-fixer fix`.
See [webpack.md](webpack.md) for the frontend setup.
