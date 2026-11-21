# Design

Visual rules of the Dojo interface. Product facts and the binding brand palette are in [PRODUCT.md](../PRODUCT.md).
Keep this page up to date when tokens, the app shell or the transition setup change.

## Tokens

All values are CSS custom properties on `:root`, defined in `assets/css/variables/` (`_colors`, `_typography`,
`_spacing`, `_layout`, bundled by `_index.scss`). Component styles in `assets/css/components/` use these tokens only:
no hex values, pixel sizes or durations outside `variables/`. Breakpoints are the Sass variables in `_layout.scss`
(media queries cannot read custom properties).

- Colors: dark navy surfaces, `#639cc5` accent for emphasis, `#eb2f33` highlight for markers and fills only (3.9:1 on the
  background, never as small text; use `--color-danger` for error text).
- Type: fixed rem scale, nothing below 13px, system fonts.
- Spacing: 4px grid, `--space-1` to `--space-8`.
- Tables carry no colors and no icons.

## Page transitions

**Every navigation between pages uses cross-document view transitions.** New pages get them for free, as long as they
follow these rules:

1. Extend `layout.html.twig` (or `base.html.twig` for pages without the sidebar). The opt-in (`@view-transition`,
   switched off under `prefers-reduced-motion: reduce`) and the `<link rel="expect" href="#content" blocking="render">`
   live in `templates/base.html.twig`.
2. The page content is `main#content`. The expect link holds back painting until that element is parsed, so the
   transition never animates to a blank page.
3. Keep the opt-in inline in `base.html.twig`. In Chromium 158 it was ignored inside the external stylesheet (console:
   "ViewTransition opt-in disabled"); the same rule inline works.
4. The animation lives in `assets/css/components/_transitions.scss`: the page content cross-fades with
   `--duration-base` and `--ease-out`. `.app-sidebar` has `view-transition-name: app-sidebar`, so it stays put while the
   content changes.
5. A `view-transition-name` must be unique on a page; duplicates cancel the whole transition. Add names for new
   elements that should morph or stay fixed (for example a sticky toolbar), in CSS, not in JavaScript, unless the name
   depends on the previous page (then use the `pagereveal` event and remove the name afterwards).
6. Do not add render-blocking scripts or `rel="expect"` links for content below the fold. The browser skips the
   transition after about 4 seconds of blocking.
7. No SPA-style navigation, no `document.startViewTransition` for page changes, no custom page-load animations.
   Firefox has no cross-document support and navigates normally; that is intended.

Check a change with the `pagereveal` event: `event.viewTransition` is set when a transition runs and `null` when it was
skipped (Playwright against `http://localhost:8000` works).

## Pages and shell

Signed-in pages use the sidebar shell (`.app`, `.app-sidebar`, `.app-main`). The navigation marks the current area with
`aria-current="page"`. The login page is a centered panel without the shell.
