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
- Glow: a very faint, soft glowing-gold glow falls from the upper right corner onto the page background (two radial
  gradients on `body::before` and `body::after` in `_base.scss`, tokens `--color-glow-core` and `--color-glow-wide`,
  fixed to the viewport, behind `.app` and `.login`). The layers breathe slowly and out of step (opacity and a slight
  scale, 11 s and 7 s, `--duration-glow-*`) so the light seems alive. No rays or hard edges, nothing faster, only
  opacity and transform (no gradient animation), off with reduced motion, low alpha (worst case, both layers at full strength in the very corner: body text 8.3:1, muted text 5.4:1).
  The phase comes from the clock: an inline script in `base.html.twig` sets `--glow-delay-wide` and `--glow-delay-core`
  (negative delays, one cycle is twice the duration), so each page continues the light where the last one left it and
  never restarts dark. If you change `--duration-glow-*`, change the 22 and 14 in that script to twice the new values.
  New full-page layouts need `position: relative; z-index: 1` to sit above it.
- Breaking board (signature): the current navigation item sits on a wooden board like the ones broken in karate, drawn in
  pure CSS from the `--color-wood-*` tokens: straight grain, one knot, bevelled edges, a gold nail head. Playfulness is
  welcome here and in small touches (buttons press down 1px), but it stays out of tables, forms and data. No images.
- Spacing: 4px grid, `--space-1` to `--space-8`.
- Tables carry no colors and no icons. Three exceptions: the sort arrow, the accent color on link hover and the version
  status dot (`.status-dot--final` in `--color-success`, `.status-dot--draft` in `--color-highlight`, with `aria-label`).
- Table anatomy (`_tables.scss`): cell padding 10px / 14px. `button` and `a.button` in a cell use the compact height (2rem). The sorted column header is semibold with a small CSS triangle
  in the text color (up = ascending, down = descending), driven by `aria-sort` on the `th`. Links in cells are semibold with
  a 2px underline (3px and `--color-accent` on hover).

## Overviews

All five overviews (groups, projects, skills, testcases, runs) share `templates/_list/controls.html.twig` and
`_list/macros.html.twig`; change them there, not per page.

- Toolbar: a wooden rail (`.toolbar`, the `wood-board` mixin in `variables/_mixins.scss`, the same board as the current
  navigation item, without the knot). Filters on the left, the "New ..." action on the right. A page passes `newPath` and
  `newLabel` to the controls include; no separate "New ..." link above the table.
- Buttons: `.button` gives a link the look of a `<button>` (New ..., Previous, Next); `.button-primary` is the one gold
  main action.
- Pagination: centered below the table; Previous and Next are `.button` links, rendered as disabled spans
  (`aria-disabled`) when there is no such page, so the line does not jump.
- Width: the page content uses the full width next to the sidebar with the padding as margin (`.app-main` has no
  max-width). Tables span it; forms and paragraphs keep their own limits (`--measure`, input `max-width`).

## Stat tiles

`dl.stats` with one `div.stat` per figure (`dt` label, `dd` number), styled in `assets/css/components/_stats.scss`. Used
on the account page. The tile is a flat surface (`--color-surface`, hairline border, `--radius-md`, no shadow) with the
number large in the system sans (`--font-size-3xl`): numbers are data, so never the display face. The label sits below
the number in `--color-text-muted`. The grid wraps with `auto-fit` and is one column under `$breakpoint-sm`. A number may
be a link. No icons, no colors per tile and no animation. `dl.facts` in the same file is the matching label-over-value row
for profile data.

A tile can be wider with `.stat--wide` (two columns from `$breakpoint-md`) and may hold its main action; the project page
uses it for Runs, followed by Skills and Testcases; the run page uses it for Versions with the Start button. A tile may also
hold a text value (version label, project name); long values wrap. The run counts on skill and testcase pages count only runs
of projects the viewer can access.

## Provider cards

The API keys section of the LLM configuration page shows one card per provider (`.provider-grid`, `.provider-card` in `_provider-cards.scss`): flat surface,
hairline border, `--radius-md`. The heading carries a status dot (`.status-dot--final` when any credential of the provider is
stored, `.status-dot--off`, an empty ring, when none is). Each credential is a `.credential` block with label, environment
variable, status and the buttons *Set key* / *Replace key* (opens a form dialog, same pattern as the user dialog) and
*Delete*. Never show a stored key again, only `••••` and the last four characters.
The Hugging Face card lists its models in `.model-list`: name left (wraps), a red `−` icon button (`.button-icon`) right, aligned to the top line of the name.

