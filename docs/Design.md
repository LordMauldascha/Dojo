# Design

Visual rules of the Dojo interface. Product facts and the binding brand palette are in [PRODUCT.md](../PRODUCT.md).
Keep this page up to date when tokens, the app shell or the transition setup change.

## Tokens

All values are CSS custom properties on `:root`, defined in `assets/css/variables/` (`_colors`, `_typography`,
`_spacing`, `_layout`, bundled by `_index.scss`). Component styles in `assets/css/components/` use these tokens only:
no hex values, pixel sizes or durations outside `variables/`. Breakpoints are the Sass variables in `_layout.scss`
(media queries cannot read custom properties).

- Theme: a traditional dojo, a little playful. Dark wood, paper-gold text, straight joinery. The theme enters through
  palette, heading type, square edges, the seal and the breaking board. Controls and layout stay standard.
- Colors: background `#2c2015` with surfaces derived from it, brand brown `#603e1e` for the selected navigation item,
  pale gold `#f1d9a8` (`--color-accent`) for links and the focus ring, glowing gold `#ffc34d` (`--color-highlight`) for
  the seal, markers and the one filled primary action. Red is not a brand color: `--color-danger` marks errors and
  destructive actions only.
- Type: fixed rem scale, nothing below 13px. Headings (`h1` to `h3`) and the wordmark use Shippori Mincho 600
  (`--font-display`, self-hosted in `assets/fonts/`, `_fonts.scss`); labels, buttons, inputs and tables use the system
  sans. Never use the display face for UI labels or data.
- Shape: radii 0 to 2px, no shadows, hairline borders.
- Seal: the square gold stamp with 道 (`.seal`, `--font-seal`) is the brand mark in the sidebar and on the login page,
  pressed slightly crooked (-4°) and straight on hover. Use the seal for the brand only.
- Breaking board (signature): the current navigation item sits on a wooden board like the ones broken in karate, drawn in
  pure CSS from the `--color-wood-*` tokens: straight grain, one knot, bevelled edges, a gold nail head. Playfulness is
  welcome here and in small touches (buttons press down 1px), but it stays out of tables, forms and data. No images.
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
   `--duration-page` (450 ms, deliberately slower than the 150 to 200 ms of hover and focus changes) and `--ease-out`. `.app-sidebar` has `view-transition-name: app-sidebar`, so it stays put while the
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
