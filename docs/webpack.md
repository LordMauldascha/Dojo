# Webpack Encore (frontend assets)

The frontend (TypeScript, SCSS, `@sulu/web` components) is built with
[Webpack Encore](https://symfony.com/doc/current/frontend.html).

Node is **not** part of the Docker image (`Dockerfile`), so all npm commands run on the host.
The required Node version is in `.nvmrc` (`nvm use`).

## Overview

| File / folder                         | Purpose                                                                |
| ------------------------------------- | ---------------------------------------------------------------------- |
| `webpack.config.js`                   | Encore configuration (ESM, see "Setup specifics")                      |
| `tsconfig.json`                       | TypeScript configuration (`include`: `assets/**/*`)                    |
| `vitest.config.ts`                    | Vitest configuration (jsdom, tests in `assets/js/tests`)               |
| `.prettierrc`                         | Prettier configuration (applies to `assets/` only, see below)          |
| `assets/js/app.ts`                    | Script entry `scripts`: loads `@sulu/web`, guards against double submit |
| `assets/css/app.scss`                 | Style entry `styles`                                                   |
| `assets/js/preventDoubleSubmit.ts`    | Blocks a second submit of the same form (double click)                 |
| `assets/js/tests/`                    | Vitest tests (`*.test.ts`)                                             |
| `assets/env.d.ts`                     | Type declarations for modules without types (`@sulu/web`)              |
| `config/packages/webpack_encore.yaml` | Symfony bundle, output path `public/build`, `defer` on all script tags |
| `public/build/`                       | Build output, **not in Git**                                           |

The entries are included in `templates/base.html.twig`:

```twig
{{ encore_entry_link_tags('styles') }}
{{ encore_entry_script_tags('scripts') }}
```

At the end of `<body>` an inline script starts the components registered for the current page:

```twig
web.startComponents({{ get_components() }});
web.callServices({{ get_services() }});
```

## Commands

```bash
npm install            # install dependencies (after cloning / when package.json changes)
npm run dev            # one-off development build (source maps, no hashes)
npm run watch          # development build, rebuilds on file changes
npm run build          # production build (minified, hashed file names)
npm run dev-server     # Encore dev server (not set up for the Docker setup, use watch)
npm test               # Vitest, one run
npm run test:watch     # Vitest in watch mode
npm run format:check   # Prettier check for assets/
npm run format         # Prettier write for assets/
npx tsc --noEmit       # type check without building
```

Development: start the stack (`docker compose up -d`), run `npm run watch` in a separate terminal and reload
http://localhost:8000.

After `npm run build` the output contains hashed files. Run `npm run dev` or `npm run watch` again before developing
further, so the output matches the development state.

## Components

A component is a class with a public `initialize(el, options)` method that is bound to a DOM element.
`@sulu/web` is the runtime, `sulu/web-twig` provides the Twig functions.

### 1. Create the class

`assets/js/components/counter.ts`:

```ts
export default class Counter {
    initialize(el: HTMLElement, options: { start?: number }): void {
        let count = options.start ?? 0;

        el.addEventListener('click', () => {
            count++;
            el.textContent = String(count);
        });
    }
}
```

- `initialize` must be public, `@sulu/web` calls it from the outside.
- One instance is created per element, so state belongs to the instance.
- `options` come from the Twig call.

### 2. Register it in `app.ts`

```ts
import Counter from './components/counter';

web.registerComponent('counter', Counter);
```

### 3. Use it in Twig

```twig
<button id="{{ prepare_component('counter', { start: 5 }) }}">Click me</button>
```

`prepare_component(name, options)` returns a unique element id. Always use `{{ ... }}`, otherwise the call is
printed as text and the component never starts. `@sulu/web` sets `window.web` itself, so the inline script in
`base.html.twig` can call `web.startComponents(...)`.

See the [sulu/web-twig documentation](https://github.com/sulu/web-twig/blob/master/docs/component.md) for the other
Twig functions.

## Tests

Vitest tests live in `assets/js/tests/` and import from `../components/...`. The environment is `jsdom`.
`vitest.config.ts` only picks up `assets/js/tests/**/*.{test,spec}.ts`, tests placed elsewhere are not run.

## Formatting

Prettier formats `assets/` (`npm run format`). The pre-commit hook (`.githooks/pre-commit`) runs it on staged files
under `assets/`. See [AGENTS.md](../AGENTS.md) for how to enable the hook.

## Adding things

- More TypeScript files: create them under `assets/js/` and `import` them, Encore bundles them automatically.
- SCSS: create files under `assets/css/` and include them in `app.scss` with `@use`. Design tokens and rules:
  [Design.md](Design.md).
- New entry: add `.addEntry('name', './assets/js/name.ts')` in `webpack.config.js` and use
  `encore_entry_script_tags('name')` in the template.
- New npm packages: `npm install <package>` (`--save-dev` for build tools), then commit `package.json` and
  `package-lock.json`.

## Setup specifics

- **Encore 7 is ESM-only**: `package.json` has `"type": "module"`, the config uses `import Encore from ...` and ends
  with `export default await Encore.getWebpackConfig();`. `require()` does not work.
- **TypeScript is limited to `^5 || ^6`** by the Encore peer dependency. Do not upgrade to a newer major until Encore
  supports it.
- **Babel 8** with `@babel/preset-env` (Encore defaults, no extra plugins).
- `ts-loader` reports TypeScript errors as build errors. For modules without types add a declaration to
  `assets/env.d.ts` (`declare module 'package';`).

## Troubleshooting

| Symptom                                     | Cause / fix                                                                           |
| ------------------------------------------- | ------------------------------------------------------------------------------------- |
| `Could not find the entrypoints file`       | No build has run yet: `npm run dev`                                                   |
| `Unknown "encore_entry_*" function`         | `WebpackEncoreBundle` missing in `config/bundles.php` or cache not cleared            |
| Component does not start, element has no id | `prepare_component` was called without `{{ }}`                                        |
| Changes not visible in the browser          | `npm run watch` is not running or browser cache (hard reload)                         |
| Vitest finds no tests                       | Test file is not under `assets/js/tests/` or does not match `*.test.ts` / `*.spec.ts` |