## Result matrix

The version page shows the results of a run version as a table of testcases (rows) by LLM configurations (columns):
`templates/runs/_result_matrix.html.twig`, styles in `assets/css/components/_result-matrix.scss`. The same partial is
rendered by the page and by the progress endpoint, so the live update swaps in exactly what the page shows.

- The table sits in `.result-matrix-scroll` (`overflow-x: auto`), so many columns scroll inside the page. It stays a
  neutral table (see Tables above): no colors and no icons, status and score are text. A column note (*skipped – no API
  key*, *effort not supported*) is a `<small>` under the header.
- A cell with output or criteria is a native `<details>`: the `<summary>` is the score or status (semibold), the body
  holds the output in a scrollable `<pre>` (max height 20rem, wrapped) and the criteria list with *met* / *not met*, the
  criterion and the reason. No JavaScript for opening.
- `assets/js/components/runProgress.ts` polls the progress URL every 3 s while the version is running and replaces the
  matrix markup. It keeps open cells (`details[data-cell]`), the output scroll position, the focused summary and the
  horizontal scroll; after three failed requests it stops and shows the `[data-run-progress-notice]` text asking to
  reload. Keep the `data-cell` attributes when you change the partial.
- Live region: `[data-run-progress-status]` (`role="status"`) announces once that the run finished. It is
  `.visually-hidden` and is not replaced with the matrix, so the announcement is not lost. The notice stays a normal
  `.help-text` paragraph, hidden with the `hidden` attribute until needed.

## Visually hidden text

`.visually-hidden` (`_base.scss`) hides text visually and keeps it for assistive technology (1px clip, no `display:
none`). Use it for live regions and labels that only a screen reader needs, never for content sighted users miss.

## Back link and split layout

Detail and form pages start with a back link: `templates/_back_link.html.twig` (`href`, `label`), styled in
`assets/css/components/_back-link.scss`: muted, small, a left arrow from CSS, no underline, accent on hover. Never a bare
`<p><a>`. `.split` (in `_base.scss`) puts two sections side by side and stacks them when a column would get narrower than
22rem; use it for equal-weight sections such as groups and users.

## Confirmation dialog

Every button that deletes something (users, API keys, groups, projects, skills, testcases, runs, drafts) opens a modal first.
It is a native `<dialog>` opened with Invoker Commands, rendered by the `confirm_post` macro in
`templates/_forms.html.twig`; the POST form with the CSRF token sits inside the dialog, so nothing is sent before the
user confirms. Styles: `assets/css/components/_dialog.scss`, backdrop token `--color-scrim`.

- Keep `type="button"` on the Cancel and opener buttons, `autofocus` on Cancel (the safe action), no `role` or `aria-modal`.
- `closedby="any"` adds click-outside dismissal where supported; Esc always closes.
- Never set `display` on `dialog` without `[open]`, and keep `overscroll-behavior: contain` on `dialog:modal` and
  `dialog::backdrop`, otherwise the page scrolls behind the modal.
- `confirm_post` takes `triggerLabel` / `triggerClass` to restyle only the opener (for example an icon button); the dialog keeps `label` as its accessible name and confirm text.
- Browsers without Invoker Commands are served by `assets/js/services/commandButtons.ts` (no dependency).
- `Remove` buttons (assignments) and `Restore` do not ask.
- Form dialogs (the password change on the account page) use the same `<dialog>`. A Symfony form (it has a `name`) inside
  keeps its normal field layout, its Cancel and submit buttons sit in `.dialog-actions`. Mark the dialog with
  `prepare_component('dialog', { id: '...', showOnLoad: form.vars.submitted })`
  (`assets/js/components/dialog.ts`) to reopen it after an invalid submit.

The toolbar's *New ...* button normally links to a page. With `newDialog` (the id of a dialog) in `_list/controls.html.twig`
it is a `button[command="show-modal"]` that opens a form dialog instead; the users page uses it for *New user*.

## Password fields

Every `input[type=password]` gets an eye button (`assets/js/services/passwordToggle.ts`, styles `.password-field` and
`.password-toggle` in `_forms.scss`). The script wraps the field in `span.password-field` and adds a
`button.password-toggle` inside the field's right edge (control-height square, muted icon, accent on hover, focus ring
inside the button). The icon is an inline SVG built in the script (`currentColor`): an eye while the text is hidden, a
struck-through eye while it is shown; `aria-pressed` and `aria-label` ("Show password" / "Hide password") follow the state.

