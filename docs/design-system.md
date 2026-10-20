# ComTSo design system — "paper magazine"

Tailwind CSS 4 **written in SCSS**, light/dark themes, natural paper textures laid on every surface through pseudo-elements,
editorial typography. Source of truth: `assets/styles/` (the application) and `design-system/` (the dev-only showcase, same
Sass tree). The look is a printed magazine on natural paper: wide margins, hairline rules instead of boxes, 2–4 px radii,
layered "sheet of paper" shadows, three self-hosted typefaces, five brand colours.

- [Quick start](#quick-start)
- [The SCSS + Tailwind 4 pipeline](#the-scss--tailwind-4-pipeline)
- [File layout](#file-layout)
- [Palette and colour tokens](#palette-and-colour-tokens)
- [Light / dark theme](#light--dark-theme)
- [Typography](#typography)
- [Paper textures](#paper-textures)
- [Components](#components)
- [Inventory of the original front-end](#inventory-of-the-original-front-end)
- [Adding or changing a component](#adding-or-changing-a-component)
- [Accessibility: contrast table](#accessibility-contrast-table)
- [Showcase](#showcase)
- [Decisions, limits, performance](#decisions-limits-performance)

## Quick start

```bash
docker compose exec node npm run build          # production bundle -> public/assets/main.css|main.js (+ fonts, textures)
docker compose up -d node                        # watch mode (npm run build -- --watch), rebuilds on SCSS/template/Vue changes
docker compose up -d design-system               # showcase: http://localhost:5174/  (override with DESIGN_SYSTEM_PORT)
docker compose exec node npm run contrast        # WCAG AA check of every text/background pair, both themes (exit 1 on failure)
python3 design-system/scripts/make-textures.py   # regenerate the texture tiles (host: python3 + numpy + Pillow)
```

Never run npm/node on the host (see AGENTS.md). The `design-system` service lives in the dev `compose.yaml` only: it is
not part of the production stack (`deploy/compose.yaml`) nor of the production image.

## The SCSS + Tailwind 4 pipeline

Tailwind 4 is "not designed for preprocessors" (its Vite plugin only transforms `.css` files). The setup that works, and
that is proven by the production build and by the showcase:

```
assets/styles/app.scss ──Sass (Vite css preprocessor)──▶ plain CSS ──PostCSS: @tailwindcss/postcss──▶ main.css
```

1. `assets/scripts/main.js` imports `../styles/app.scss`. Vite compiles it with `sass` (dart-sass, pure JS, no native binary so
   it also works on the Alpine production stage).
2. The compiled CSS then goes through PostCSS where **`@tailwindcss/postcss`** runs (`css.postcss.plugins` in `vite.config.js`;
   `@tailwindcss/vite` is no longer used). Tailwind resolves `@import`, `@source`, `@theme`, `@custom-variant`, `@apply`,
   `@utility`, generates the utilities used by templates/Vue files and optimises with lightningcss.
3. What makes Sass and Tailwind coexist (all in `assets/styles/base/_tailwind.scss`):
   - `@import "tailwindcss/index.css" source(none);` — the `.css` suffix makes it a *plain CSS import* for Sass, which leaves it
     untouched and hoists it; Tailwind inlines it. `source(none)` disables automatic content detection: only the `@source`
     lines of the entry (`app.scss`: `templates/`, `assets/vue/`, `assets/scripts/`) are scanned (PHP code and docs contain
     look-alike strings such as `[quote:uid="x"]`). **`@source` paths are relative to the entry stylesheet**, which is why
     they sit in `app.scss` (and in `design-system/showcase.scss` for the showcase), not in partials.
   - `@theme`, `@custom-variant`, `@apply`, `@source` are unknown at-rules for Sass: they pass through, and Sass features keep
     working inside them (`@each` loops generate the colour tokens, `#{…}` interpolation).
   - `--color-*: initial` (reset of the default Tailwind palette) is not a valid Sass declaration: it is spelled
     `--color-#{"*"}: initial`.
   - `color-mix()`, `min()`, `:where()` etc. pass through unchanged.
4. Everything the Sass tree emits is wrapped in `@layer base` / `@layer components`, so **utilities in templates still win**
   (`class="btn ml-auto lg:hidden"`). Only two things are deliberately unlayered, each commented where it lives: the chat
   page layout and the post-photo tile geometry (they must beat Tailwind sizing utilities carried by the markup).
5. Assets referenced from SCSS use Vite aliases declared in `vite.config.js` and `design-system/vite.config.js`:
   `@fontsource-variable/…` (fonts from npm) and `@textures/…` (`assets/images/textures/`). Vite bundles and fingerprints them
   (`public/assets/paper-multiply-<hash>.webp`, fonts likewise; `main.css` keeps its stable name through `assetFileNames`).
   Relative `url()` in Sass is resolved against the *entry*, aliases avoid that trap.
6. `npm run build -- --watch` rebuilds when a scanned template or Vue file changes (checked: a new utility class in a Twig file
   appears in `main.css` within seconds).

Production image: the `frontend_builder` stage copies `package*.json`, `vite.config.js`, `assets/`, `templates/` and runs
`npm ci && npm run build` (nothing else needed: textures and fonts are inside `assets/` and `node_modules`).

## File layout

```
assets/styles/
  app.scss                  entry: `@use "index"` + the @source list
  _index.scss               every partial, in cascade order (the showcase @uses the same file)
  abstracts/                no CSS output
    _palette.scss             the five brand colours + OKLCH scales (50…950) + paper neutrals + brick/sage
    _functions.scss           srgb(), hex(), relight(), mix-ok(), luminance() (WCAG), contrast(), over()
    _theming.scss             themed($theme) selectors, each-theme, state mixins (hover/pressed/focus/disabled), focus-ring, up()/down()
    _texture.scss             tone buckets, recipe tables, texture-host / texture-tone / texture-vars mixins
    _mixins.scss              sheet-shadow, stacked-sheets, eyebrow, fluid(), sr-only
  themes/
    _light.scss _dark.scss    role -> colour maps (Sass colours, emitted as --c-<role>)
    _index.scss               $themes registry, color($theme, $role)
    _probe.scss               compiled only by scripts/contrast.mjs
  base/
    _tailwind.scss            Tailwind import, @custom-variant dark, @theme (fonts, type scale, radii, shadows, tokens)
    _fonts.scss               @font-face (latin + latin-ext) for Fraunces, Newsreader, Inter
    _theme-vars.scss          --c-* per theme, theme-scoped shadows, default texture recipes
    _base.scss                html/body/links/selection/focus/scrollbars
    _typography.scss          headings, .display-*, .kicker, .standfirst, .byline, .rule*, .drop-cap, .prose, .eyebrow
    _textures.scss            page canvas (body::before), .texture-* utilities, print switch
    _a11y-print.scss          prefers-reduced-motion, @media print
  layout/_page.scss _masthead.scss
  components/               one partial per component (see the table below)
assets/images/textures/     tracked texture tiles (+ source/ = untouched originals, + stats.json used by the contrast check)
assets/scripts/theme-toggle.js   anonymous-visitor theme toggle
design-system/              dev-only showcase (index.html, main.js, tokens.js, sections/NN-*.html, styles/_ds-ui.scss, lib/contrast.js, scripts/)
```

`public/images/` is **git-ignored** (`/public/images/` in `.gitignore`), so nothing needed by the product lives there: the
texture sources and derivatives are tracked in `assets/images/textures/` and reach `public/assets/` through the Vite build.

## Palette and colour tokens

Exactly five brand colours; every other colour is derived from them in OKLCH (Sass `color.change(… $space: oklch)`,
gamut-mapped to sRGB) and documented as a scale.

| Name | Hex | Role |
|---|---|---|
| Plum (`plum`) | `#950952` | primary action, brand, active nav tint |
| Mist (`mist`) | `#95b2b8` | hairlines, secondary surfaces, avatars |
| Sand (`sand`) | `#f0a868` | highlight: stars, kickers, focus (dark theme), hero accents |
| Deep blue (`blue`) | `#00487c` | links, info, secondary action, focus (light theme) |
| Ink (`ink`) | `#2b2118` | text on light, page canvas of the dark theme |

- Scales `plum|mist|sand|blue|ink` 50…950 (the brand colour is the exact step 700/400/300/700/900) and `paper` 50…400 (warm
  cream neutrals, hue of the sand) are Tailwind colours: `bg-plum-700`, `text-ink-900`, `border-mist-300`, `bg-paper-100`…
  Also `brick` (danger) and `sage` (ok), hue-matched to the warm palette, not part of the five.
- **Semantic roles** (`themes/_light.scss`, `_dark.scss`) are what components use. Each role is a CSS variable
  `--c-<role>` defined per theme and a Tailwind colour (`bg-surface`, `text-muted`, `border-line`, `bg-accent`, `text-on-accent`…).
  The original tokens (`page surface surface-2 line fg muted accent accent-strong accent-soft on-accent danger ok star`) keep
  their names and meaning, new ones were added:

  | Group | Roles |
  |---|---|
  | Surfaces | `page` `surface` `surface-2` `surface-3` `overlay` |
  | Lines | `line` `line-soft` (decorative hairlines) `line-strong` (control borders, ≥3:1) `rule` (ink rules) |
  | Text | `fg` `fg-soft` `muted` |
  | Plum | `accent` `accent-hover` `accent-active` `accent-strong` (plum as text) `accent-soft` (tint) `on-accent` |
  | Blue | `secondary` `-hover` `-active` `-soft` `on-secondary` `link` `link-hover` |
  | Sand | `highlight` `-hover` `-active` `-soft` `on-highlight` `kicker` `star` |
  | Mist | `mist` `mist-soft` `mist-strong` |
  | Neutral button | `btn` `btn-hover` `btn-active` `on-btn` |
  | Status | `danger` (text) `danger-fill` `danger-fill-hover` `danger-soft` `on-danger`, same for `ok` |
  | Misc | `focus` `selection` `shadow` `scrim` |

- Why `danger`/`danger-fill` are separate: in the dark theme a colour that is readable as *text* on ink (light salmon) cannot
  also carry white text as a *fill* (the two requirements are mathematically incompatible), hence a text role and a fill role.
- **Changing a colour** = editing one value in `themes/_light.scss` or `_dark.scss` (or the brand constants in
  `abstracts/_palette.scss`), then `npm run contrast`. Texture recipes follow automatically (they are computed from the roles).
- **Adding a role**: add the key to both theme maps (the build fails if one is missing for the tokens), use it as
  `var(--c-<role>)` or `bg-<role>`; add its text/background pairs to `design-system/lib/contrast.js`.

## Light / dark theme

- Mechanism unchanged: `<html data-theme="light|dark">` (the server writes the member's choice from `fos_user.config`,
  the Vue `ThemeToggle` flips it and POSTs to `user_theme`). Tailwind's `dark:` variant is
  `@custom-variant dark (&:where([data-theme="dark"], [data-theme="dark"] *))`.
- Every colour is a theme-scoped CSS custom property: switching theme is one attribute change, **no reload, no rebuild**.
- A `data-theme` attribute on **any element** re-themes its subtree ("theme islands": the showcase shows light and dark side
  by side). Texture recipes are themed with the nearest-theme-wins rule (see below).
- **Anonymous visitors** (login page): `assets/scripts/theme-toggle.js` + `.theme-toggle` (components/_theme-toggle.scss).
  What the template must include:
  1. In `<head>` of `base.html.twig` (replacing the current `prefers-color-scheme` one-liner, before the stylesheet):
     ```html
     <script>(function(){var t=null;try{t=localStorage.getItem('comtso-theme')}catch(e){}
       document.documentElement.dataset.theme=t||(matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light')})();</script>
     ```
  2. The button (login page only; the icon shown is the one of the *current* theme through `data-when`, moon in light, sun in dark):
     ```html
     <button type="button" class="btn-icon theme-toggle theme-toggle-floating" data-theme-toggle aria-label="Changer de thème">
       <svg data-when="light">…moon…</svg><svg data-when="dark">…sun…</svg>
     </button>
     ```
  3. In `assets/scripts/main.js`: `import { initThemeToggle } from './theme-toggle.js'; initThemeToggle();`.
  The choice lives in `localStorage['comtso-theme']` and is shared with the showcase.

## Typography

Self-hosted through npm (`@fontsource-variable/*`, bundled by Vite, **no CDN**), latin + latin-ext only, `unicode-range` so the
browser downloads a file only when a glyph needs it (typical page: Fraunces roman 67 kB + Newsreader roman 132 kB + Inter 48 kB).

| Voice | Family | Use |
|---|---|---|
| Display | Fraunces Variable (opsz, wght 100–900, italic) — `font-display`, `--font-display` | headings, masthead, drop caps, hero/thread titles |
| Text | Newsreader Variable (opsz, wght 200–800, italic) — `font-serif` | message bodies, standfirsts, `.prose` |
| UI | Inter Variable (wght 100–900) — `font-sans` | navigation, forms, metadata, kickers, buttons |

- Fluid modular scale as Tailwind theme values: `text-2xs … text-6xl` (the large steps use `clamp()`), with line-heights,
  tracking tokens (`tracking-kicker`…), `leading-prose`.
- Headings: `text-wrap: balance`, no hyphenation; paragraphs: `text-wrap: pretty`; reading text: `hyphens: auto` (`lang="fr"`),
  measure ≤ 68 ch (`--container-prose`); `font-variant-numeric: tabular-nums` on `time`/`.tabular`/counters.
- Helpers: `.display-1/2/3`, `.kicker` (small-caps eyebrow with a sand rule, `.is-plain` without), `.standfirst`, `.byline`,
  `.rule` (single), `.rule-double` (press double rule), `.rule-hair`, `.drop-cap`, `.prose`, `.eyebrow`.

## Paper textures

Two images, one rule: **the texture is a pseudo-element laid on top of the element's own colour**, never baked into
`background`, so its strength is configurable and its recipe can depend on the colour underneath.

- `watercolor_paper_5_by_craftmans_d2772t4-fullview.jpg` (near-white warm paper, coloured fibres) → family **`paper`**: page
  canvas, cards, panels, header strip, hero, reply sheet, popovers, editor surface.
- `hard-paper-texture.jpg` (mid-tone cardboard) → family **`kraft`**: buttons, badges/chips, pager items, active nav/tab pills,
  toolbar buttons, lightbox buttons.
- Derivatives (`design-system/scripts/make-textures.py`, run on the host): seamless 640² WebP tiles (variance-preserving blend
  with the half-shifted copy) in three tonal flavours per family — `*-multiply.webp` (white with dark grain, mean ≈ 0.96),
  `*-neutral.webp` (mid-grey grain, mean 0.5), `*-screen.webp` (black with light grain, mean ≈ 0.1). The kraft tile is
  desaturated (10 % of the fibre colour) so it never tints every button brown. Originals are kept untouched in
  `assets/images/textures/source/`; `stats.json` holds the grain percentiles used by the contrast check.

### Why the recipe depends on the colour

An almost-white tile `multiply`'d over a light colour is subtle grain; over a dark colour (dark theme canvas, plum, deep blue,
ink) it disappears — there is nothing to darken. There the tile must add light instead (`screen` of the black-and-light variant).
Mid-tones get a neutral tile with `soft-light`/`overlay`. At build time (Sass), for every host colour the mixin computes the WCAG
relative luminance of the colour and picks a recipe:

| Tone | Luminance | `paper` family | `kraft` family | Typical hosts |
|---|---|---|---|---|
| `paper` | ≥ 0.60 | multiply · `paper-multiply` (gain .55) | multiply · `kraft-multiply` (gain .9) | page, surface, btn (light theme), paper-* |
| `light` | 0.30 – 0.60 | multiply · `paper-multiply` | multiply · `kraft-multiply` + `contrast(1.1)` | sand, mist, plum-400, hover states of sand |
| `mid` | 0.10 – 0.30 | soft-light · `paper-neutral` | overlay · `kraft-neutral` (gain .8) | plum-600, blue-600, secondary in dark theme |
| `dark` | 0.055 – 0.10 | screen · `paper-screen` (gain .8) | screen · `kraft-screen` (gain .7) | plum, deep blue, brick |
| `deep` | < 0.055 | screen · `paper-screen` (gain .5) | screen · `kraft-screen` (gain .75) | ink, dark-theme canvas, cards and neutral buttons |

Tables live in `abstracts/_texture.scss` (`$tone-floors`, `$recipes`): tuning a recipe = editing that table. Because theme
colours are CSS variables resolved at runtime, **the recipe is emitted per theme** (and per state: hover, pressed, disabled…), so
it stays correct when the fill changes.

### Mixin API (Sass)

```scss
@use "../abstracts" as *;

.my-chip {
  @include texture-host;                          // position:relative; isolation:isolate; ::before = the tile (z-index:-1, inset:0,
                                                  //   border-radius:inherit, pointer-events:none, mix-blend-mode/filter/opacity from variables)
  @include texture-tone("kraft", "btn");          // per theme: recipe computed from the colour the role `btn` has in that theme
  background-color: var(--c-btn);                 // the host keeps its colour

  @include hover {                                // forced `.is-hover` works too (showcase)
    background-color: var(--c-btn-hover);
    @include texture-tone("kraft", "btn-hover");  // the fill changed: re-emit the recipe
  }
}
// translucent role over another one: @include texture-tone("paper", "accent-soft", "surface");
// pseudo-element already taken by the component: @include texture-host("after");
// explicit colours instead of roles:              @include texture-tone-colors("paper", ("light": #fff, "dark": #000));
// fixed recipe, no colour computation:            @include texture-vars("kraft", "dark");
```

`texture-tone` writes the selectors with the `themed()` mixin: `[data-theme="x"] .host`, plus the island selector
`[data-theme="other"] [data-theme="x"] .host` (a theme nested in the other one: one class more of specificity so the NEAREST theme
wins) and `:where(:root:not([data-theme])) .host` as light fallback.

### Custom properties

| Property | Owner | Meaning |
|---|---|---|
| `--texture-image` `--texture-blend` `--texture-filter` `--texture-size` `--texture-gain` | the recipe | image variant, `mix-blend-mode`, CSS filter, tile size, tone-calibrated strength. Don't set by hand. |
| `--texture-strength` | component | per-component multiplier (default 1): "this sheet carries half the grain" (`.card` 0.5, canvas 0.4 light / 0.55 dark) |
| `--texture-ratio` | **user dial** | default 1, `0` = flat colour, ~2 = strong. Inherits on purpose: one slider for a whole region (showcase) |
| `--texture-print` | global switch | 0 under `@media print`, `forced-colors: active`, `prefers-contrast: more` |

Pseudo-element opacity = `min(1, ratio × strength × gain × print)`. Custom properties inherit and the `::before` reads them from its host, so they
cannot be registered with `inherits: false` (the pseudo-element would then see the initial value: tried, it silently removes every
texture). Instead every recipe sets all of its properties, and `texture-host` resets `--texture-strength: 1` through a zero-specificity
`:where(.host)` rule, so a host never leaks its strength into hosts nested inside it.

### Plain-CSS utility API

```html
<div class="texture-paper">…</div>                         <!-- watercolour tile, recipe = tone of the current theme's surface -->
<div class="texture-kraft is-tone-dark">…</div>            <!-- hard paper, recipe forced for a DARK host colour -->
<div class="texture-card">…</div>                          <!-- surface + paper tile + sheet shadow + 3px radius -->
<div class="texture-paper" style="--texture-ratio:.4">…</div>   <!-- dial -->
```
Modifiers: `.is-tone-paper|light|mid|dark|deep` (force the recipe for the host colour you set yourself), `.texture-subtle` (×0.5),
`.texture-strong` (×1.6), `.texture-off`. The host must be positioned (the utilities set `position: relative`; a Tailwind
`absolute`/`sticky` utility overrides it without breaking anything).

### Page canvas, scrolling, accessibility

- `body::before` carries the canvas tile (`--canvas-texture-strength`: 0.4 light, 0.55 dark; text sits on it).
- It is `position: absolute` (scrolls with the content) rather than `fixed`: a fixed blended layer needs a per-frame composite of
  two compositor layers while scrolling, an attached one is raster only, and the paper feels printed on the page rather than a
  glass overlay. Measured in headless Chromium (150 scroll frames over the showcase): both variants are vsync-bound (13.4 ms/frame),
  no measurable difference there; the choice is architectural. The first raster of the 640² tiles shows up as a single long frame.
- `@media print`: no textures, flat ink on white (`base/_a11y-print.scss`). `prefers-reduced-motion`: all transitions/animations
  collapse; the only motion in the system is colour/shadow fades and 2 px lifts. Forced colours / high contrast: textures off.
- Text on texture: `npm run contrast` also checks text roles against the **5th-percentile darkest** (multiply) or **95th-percentile
  brightest** (screen) grain pixel at the strongest opacity the canvas/cards use (last table below).

## Components

All class names of the original front-end are a **stable API** and keep working; the new ones follow the shared contract
(`docs/components-markup.md` shows the markup the templates render). Every interactive component supports real pseudo-classes
and forced classes (`is-hover`, `is-pressed`, `is-focus`, `is-disabled`) through the state mixins.

| Partial | Classes |
|---|---|
| `layout/_page` | `.aside-quote-add` (button under the aside quote) `.page` `.page-narrow` `.page-header` (`.is-double`, `.is-centered`) `.page-title` `.page-actions` `.layout` `.layout-main` `.layout-aside` `.reading` `.section` `.section-title` `.section-more` `.stack` |
| `layout/_masthead` | `.masthead` `.masthead-bar` `.masthead-title` `.masthead-search` `.masthead-tools` `.masthead-user` `.masthead-nav` (sticky paper strip) `.masthead-menu` `.masthead-toggle` `.masthead-link` (`.is-active`/`aria-current`) |
| `_button` | `.btn` `.btn-primary` `.btn-secondary` `.btn-highlight` `.btn-danger` `.btn-ghost` `.btn-sm` `.btn-lg` `.btn-block` `.btn-icon` `.btn-group` |
| `_card` | `.card` (carries its own padding, 1.25rem) `.card-roomy` (forms/settings) `.card-flush` (edge-to-edge: messages, photos, chat) `.card-flat` `.card-sunken` `.card-stack` `.card-hover` `.card-accent` `.card-title` |
| `_form` | `.input` (+`-sm`/`-lg`, `.is-invalid`, readonly) `.label` `.field` `.field-help` `.field-error` `.form-grid` `.form-actions` checkbox/radio (global) `.check` `.switch` `.input-group` `.input-addon` `.input-wrap` `.input-icon` |
| `_alert` | `.alert` `-danger` `-ok`/`-success` `-info` `-warning` `-neutral` `-sm` `.alert-icon` `.alert-body` `.alert-title` |
| `_badge` | `.badge` `-muted` `-accent` `-secondary` `-highlight` `-danger` `-ok` `-solid` `-lg` `-dot` `-count` |
| `_avatar` | `.avatar` `-xs/-sm/-lg/-xl` `-initial` (`--avatar-hue`) `-framed` `.avatar-wrap` `.avatar-status` `.avatar-stack` (`li` carries the overlap) `.avatar-stack-more` |
| `_tabs` | `.tabs` `.tab-pills` `.nav-pills` |
| `_dropdown` | `.dropdown` `.dropdown-menu` `.dropdown-item` `.dropdown-divider` `.dropdown-label` `.popover` `.tooltip` `[data-tooltip]` `.menu` `.menu-item` `.menu-title` |
| `_pager` | `.pager` (`a`, `span`, `.is-current`, `.is-disabled`) |
| `_table` | `.table` `-striped` `-hover` `-sm` `-sticky` `.table-responsive` `.dl` `.dl-stacked` |
| `_filter-bar` | admin lists: `.filter-bar` (`-fields`, `-actions`) `.filter-range` (`-inputs`) `.list-meta` `.table-sort` (a column head that sorts, current direction = `th[aria-sort]`) `.table-actions` `.table-empty`; the table is `.table`, the pagination `.pager` |
| `_settings-layout` | settings centre: `.settings-layout` (menu left + content, no aside) `.settings-nav` (wraps a `.nav-pills.is-vertical`; a row of pills that scrolls sideways under 48rem) `.settings-nav-admin` (admin button) `.settings-main` |
| `_admin` | `/admin` pages around the list: `.admin-tiles` (overview tiles, ≥14rem) `.admin-form` (form column) `.admin-subject` (name under the title) `.admin-details` (what a removal takes along) |
| `_skeleton` | `.skeleton*` `.empty*` `.spinner` `.progress` |
| `_post` | `.post` `.post-body` (all sanitized elements) `.post-meta` `.post-toggle` `.post-author`, states `.is-target` `.is-active` `.is-collapsed` |
| `_post-photos` | `.post-photos` (mosaic by count: 1, 2, 3, 4, 5+ with "+N") `.post-photo` `.post-photo-more` |
| `_photo` | `.photo-grid` `.photo-card` `.photo-caption` `.photo-figure` `.photo-manager*` `.photo-tile*` `.photo-dropzone` |
| `_attach` | `.attach` `.attach-zone` (`.is-dragover`) `.attach-input` `.attach-hint` `.attach-tray` `.attach-item` (`.is-uploading` `.is-error`) `.attach-item-name` `.attach-item-size` `.attach-item-remove` `.attach-count` `.attach-error` |
| `_reply` | `.reply` `.reply-avatar` `.reply-body` `.reply-actions` |
| `_lightbox` | `.lightbox` `.lightbox-btn` (+ the Tailwind classes `assets/scripts/ui.js` creates) |
| `_hero` | `.hero` `.hero-media` `.hero-image` `.hero-body` `.hero-title` (+ variants, see the showcase) |
| `_thread-preview` | `.thread-preview` (`.is-new`; `.has-cover` is a marker, the layout follows the presence of `.thread-preview-cover`) `.thread-preview-cover` `-body` `-title` `-excerpt` `-last` `-last-author` `-meta` `-stat` `-toggle` `-more` |
| `_chat` | every `.chat-*` class of the original chat (page layout is unlayered on purpose) |
| `_rich-editor` | every `.rich-editor*` class, ProseMirror content typeset like `.post-body` |
| `_people` | `.people-grid` `.person-card*` `.profile*` `.settings*` `.result-list` `.result*` `.search-hit` (search highlight, `mark`) |
| `_stats` | `.stats-chart*` `.stats-bar` `.stats-series-N` `.stats-legend*` `.stats-swatch` (SVG bar chart of `StatsChart.vue`; series colours = `link`, `kicker`) |
| `_auth` | `.auth` `.auth-card` `.auth-brand` `.auth-wordmark` `.auth-title` `.auth-lead` `.auth-form` `.auth-remember` `.auth-footer` |
| `_error` | `.error-page` `.error-code` `.error-title` `.error-text` `.error-actions` |
| `_theme-toggle` | `.theme-toggle` `.theme-toggle-floating` |

Cross-cutting rules: `[hidden]` always wins (`display: none !important` in base: `.thread-preview-toggle` and
`.thread-preview-more` rely on it); `.is-active` is reserved for the JS-driven states of posts/tabs/toolbar buttons (forced states
use `is-pressed`); no inline styles in components.

## Inventory of the original front-end

Audit of `assets/styles/app.css` (removed by the cutover), `templates/**`, `assets/vue/components/*.vue`, `assets/scripts/ui.js`:

| Pattern (where) | Now covered by |
|---|---|
| Buttons `btn btn-primary btn-danger btn-sm btn-icon` (everywhere) | `_button` |
| Card, card title (aside widgets, forum list, people, search, topic) | `_card` |
| `input`, `label`, `field-error`, Symfony form theme (`form/theme.html.twig`), date input, checkbox/radio expanded choices, help text | `_form` |
| Flash messages (`base.html.twig`), login error, form errors | `_alert`, `.field-error` |
| Header/nav: brand, links with active pill, unread badges (`data-chat-badge`), search with icon, theme toggle, user chip, mobile menu (`data-nav-toggle`, `data-nav-menu`) | `layout/_masthead`, `_badge`, `_theme-toggle`, `_form` |
| Aside: favourites list, forums list, random quote (`card border-l-4`) | `_card`, `.card-accent`, `.menu` |
| Forum tabs (`forum/_tabs`), search tabs | `_tabs` |
| Pagination (`_layout/pagination`, `admin/datagrid`) | `_pager` |
| Topic list / forum list rows, people cards, profile, definition lists (`dl grid-cols-[auto_1fr]`) | `_thread-preview`, `_people`, `_table` (`.dl`) |
| Message (`_post/message`), user HTML, collapse (`.is-collapsed`/`.post-toggle`), hover/tap meta, permalink highlight | `_post` |
| Star favourite toggle (`topic/_star`, `group-aria-pressed`) | `.btn-icon` + utilities (unchanged) |
| Photo thumbs (`_ui/photo`, `group/photo`), photos page, photo page, topic photo manager (`PhotoManager.vue`) | `_photo`, `_post-photos` |
| Lightbox (built by `ui.js`, `data-lb-*`) | `_lightbox` |
| Chat (`ChatRoom.vue`, `chat-badge.js`) | `_chat` |
| Rich editor (`RichEditor.vue`, `form/rich_text`) | `_rich-editor` |
| Statistics chart (`StatsChart.vue`, `stats/index`) | `_stats` |
| Login / register / error pages | `_auth`, `_error` |
| Avatars (`_ui/avatar`: image, initial on `hsl()` background, `?`) | `_avatar` |
| Search result highlights | `mark` (base) |
| Scrollbars, selection, focus ring | `base/_base` |
| New: hero, thread preview, post photos, attach widget, reply block | `_hero`, `_thread-preview`, `_post-photos`, `_attach`, `_reply` |
| Likes on photos (photo-page heart, lightbox heart) | `_like` |

Templates and Vue components use the semantic classes above (masthead, `.page` / `.layout`, `.page-header`, `.section`, `.alert`, `.badge`,
`.avatar`, `.post`, `.photo-*`, `.chat-*`, `.auth`, `.error-page`, `.people-grid`, `.profile`, `.settings`...); Tailwind utilities remain only as
layout glue. Page contract of `base.html.twig`: skip link, `.masthead` (+ sticky `.masthead-nav`, burger `data-nav-toggle` / `data-nav-menu`),
`<div class="page">` with flash `.alert`s and `{% block content_layout %}` (`.layout` > `main#main.layout-main` + `aside.layout-aside`), colophon
footer (`{% block footer %}`). Login/register override `masthead` (floating `.theme-toggle`, localStorage, head script for anonymous visitors only)
and `content_layout`. Avatar macro: `av.avatar(user, 'xs|sm|md|lg|xl')`.

## Adding or changing a component

1. Create `assets/styles/components/_name.scss`, `@use "../abstracts" as *;`, put rules in `@layer components { … }`, add
   `@use "components/name";` to `assets/styles/_index.scss` (cascade order matters: later wins at equal specificity).
2. Colours only through roles: `var(--c-<role>)` (never hex). Compose states with `@include hover|pressed|focus|disabled`.
3. Surfaces: `@include texture-host; @include texture-tone("paper", "<role>")`; button-like things: family `"kraft"`. Re-emit
   `texture-tone` in states that change the fill. Tune with `--texture-strength`, never with `--texture-gain`.
4. Text on a new background: add the pair to `design-system/lib/contrast.js` and run `npm run contrast`.
5. Add `design-system/sections/NN-name.html` with every variant and state (forced classes), realistic French content.
6. Check light **and** dark, 1280 and 390 px, keyboard focus, reduced motion. `docker compose exec node npm run build` must stay green.

## Accessibility: contrast table

WCAG 2.x AA is mandatory in both themes: 4.5 : 1 for text, 3 : 1 for UI components (control borders, focus ring, star).
Generated by `npm run contrast` (the same pairs are shown live in the showcase, "Couleurs"); **every pair passes in both themes**.

### Thème clair

| Texte | Fond | Ratio | Minimum | Usage | |
|---|---|---:|---:|---|---|
| `fg` #2b2118 | `page` #f9f4eb | 14.37 | 4.5 | Texte courant sur le canvas | OK |
| `fg` #2b2118 | `surface` #fdfbf7 | 15.24 | 4.5 | Texte courant sur une carte | OK |
| `fg` #2b2118 | `surface-2` #f3eadd | 13.22 | 4.5 | Texte sur surface en creux | OK |
| `fg` #2b2118 | `surface-3` #e8dccb | 11.65 | 4.5 | Texte sur surface 3 | OK |
| `fg-soft` #4a4037 | `page` #f9f4eb | 9.22 | 4.5 | Chapô / standfirst | OK |
| `fg-soft` #4a4037 | `surface` #fdfbf7 | 9.77 | 4.5 | Chapô sur carte | OK |
| `fg-soft` #4a4037 | `surface-2` #f3eadd | 8.47 | 4.5 | Texte secondaire sur surface en creux | OK |
| `fg-soft` #4a4037 | `overlay` #fdfbf7 | 9.77 | 4.5 | Titre de catégorie du popover d’émojis (éditeur) | OK |
| `muted` #625951 | `page` #f9f4eb | 6.25 | 4.5 | Texte atténué (meta, placeholder) sur canvas | OK |
| `muted` #625951 | `surface` #fdfbf7 | 6.62 | 4.5 | post-meta, placeholder, légendes | OK |
| `muted` #625951 | `surface-2` #f3eadd | 5.75 | 4.5 | Texte atténué sur surface en creux | OK |
| `muted` #625951 | `surface-3` #e8dccb | 5.06 | 4.5 | Texte atténué sur surface 3 | OK |
| `link` #00487c | `page` #f9f4eb | 8.64 | 4.5 | Lien | OK |
| `link` #00487c | `surface` #fdfbf7 | 9.16 | 4.5 | Lien sur carte | OK |
| `link` #00487c | `surface-2` #f3eadd | 7.95 | 4.5 | Lien sur surface en creux | OK |
| `link-hover` #950952 | `page` #f9f4eb | 7.86 | 4.5 | Lien survolé | OK |
| `link-hover` #950952 | `surface` #fdfbf7 | 8.33 | 4.5 | Lien survolé sur carte | OK |
| `kicker` #8d5c2f | `page` #f9f4eb | 5.17 | 4.5 | Kicker (surtitre) | OK |
| `kicker` #8d5c2f | `surface` #fdfbf7 | 5.48 | 4.5 | Kicker sur carte | OK |
| `kicker` #8d5c2f | `surface-2` #f3eadd | 4.76 | 4.5 | Kicker sur surface en creux | OK |
| `accent-strong` #6c0038 | `page` #f9f4eb | 11.31 | 4.5 | Texte prune / onglet actif | OK |
| `accent-strong` #6c0038 | `surface` #fdfbf7 | 11.99 | 4.5 | Texte prune sur carte | OK |
| `accent-strong` #6c0038 | `surface-2` #f3eadd | 10.40 | 4.5 | Texte prune sur surface en creux | OK |
| `accent-strong` #6c0038 | `accent-soft` #fee5ed | 10.41 | 4.5 | Texte prune sur teinte prune (nav active, badge) | OK |
| `danger` #a13124 | `page` #f9f4eb | 6.43 | 4.5 | Erreur / texte danger | OK |
| `danger` #a13124 | `surface` #fdfbf7 | 6.82 | 4.5 | Erreur sur carte | OK |
| `danger` #a13124 | `surface-2` #f3eadd | 5.91 | 4.5 | Erreur sur surface en creux | OK |
| `danger` #a13124 | `danger-soft` #ffe4df | 5.84 | 4.5 | Texte danger sur teinte danger (alerte) | OK |
| `ok` #31653e | `page` #f9f4eb | 6.26 | 4.5 | Succès / texte ok | OK |
| `ok` #31653e | `surface` #fdfbf7 | 6.64 | 4.5 | Succès sur carte | OK |
| `ok` #31653e | `ok-soft` #def1e1 | 5.81 | 4.5 | Texte ok sur teinte ok (alerte) | OK |
| `on-accent` #fdfbf7 | `accent` #950952 | 8.33 | 4.5 | Bouton primaire | OK |
| `on-accent` #fdfbf7 | `accent-hover` #6c0038 | 11.99 | 4.5 | Bouton primaire (survol) | OK |
| `on-accent` #fdfbf7 | `accent-active` #470021 | 15.75 | 4.5 | Bouton primaire (pressé) | OK |
| `on-secondary` #fdfbf7 | `secondary` #00487c | 9.16 | 4.5 | Bouton secondaire | OK |
| `on-secondary` #fdfbf7 | `secondary-hover` #003a68 | 11.26 | 4.5 | Bouton secondaire (survol) | OK |
| `on-secondary` #fdfbf7 | `secondary-active` #002245 | 15.45 | 4.5 | Bouton secondaire (pressé) | OK |
| `on-highlight` #2b2118 | `highlight` #f0a868 | 7.88 | 4.5 | Bouton sable | OK |
| `on-highlight` #2b2118 | `highlight-hover` #d79457 | 6.20 | 4.5 | Bouton sable (survol) | OK |
| `on-highlight` #2b2118 | `highlight-active` #c58040 | 4.90 | 4.5 | Bouton sable (pressé) | OK |
| `on-danger` #fdfbf7 | `danger-fill` #a13124 | 6.82 | 4.5 | Bouton / badge danger plein | OK |
| `on-danger` #fdfbf7 | `danger-fill-hover` #861309 | 9.63 | 4.5 | Bouton danger (survol) | OK |
| `on-ok` #fdfbf7 | `ok-fill` #31653e | 6.64 | 4.5 | Badge ok plein | OK |
| `on-ok` #fdfbf7 | `ok-fill-hover` #194f29 | 9.27 | 4.5 | Badge ok (survol) | OK |
| `on-btn` #2b2118 | `btn` #f3eadd | 13.22 | 4.5 | Bouton neutre | OK |
| `on-btn` #2b2118 | `btn-hover` #e8dccb | 11.65 | 4.5 | Bouton neutre (survol) | OK |
| `on-btn` #2b2118 | `btn-active` #d2c5b2 | 9.28 | 4.5 | Bouton neutre (pressé) | OK |
| `danger` #a13124 | `btn` #f3eadd | 5.91 | 4.5 | Bouton danger (papier, texte brique) | OK |
| `fg` #2b2118 | `accent-soft` #fee5ed | 13.23 | 4.5 | Texte sur teinte prune | OK |
| `fg` #2b2118 | `secondary-soft` #e4eef8 | 13.41 | 4.5 | Texte sur teinte bleue | OK |
| `fg` #2b2118 | `highlight-soft` #ffe7d2 | 13.21 | 4.5 | Texte sur teinte sable (cible de permalien) | OK |
| `fg` #2b2118 | `danger-soft` #ffe4df | 13.06 | 4.5 | Texte sur teinte danger | OK |
| `fg` #2b2118 | `ok-soft` #def1e1 | 13.33 | 4.5 | Texte sur teinte ok | OK |
| `fg` #2b2118 | `mist-soft` #e6eff1 | 13.48 | 4.5 | Texte sur teinte brume | OK |
| `fg-soft` #4a4037 | `mist-soft` #e6eff1 | 8.65 | 4.5 | Texte secondaire sur teinte brume | OK |
| `fg` #2b2118 | `highlight-soft@0.62>surface` #feefe0 | 13.95 | 4.5 | Message ciblé par un permalien (.post.is-target) | OK |
| `fg-soft` #4a4037 | `highlight-soft@0.62>surface` #feefe0 | 8.94 | 4.5 | Texte secondaire, message ciblé | OK |
| `muted` #625951 | `highlight-soft@0.62>surface` #feefe0 | 6.06 | 4.5 | Méta de message ciblé | OK |
| `link` #00487c | `highlight-soft@0.62>surface` #feefe0 | 8.39 | 4.5 | Lien dans un message ciblé | OK |
| `danger` #a13124 | `highlight-soft@0.62>surface` #feefe0 | 6.24 | 4.5 | Action supprimer dans un message ciblé | OK |
| `accent-strong` #6c0038 | `highlight-soft@0.62>surface` #feefe0 | 10.98 | 4.5 | Texte prune dans un message ciblé | OK |
| `on-accent` #fdfbf7 | `scrim@0.82>on-accent` #403a34 | 10.80 | 4.5 | Légende de hero sur photo (voile 82 %, photo claire) | OK |
| `fg` #2b2118 | `surface@0.92>scrim` #ebe8e4 | 12.90 | 4.5 | Titre de hero en surimpression, photo sombre | OK |
| `fg` #2b2118 | `surface@0.92>on-accent` #fdfbf7 | 15.24 | 4.5 | Titre de hero en surimpression, photo claire | OK |
| `fg-soft` #4a4037 | `surface@0.92>scrim` #ebe8e4 | 8.27 | 4.5 | Surtitre / signature de hero, photo sombre | OK |
| `fg-soft` #4a4037 | `surface@0.92>on-accent` #fdfbf7 | 9.77 | 4.5 | Surtitre / signature de hero, photo claire | OK |
| `line-strong` #728b90 | `page` #f9f4eb | 3.30 | 3 | Bordure de champ / contrôle (UI) | OK |
| `line-strong` #728b90 | `surface` #fdfbf7 | 3.50 | 3 | Bordure de champ sur carte (UI) | OK |
| `line-strong` #728b90 | `surface-2` #f3eadd | 3.03 | 3 | Bordure de champ sur surface en creux (UI) | OK |
| `focus` #00487c | `page` #f9f4eb | 8.64 | 3 | Anneau de focus (UI) | OK |
| `focus` #00487c | `surface` #fdfbf7 | 9.16 | 3 | Anneau de focus sur carte (UI) | OK |
| `focus` #00487c | `surface-2` #f3eadd | 7.95 | 3 | Anneau de focus sur surface en creux (UI) | OK |
| `star` #b27742 | `page` #f9f4eb | 3.42 | 3 | Étoile / favori (UI) | OK |
| `star` #b27742 | `surface` #fdfbf7 | 3.62 | 3 | Étoile sur carte (UI) | OK |

### Thème sombre

| Texte | Fond | Ratio | Minimum | Usage | |
|---|---|---:|---:|---|---|
| `fg` #f9f4eb | `page` #2b2118 | 14.37 | 4.5 | Texte courant sur le canvas | OK |
| `fg` #f9f4eb | `surface` #362c22 | 12.44 | 4.5 | Texte courant sur une carte | OK |
| `fg` #f9f4eb | `surface-2` #40362c | 10.76 | 4.5 | Texte sur surface en creux | OK |
| `fg` #f9f4eb | `surface-3` #4e4339 | 8.77 | 4.5 | Texte sur surface 3 | OK |
| `fg-soft` #e8dccb | `page` #2b2118 | 11.65 | 4.5 | Chapô / standfirst | OK |
| `fg-soft` #e8dccb | `surface` #362c22 | 10.08 | 4.5 | Chapô sur carte | OK |
| `fg-soft` #e8dccb | `surface-2` #40362c | 8.72 | 4.5 | Texte secondaire sur surface en creux | OK |
| `fg-soft` #e8dccb | `overlay` #3b3127 | 9.39 | 4.5 | Titre de catégorie du popover d’émojis (éditeur) | OK |
| `muted` #c3b9b0 | `page` #2b2118 | 8.16 | 4.5 | Texte atténué (meta, placeholder) sur canvas | OK |
| `muted` #c3b9b0 | `surface` #362c22 | 7.07 | 4.5 | post-meta, placeholder, légendes | OK |
| `muted` #c3b9b0 | `surface-2` #40362c | 6.11 | 4.5 | Texte atténué sur surface en creux | OK |
| `muted` #c3b9b0 | `surface-3` #4e4339 | 4.98 | 4.5 | Texte atténué sur surface 3 | OK |
| `link` #acc4dc | `page` #2b2118 | 8.76 | 4.5 | Lien | OK |
| `link` #acc4dc | `surface` #362c22 | 7.59 | 4.5 | Lien sur carte | OK |
| `link` #acc4dc | `surface-2` #40362c | 6.56 | 4.5 | Lien sur surface en creux | OK |
| `link-hover` #f0a868 | `page` #2b2118 | 7.88 | 4.5 | Lien survolé | OK |
| `link-hover` #f0a868 | `surface` #362c22 | 6.82 | 4.5 | Lien survolé sur carte | OK |
| `kicker` #f0a868 | `page` #2b2118 | 7.88 | 4.5 | Kicker (surtitre) | OK |
| `kicker` #f0a868 | `surface` #362c22 | 6.82 | 4.5 | Kicker sur carte | OK |
| `kicker` #f0a868 | `surface-2` #40362c | 5.90 | 4.5 | Kicker sur surface en creux | OK |
| `accent-strong` #eaadc1 | `page` #2b2118 | 8.43 | 4.5 | Texte prune / onglet actif | OK |
| `accent-strong` #eaadc1 | `surface` #362c22 | 7.30 | 4.5 | Texte prune sur carte | OK |
| `accent-strong` #eaadc1 | `surface-2` #40362c | 6.31 | 4.5 | Texte prune sur surface en creux | OK |
| `accent-strong` #eaadc1 | `accent-soft` #5f1a38 | 6.65 | 4.5 | Texte prune sur teinte prune (nav active, badge) | OK |
| `danger` #f49383 | `page` #2b2118 | 7.01 | 4.5 | Erreur / texte danger | OK |
| `danger` #f49383 | `surface` #362c22 | 6.07 | 4.5 | Erreur sur carte | OK |
| `danger` #f49383 | `surface-2` #40362c | 5.25 | 4.5 | Erreur sur surface en creux | OK |
| `danger` #f49383 | `danger-soft` #54261f | 5.58 | 4.5 | Texte danger sur teinte danger (alerte) | OK |
| `ok` #88ca95 | `page` #2b2118 | 8.20 | 4.5 | Succès / texte ok | OK |
| `ok` #88ca95 | `surface` #362c22 | 7.10 | 4.5 | Succès sur carte | OK |
| `ok` #88ca95 | `ok-soft` #223d27 | 6.19 | 4.5 | Texte ok sur teinte ok (alerte) | OK |
| `on-accent` #fdfbf7 | `accent` #950952 | 8.33 | 4.5 | Bouton primaire | OK |
| `on-accent` #fdfbf7 | `accent-hover` #ac3769 | 5.80 | 4.5 | Bouton primaire (survol) | OK |
| `on-accent` #fdfbf7 | `accent-active` #6c0038 | 11.99 | 4.5 | Bouton primaire (pressé) | OK |
| `on-secondary` #fdfbf7 | `secondary` #1c5b91 | 6.88 | 4.5 | Bouton secondaire | OK |
| `on-secondary` #fdfbf7 | `secondary-hover` #3a6d9c | 5.28 | 4.5 | Bouton secondaire (survol) | OK |
| `on-secondary` #fdfbf7 | `secondary-active` #034a7e | 8.89 | 4.5 | Bouton secondaire (pressé) | OK |
| `on-highlight` #2b2118 | `highlight` #f0a868 | 7.88 | 4.5 | Bouton sable | OK |
| `on-highlight` #2b2118 | `highlight-hover` #ffd1aa | 11.21 | 4.5 | Bouton sable (survol) | OK |
| `on-highlight` #2b2118 | `highlight-active` #d79457 | 6.20 | 4.5 | Bouton sable (pressé) | OK |
| `on-danger` #fdfbf7 | `danger-fill` #a13124 | 6.82 | 4.5 | Bouton / badge danger plein | OK |
| `on-danger` #fdfbf7 | `danger-fill-hover` #b54436 | 5.28 | 4.5 | Bouton danger (survol) | OK |
| `on-ok` #fdfbf7 | `ok-fill` #31653e | 6.64 | 4.5 | Badge ok plein | OK |
| `on-ok` #fdfbf7 | `ok-fill-hover` #42774f | 5.10 | 4.5 | Badge ok (survol) | OK |
| `on-btn` #f9f4eb | `btn` #463b31 | 9.93 | 4.5 | Bouton neutre | OK |
| `on-btn` #f9f4eb | `btn-hover` #53483e | 8.11 | 4.5 | Bouton neutre (survol) | OK |
| `on-btn` #f9f4eb | `btn-active` #61564b | 6.52 | 4.5 | Bouton neutre (pressé) | OK |
| `danger` #f49383 | `btn` #463b31 | 4.84 | 4.5 | Bouton danger (papier, texte brique) | OK |
| `fg` #f9f4eb | `accent-soft` #5f1a38 | 11.33 | 4.5 | Texte sur teinte prune | OK |
| `fg` #f9f4eb | `secondary-soft` #05375f | 11.16 | 4.5 | Texte sur teinte bleue | OK |
| `fg` #f9f4eb | `highlight-soft` #53361b | 10.03 | 4.5 | Texte sur teinte sable (cible de permalien) | OK |
| `fg` #f9f4eb | `danger-soft` #54261f | 11.45 | 4.5 | Texte sur teinte danger | OK |
| `fg` #f9f4eb | `ok-soft` #223d27 | 10.86 | 4.5 | Texte sur teinte ok | OK |
| `fg` #f9f4eb | `mist-soft` #3b362e | 10.93 | 4.5 | Texte sur teinte brume | OK |
| `fg-soft` #e8dccb | `mist-soft` #3b362e | 8.86 | 4.5 | Texte secondaire sur teinte brume | OK |
| `fg` #f9f4eb | `highlight-soft@0.62>surface` #48321e | 10.93 | 4.5 | Message ciblé par un permalien (.post.is-target) | OK |
| `fg-soft` #e8dccb | `highlight-soft@0.62>surface` #48321e | 8.86 | 4.5 | Texte secondaire, message ciblé | OK |
| `muted` #c3b9b0 | `highlight-soft@0.62>surface` #48321e | 6.21 | 4.5 | Méta de message ciblé | OK |
| `link` #acc4dc | `highlight-soft@0.62>surface` #48321e | 6.66 | 4.5 | Lien dans un message ciblé | OK |
| `danger` #f49383 | `highlight-soft@0.62>surface` #48321e | 5.33 | 4.5 | Action supprimer dans un message ciblé | OK |
| `accent-strong` #eaadc1 | `highlight-soft@0.62>surface` #48321e | 6.41 | 4.5 | Texte prune dans un message ciblé | OK |
| `on-accent` #fdfbf7 | `scrim@0.82>on-accent` #2e2d2c | 13.29 | 4.5 | Légende de hero sur photo (voile 82 %, photo claire) | OK |
| `fg` #f9f4eb | `surface@0.92>scrim` #32281f | 13.09 | 4.5 | Titre de hero en surimpression, photo sombre | OK |
| `fg` #f9f4eb | `surface@0.92>on-accent` #463d33 | 9.75 | 4.5 | Titre de hero en surimpression, photo claire | OK |
| `fg-soft` #e8dccb | `surface@0.92>scrim` #32281f | 10.61 | 4.5 | Surtitre / signature de hero, photo sombre | OK |
| `fg-soft` #e8dccb | `surface@0.92>on-accent` #463d33 | 7.90 | 4.5 | Surtitre / signature de hero, photo claire | OK |
| `line-strong` #7d8f91 | `page` #2b2118 | 4.66 | 3 | Bordure de champ / contrôle (UI) | OK |
| `line-strong` #7d8f91 | `surface` #362c22 | 4.03 | 3 | Bordure de champ sur carte (UI) | OK |
| `line-strong` #7d8f91 | `surface-2` #40362c | 3.48 | 3 | Bordure de champ sur surface en creux (UI) | OK |
| `focus` #f0a868 | `page` #2b2118 | 7.88 | 3 | Anneau de focus (UI) | OK |
| `focus` #f0a868 | `surface` #362c22 | 6.82 | 3 | Anneau de focus sur carte (UI) | OK |
| `focus` #f0a868 | `surface-2` #40362c | 5.90 | 3 | Anneau de focus sur surface en creux (UI) | OK |
| `star` #f0a868 | `page` #2b2118 | 7.88 | 3 | Étoile / favori (UI) | OK |
| `star` #f0a868 | `surface` #362c22 | 6.82 | 3 | Étoile sur carte (UI) | OK |

### Fond sous texture (grain défavorable : 5e centile le plus sombre en clair, 95e le plus clair en sombre)

| Thème | Texte | Fond texturé | Ratio | Minimum | |
|---|---|---|---:|---:|---|
| light | `fg` | `page` #f1ece4 | 13.41 | 4.5 | OK |
| light | `fg-soft` | `page` #f1ece4 | 8.60 | 4.5 | OK |
| light | `muted` | `page` #f1ece4 | 5.83 | 4.5 | OK |
| light | `link` | `page` #f1ece4 | 8.06 | 4.5 | OK |
| light | `kicker` | `page` #f1ece4 | 4.83 | 4.5 | OK |
| light | `fg` | `surface` #f3f1ed | 13.97 | 4.5 | OK |
| light | `fg-soft` | `surface` #f3f1ed | 8.95 | 4.5 | OK |
| light | `muted` | `surface` #f3f1ed | 6.07 | 4.5 | OK |
| light | `link` | `surface` #f3f1ed | 8.40 | 4.5 | OK |
| light | `kicker` | `surface` #f3f1ed | 5.03 | 4.5 | OK |
| dark | `fg` | `page` #3a3128 | 11.63 | 4.5 | OK |
| dark | `fg-soft` | `page` #3a3128 | 9.43 | 4.5 | OK |
| dark | `muted` | `page` #3a3128 | 6.61 | 4.5 | OK |
| dark | `link` | `page` #3a3128 | 7.09 | 4.5 | OK |
| dark | `kicker` | `page` #3a3128 | 6.38 | 4.5 | OK |
| dark | `fg` | `surface` #433a30 | 10.19 | 4.5 | OK |
| dark | `fg-soft` | `surface` #433a30 | 8.26 | 4.5 | OK |
| dark | `muted` | `surface` #433a30 | 5.79 | 4.5 | OK |
| dark | `link` | `surface` #433a30 | 6.21 | 4.5 | OK |
| dark | `kicker` | `surface` #433a30 | 5.59 | 4.5 | OK |

**Result: All pairs pass WCAG AA in both themes.**

Nominal pairs also checked: see "Couleurs" in the showcase (computed live from the compiled CSS variables). Not covered by the checker, measured in a browser by the component workers: initials on the inline `hsl(hue 30% 32%)` avatars (white-ish text, worst hue 5.44 : 1 in both themes) and chat initials on `hsl(h 42% 42%)` with a 32 % ink veil (about 6 : 1 worst hue).

## Showcase

`docker compose up -d design-system` → **http://localhost:5174/** (plain HTTP, Vite dev server with hot reload; the port is published on
the host, override with `DESIGN_SYSTEM_PORT`). It is a separate Vite config (`design-system/vite.config.js`) importing the *same*
Sass tree (`design-system/showcase.scss` = `@use "../assets/styles/index"` + `ds-*` chrome), so it can never drift from the product.

- Header toggle = the anonymous-visitor module (`localStorage['comtso-theme']`); every demo has a **Clair | Sombre** switch (two
  theme islands side by side) and an **HTML** panel with the copy-paste markup generated from the demo itself.
- Sections (files `design-system/sections/NN-*.html`, sorted): couleurs (palette, scales, roles, live contrast), typographie,
  textures (matrix colours × families × themes, live `--texture-ratio` slider, wrong-vs-right blend mode, utility API), espaces &
  reliefs, mise en page (masthead, page header, layout), boutons, cartes, formulaires, retours (alerts, badges, skeleton, empty…),
  navigation (tabs, pager, dropdown…), données (tables, avatars), administration (filter bar, sortable table, forms, confirmation), messages, photos, réponse (attach + reply), hero, aperçus de sujet,
  chat, éditeur (the real `RichEditor` mounted), pages (login, register, errors, people, profile, settings).
- Adding a section: drop a file, no registration (see the header comment of `design-system/main.js`).
- Vue components with a backend (`ChatRoom`, `PhotoManager`, `ThemeToggle`) are replicated statically; `RichEditor` is mounted for real.

## Decisions, limits, performance

- `main.css` is ~280 kB raw / ~37 kB gzipped: 275 texture recipe rules (every colour × state × theme × island selector) are a third of
  it. They gzip very well (repetitive); the alternative (one recipe per tone, shared by `@extend`) breaks source-order resolution
  across `@layer` blocks, so the explicit form was kept.
- Island theming of the texture recipe supports one nesting level (`light > dark` or `dark > light`), enough for previews.
- Inputs, `<img>` and `<select>` cannot carry pseudo-elements: they are paper-coloured, without texture.
- Dev-only warning at build: Vite prints nothing for `@textures`/fonts (aliases resolve); the dev server needs `fs.allow` on the repo
  (set in `design-system/vite.config.js`).


## Emails

Every mail of the forum has an HTML and a plain-text part, built by **`App\Mail\TemplatedMailFactory::create($name, $subject, Address $to, array $context)`** (sender = `MAILER_FROM`, `site_url` added to the context from `DEFAULT_URI`, `app_name` is a Twig global). It renders `templates/email/<name>.html.twig` and `<name>.txt.twig` **immediately** and throws when a part is empty. Never leave rendering to the mailer: mails are delivered from `kernel.terminate` (`AfterResponseSubscriber`), and after FrankenPHP closed the response Twig cannot open an output buffer (`ob_start(): Failed to create buffer`), which sent password-reset mails with empty bodies. Prepare the message in the request, defer only `MailerInterface::send()`.

* `email/base.html.twig`: 600 px table layout, inline styles, light-theme tokens only (cream page `#f9f4eb`, sheet `#fdfbf7`, ink `#2b2118`, plum `#950952` button and rule, blue `#00487c` links, Georgia/Palatino stack for text and the `ComTSo` wordmark, Arial for buttons/footer), hidden preheader, footer "Vous recevez ce message parce que vous avez un compte sur ComTSo" + site link, a single optional `@media` block for narrow screens. Blocks: `preheader`, `content`, `footer`.
* `email/_macros.html.twig`: `heading`, `paragraph(text, muted)`, `button(label, url)` (bulletproof table button), `link_line(url)`, `divider`, `kicker`, `quote(excerpt, author)`, `items([{title, url, meta?, excerpt?}])` (digest lists). All arguments are escaped; build markup with `{% set %}` blocks.
* `email/base.txt.twig` + `email/_macros.txt.twig`: the plain-text twins (`heading`, `button`, `divider`, `quote`, `items`); URLs alone on their line, no escaping (never pass HTML).
* `tests/Mail/TemplatedMailFactoryTest::testADigestSampleReusesTheSharedLayoutAndMacrosInBothParts` locks this contract with a digest sample (shared base, `items`/`heading`/`button` macros, escaping in HTML, none in text): a future digest mail must keep passing it.
* New mail = `templates/email/<name>.html.twig` extending the base (`{% import 'email/_macros.html.twig' as m %}`, blocks `preheader` and `content`) + `<name>.txt.twig`, then `$factory->create('<name>', ...)`. No remote images or tracking pixels; the logo is the text wordmark.