- A shown field is hidden again when its dialog closes and when the page returns from the back/forward cache.
- The field gets `data-password-field`: while shown it has `type="text"`, and the unsaved-changes guard skips it by that
  attribute. Keep it when you change the script.
- Without script the field stays a plain password field. New password inputs need no markup, the script finds them.

## Unsaved changes

Leaving a page with edited form fields asks first (`assets/js/components/unsavedChanges.ts`, the `#unsaved-dialog` in
`templates/layout.html.twig`, styles shared with the confirmation dialog). It guards every named POST form
(`form[name][method="post"]`, so all Symfony forms, not the GET search of the lists or the login). A field counts as changed
when it differs from its HTML default (`defaultValue`, `defaultChecked`, `defaultSelected`); hidden, password and file fields are
ignored (password managers fill them without an edit).

- A click on an in-app link opens the dialog with *Stay* (focused) and *Discard changes*. The handler runs in the capture
  phase, so the logout brick only starts after the user chose to discard. Links with a modifier key, `target`, `download`,
  a hash on the same page or another origin are not guarded.
- Reload, closing the tab, back, external links and the submit of another form get the browser's own prompt (`beforeunload`;
  its text cannot be changed).
- Submitting a form never warns about its own fields, but about changes in another form on the same page.
- Pages without `layout.html.twig` (login) have no dialog and no named forms, so nothing changes there.

## Logout brick

The Logout link in the sidebar turns into a brick on hover and breaks when clicked (`a.logout`,
`assets/css/components/_brick.scss`, `assets/js/components/smashLogout.ts`, tokens `--color-brick*` and `--duration-smash`). The
two halves are the link's `::before` and `::after`, cut along one crack. A click adds `.is-smashing`, the halves fall,
and the logout URL is followed when the animation ends (with a 1.2 s fallback). Without script, with reduced motion or
with a modified click (Ctrl, Cmd, middle button) the link just works. Brick red is a one-off for this link, not a brand
color. Keep the `<span>` label inside the link; its own animation is ignored by the script. While the brick
breaks, the sidebar (and the mobile navigation) switch to `overflow: clip`, so the falling halves never make scrollbars
flash up on systems with classic scrollbars (Windows).

## Login animation (tatami)

On login the credentials are sent in the background first. Only when the server accepts them, three tatami mats roll
down over the page (woven straw, dark cloth borders, a cylinder at the moving edge) and the first page opens; once it has
loaded, the mats roll away downward. Wrong credentials start no animation: the server's error message appears in the
form and the password field is cleared. Files: `assets/css/components/_tatami.scss`,
`assets/js/components/tatamiLogin.ts`, `assets/js/components/tatamiCover.ts`, the `.tatami` markup and the inline script in `templates/base.html.twig`, tokens
`--color-tatami*`, `--duration-tatami`, `--tatami-stagger`.

- States on `<html>`: `tatami-in` (rolling down, set on submit), `tatami-cover` (the next page starts covered; an
  inline script sets it before the first paint from `sessionStorage['dojo-tatami']`, valid for 15 s), `tatami-away`
  (rolling away). The page-transition cross-fade is skipped while covered, so the cover does not flicker.
- The login form needs the class `login-form`; the overlay needs `.tatami > .tatami-mat > (.tatami-sheet, .tatami-roll)`
  three times.
- Success or failure is read from the final URL of the background request (the login page again means failure). If the
  request fails, or with an invalid form or reduced motion, the normal form submit runs; nothing changes without script.
- Keep it to the login. Do not reuse the mats for other page changes.

## Dropdowns

`<select>` elements are styled and animated through the customizable select (`appearance: base-select`,
`assets/css/components/_select.scss`), so Symfony forms and templates keep plain `<select>` markup. The list opens in
the top layer, fades and drops in, fades out on close and rotates the arrow; the selected option sits on the breaking
board. Do not build JavaScript dropdowns or put `overflow: hidden` on `::picker(select)`. Firefox has no support yet and
shows its native select; all other current browsers show the styled one.

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
   content changes. The old page image (`::view-transition-old(root)`) is hidden and the new page fades in: Chromium
   sometimes captures the old image as opaque white, which showed as a white flash on navigation (also after saving a
   form). Do not bring the old image back without measuring that flash again.
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
