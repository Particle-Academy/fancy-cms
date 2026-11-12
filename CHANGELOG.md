# Changelog

Notable changes to `particle-academy/fancy-cms`.

**BREAKING** marks anything that can stop working on upgrade. This package is
pre-1.0, so breaking changes land in MINOR releases — read those entries before
upgrading.

> Entries below **1.0** were reconstructed from git history when this file was
> introduced, so they summarise commit subjects rather than consumer impact.
> Everything from the next release onward is written by hand, in the same commit
> as the change.

---

## [Unreleased]

## [0.9.0] - 2026-11-12

**BREAKING only if your documents already use a node type named `chart`,
`kpi`, `table` or `leaderboard`** for something of your own: those are now
native node types in `PageDoc::NODE_TYPES`. Rename yours (a namespaced island
such as `kit:Chart`). Everyone else: nothing to do. The same holds for `input`.

### Added

- **Page inputs** (phase 2 of report dashboards): an `input` node (`period`,
  `daterange`, `person`, `select`, `multiselect`) with a `name`, a `default` and
  literal or `$bind`-ed options, rendered by `PageRenderer` as native labelled
  controls, byte-identical to `@particle-academy/fancy-cms-ui` (shared spec
  `tests/fixtures/inputs.json`). `PageRenderer::render(..., inputs: $values)`
  renders the values the data was fetched for.
- `Data\PageInputs`: `declared()`, `values()`, `fromQuery($doc, $request->query(), $data)`
  and `toQuery()`. A value that is not an option, an impossible date or a
  reversed range falls back to the default, so a URL cannot put the page in a
  state its controls cannot show. Identical to fancy-cms-ui's functions.
- **Charlotte's binding keys, carried natively.** `DataRequests::of()` returns
  a binding's `$params` and `$scope`. `DataRequests::queries($doc, $inputs, $data)`
  returns one request per distinct `(stream, alias, params, scope)`, keyed by
  the path's first segment (`<stream>` or `<stream>#<alias>`, whose result the
  host puts in `$data` under that key), with every `{"$input": name}` filled
  from the page's input values and the inputs each query reads. fancy-cms never
  interprets a scope: the host applies it against the viewer. The schema
  refuses a non-object `$params` / `$scope` where it checks a binding
  (`repeat.each`). Shared spec `tests/fixtures/data-queries.json`.
- `PageRenderer::styleTag($doc, $css)`: the page's `<style>` for the host's
  `<head>`, byte-identical to fancy-cms-ui's `cmsStyleTag()`, which the browser
  renderer reuses instead of adding a second (lab finding K20: the React side
  used to put it inside the host's `<div>`).
- `bin/render-html.php` takes page input values as a 4th argument, for the
  cross-engine parity harness.
- **Infographic node kit**, rendered by `PageRenderer`: `chart` (column, bar,
  line, pie, funnel; the server renders an accessible data table, and the
  browser loads the chart as a lazy island), `kpi` (value, label, delta, trend;
  number / currency / percent), `table` (typed columns, sort, totals row) and
  `leaderboard` (ranked rows, ties share a rank). Each binds its numbers with
  `$bind` from the `$data` you already pass to `render()`. Missing or malformed
  data renders an empty state, never an exception. Markup is byte-identical to
  `@particle-academy/fancy-cms-ui`'s `CmsPage`; both suites render the shared
  spec in `tests/fixtures/infographics.json`.
- **`FancyCms\Islands\IslandCatalogue`**: the island catalogue, each type with a
  JSON Schema for its props, an example and how it hydrates. `builtin()` holds
  the infographic kit (`resources/islands/catalogue.json`, shipped
  byte-identical in fancy-cms-ui). `register($type, [...])` adds a host island
  (`kit:Tabs`); `merge($json)` takes the catalogue a JS host exports with
  `islandCatalogue(islands)`; `nodeTypes()` is the `$knownTypes` list for
  `PageDoc` / `PageSession`. Immutable, so one catalogue built at boot is safe
  to share.
- **`Agent::describe(?IslandCatalogue $islands)`** returns the catalogue under
  `islands` and lists every island type in `elements`, so `cms_describe` tells
  an agent which types the host renders and what props each takes. The base
  catalogue keeps its 8,000-byte budget; the built-in island schemas have a
  separate 5,000-byte budget (4,640 today).
- **`PageRenderer::render(..., islands: $catalogue)`** marks registered host
  island types with `data-cms-island` without the node setting `island`.
- **`FancyCms\Data\DataRequests::of($doc)`**: every bound path a page reads,
  with the literal schema the catalogue declares for it. The seam where a host
  learns what data to supply; page inputs and a host data catalogue attach here
  later.
- An infographic stylesheet block (light, dark, print, theme tokens), emitted
  by `CssEmitter` only for documents that place an infographic node.
- `bin/render-html.php` takes an optional data-context file as a third
  argument, for the parity harness.

### Changed

- The built-in island catalogue's byte budget is 6000 (was 5000): `input` and
  the `$params` / `$scope` binding keys cost 1273 bytes after trimming.

### Fixed

- The chart's visually hidden data table was as wide as its container (the
  chart table's `width: 100%` came after the screen-reader rule at the same
  specificity), so a page with a chart scrolled sideways on a phone. It is 1px
  wide again; byte-identical to fancy-cms-ui (shared `infographics.json` css).
- **A long unbroken token scrolled the page sideways on a phone** (lab finding
  S11: hostile-facts measured 380 px wide at 375 px). `CssEmitter::BASE_CSS`
  gives text-bearing nodes (headings, text, lists, links, callouts)
  `overflow-wrap: anywhere`: `anywhere`, not `break-word`, because only
  `anywhere` lowers min-content width, which grid tracks and flex items are
  sized from. Text that fits lays out exactly as before; buttons keep their
  labels whole. New style key `overflowWrap` (`normal` | `break-word` |
  `anywhere`), validated by the schema, listed by `Agent::describe()`, and
  dropped by the emitter for any other value. Byte-identical to fancy-cms-ui
  (shared `tests/fixtures/text-wrap.json`). Consumers do nothing.

### Security

- **`PageRenderer::styleTag($doc, $css)` can no longer be closed early by its
  `$css`.** It is public, so a host can pass CSS that did not come from the
  emitter, and it wrote that CSS raw into `<style>…</style>`: a
  `</style><script>` in it ended the element. Every `<` that starts `</` or
  `<!` is now written as the CSS escape `\3C ` (new `PageRenderer::styleText`),
  so no `</style` in any case and no `<!--` reaches the HTML, and the CSS still
  means the same thing in a string, identifier, `url()` or comment. CSS from
  `CssEmitter` is unchanged byte for byte. Byte-identical to fancy-cms-ui's
  `styleTagHtml` (shared fixture `tests/fixtures/inputs.json`,
  `styleTagHostile`). Nothing to do as a consumer.
- **Number formatting groups thousands with a loop**, not the lookahead regex
  it used, which is quadratic in the number of digits and, on a 100k-digit
  string, hit PCRE's limit and returned an empty string. Output is unchanged; a
  finite number has at most about 330 digits, so no rendered page was affected.
  Mirrors fancy-cms-ui's fix for CodeQL `js/polynomial-redos`.
- **`JsValue::trim` is a loop**, not a `^[…]+|[…]+$` regex. It trims an
  author-written `href` (and a heading `level`), so it handles consumer input.
  With PCRE's JIT the regex happened to run in linear time. Without it
  (`pcre.jit=0`, or when JIT memory cannot be allocated) a 100k whitespace run
  inside an href took 105 s, and a 1M run hit the backtrack limit and returned
  an empty string, so the link silently rendered as a `<span>`. It trims
  exactly the same 25 code points as JavaScript's `String.prototype.trim`, and
  invalid UTF-8 still gives `''`. Nothing to do as a consumer.

## [0.8.1] - 2026-10-26

**Nothing to do:** a documentation addition and a CLI fix. The renderer API is unchanged.

### Added
- A webmaster guide, `agent-guide/webmaster.md`, shipped in the package: the
  host-side reference for running a site on fancy-cms. It covers the render
  route and recommended CSP, image and font resolvers, accessibility and
  responsive checks, the CLIs, pre-publish verification and current
  limitations. It pairs with the authoring guide in
  `@particle-academy/fancy-cms-ui`.

### Fixed
- `bin/render-html.php`, and `bin/emit-css.php` without `--fonts-stdin`, no
  longer crash on a document with a structured font. They have no font
  resolver, so they now refuse it like other bad input: exit 3 with the
  constant reason (`missing-resolver`) on stderr, instead of an uncaught
  exception (exit 255).

## [0.8.0] - 2026-10-26

**Nothing to do unless you opt in:** documents that keep string font tokens render byte-identically. To use a web font, pass a `fontResolver` that maps the reference to same-origin WOFF2 URLs you publish yourself; the renderer never fetches fonts or contacts Google.

### Added
- Structured `theme.font.<role>` references with a synchronous host `fontResolver`,
  manifest-derived families, same-origin WOFF2 `@font-face` output and explicit
  `FontResolutionException` reasons for missing or invalid fonts. Live output
  defaults to swap; trusted capture callers can select block. Legacy string
  tokens retain their exact output. The PHP/JS emitters share positive and
  negative parity checks.
- Opt-in, unmodified Inter 4.1 variable normal/italic assets, OFL licence,
  NOTICE and archive/file SHA256 manifest. `BundledFonts::interFiles()` exposes
  publication metadata; `interAsset()` maps explicitly published host URLs.

## [0.7.1] - 2026-10-26

### Fixed

- Native icons retain their 1em sizing and alignment under nonce-only Content
  Security Policy. Fixed wrapper declarations now live in the shared base
  stylesheet, and native rendering emits no generated inline style attributes.
  Nothing to do unless you relied on the inline style; the base stylesheet now
  carries it. PHP/React markup and CSS remain in parity.

## [0.7.0] - 2026-10-26

### Added

- Host-resolved responsive image assets: literal `props.asset` handles resolve
  through the optional third `PageRenderer::render` argument. Measured intrinsic
  dimensions and declared variants/sizes produce the same HTML/preload hints as
  React. Unknown or invalid assets render without a source; no authored-URL
  fallback. Existing images without `asset` retain their legacy behavior.
- Document a standalone CDN-manifest resolver, catalogue responsibilities and
  the shared host asset contract in the agent guide. No dependencies added.

## [0.6.0] - 2026-10-24

### Added

- Full Fancy Lucide 0.511.0 catalogue (1,803 names/aliases, 1,594 glyphs), bounded
  `Agent::icons`/`Icons::search` pagination for `cms_icons`, and a compact
  `Agent::describe` rule rather than a full name dump. Glyph JSON loads lazily
  per icon; class loading reads no catalogue. Source version/content hash and
  the complete Lucide ISC licence/NOTICE ship with the package; the UI twin's
  offline generator checks both artifacts against installed Fancy's Lucide.

- Native `icon` leaf nodes with a closed name catalogue, explicit decorative
  flag and accessible label for meaningful icons. Size and colour use existing
  responsive font-size styles and theme tokens. No SVG, paths, bindings or
  arbitrary attributes may enter through icon props.
- PHP/React HTML, CSS and operation parity fixtures, including hostile names,
  invalid edits and exact wire undo when an optional label is first introduced.
  The generated SVG data and Fancy-compatible wrapper are shared with
  fancy-cms-ui; JavaScript keeps its react-fancy peer optional.

### Security

- Decorative frame/shape subtrees accept only frames, shapes and decorative
  icons without actions. Validation, insertion, prop merges, moves and action edits refuse meaningful
  descendants. Direct malformed rendering fails visible; safe artwork emits
  `aria-hidden="true"`. The matching HTML/operations parity includes hostile
  attempts to hide links, headings, CTAs and nested subtrees.
- **BREAKING for documents using `decorative` as unrelated metadata:** the prop
  is now reserved for frame/shape/icon. Rename such metadata on other node types.
  Existing documents without that prop need no change.

- Icon insertion and merged prop edits validate before mutation. Invalid
  operations remain logged no-ops; icons reject children. Direct malformed
  rendering emits no SVG or meaningful-image role.

## [0.5.0] - 2026-10-23

### Added

- `FancyCms\Agent::guide()` ships the professional-page guide used by the
  website-builder lab. A SHA-256 pin and a direct PHP/UI byte comparison guard
  the shared text; CI fetches the canonical UI guide for that comparison.
- `Agent::describe()` returns the compact JSON-friendly operation, element,
  style and breakpoint catalogue. Its shorthand is documented, with an
  8,000-byte regression budget. It excludes host-authored `richtext` from the
  agent element list and identifies browser-only behavior.
- `FancyCms\PageSession` holds one document and an attributed op log. Batches
  validate through `PageDoc::apply` and commit atomically. Each op gets an
  opaque revision; a stale `basedOn` refuses the batch with
  `surface_changed_under_you`. Errors carry an operation index and path.
- `changesSince()` returns a complete chronological feed of created, updated,
  deleted and moved handles with `human`/`agent` actors, including subtree
  tombstones. Unknown cursors fail explicitly instead of reporting silence.
  `node:<id>` and `page:<doc.id>` handles keep node and whole-page edits distinct;
  id components are URL-encoded so whitespace and Unicode survive parsing.
- Versioned `toJson()`/`fromJson()` storage preserves object/list tags,
  operation history, cursors and actors. Restore revalidates and replays;
  returned documents/logs cannot mutate the session through shared objects.
  Limits refuse writes before committing: 1,000 ops per batch, 10,000 retained
  ops and 32 MiB of encoded state. History is never silently pruned.
- The fixed 0.9 base stylesheet and native button, callout, code, divider and
  image markup matching fancy-cms-ui. `bin/render-html.php` emits static HTML
  for the new cross-runtime parity gate. Missing legacy node props/style.base
  no longer prevent rendering.

### Changed

- **BREAKING:** CSS now starts with the scoped base stylesheet, resetting
  default heading/paragraph/list margins and styling native elements. Always
  include `PageRenderer::render(...)["css"]`; per-node rules come later and
  override the base. Review custom spacing that previously relied on browser
  margins. Images use `data-cms-fit` instead of inline styles; update selectors
  that inspected their old `style` attribute. Built-in islands render their
  native element; unknown islands remain placeholders.

### Fixed

- PHP now renders a valid `props.anchor` as the semantic element's `id`,
  matching the UI's lowercase-slug allowlist. In-page links can target it;
  invalid or injected anchors are ignored, including bound values that fail
  the same check.
- Eager-image HTML includes the native SSR preload hint, deduplicated within
  each render; lazy/data images add no hint and later pages cannot inherit it.

### Integration

- The host must lock or compare-and-swap stored state across load/apply/save,
  provide authenticated actors, require observed revisions on agent writes,
  authorize operations and broadcast results. `PageSession` makes no network
  calls and does not authenticate storage. Omitting `basedOn` is an explicit
  unconditional write. Read the README's two-writer example before integrating.

### Validation

- 121 PHP tests / 387 assertions cover the new agent
  contracts, anchors, renderer markup and malformed-history controls were red
  before implementation. Isolated stale-write, partial-commit, tombstone and
  guide-drift controls fail without mutating production source.
- All five canonical pages match byte for byte in both HTML and CSS against
  fancy-cms-ui 0.9. Existing reducer/key/number differential checks still pass:
  28 sequences, 1,090 intermediate states/inverses/undo, 520 keys and 14,200
  number strings. The actual prism-human-plus parser accepts the complete
  human-attributed change feed and preserves its revision.

## [0.4.0] - 2026-10-23

### Added

- Server page-operation spine matching fancy-cms-ui 0.8: all thirteen
  `PageOp` variants, pure `Reducer::reduce`/`reduceAll`, JSON-wire inverses,
  tree helpers and matching base-62 fractional keys. Applied edits increment
  `seq`; invalid targets, cycle moves and non-permutation root reorders keep
  it unchanged. Merge patches preserve null values and stay shallow.
- `PageDoc` and `PageOp` validation with structured path errors, known node
  types/host registries, existing parents, iterative cycle detection and
  bounds on nodes/JSON depth/value count. `PageDoc::apply` validates before
  and after an edit and refuses semantic no-ops at the storage boundary.
- `PageJson` decoding/encoding preserves empty object/list distinctions,
  numeric map keys and opaque JSON values; non-JSON objects are refused before
  a serialization callback can run. Use this codec for incoming/stored JSON.
- PHP parity for 0.8 theme tokens, responsive stack/grid controls, auto-fit
  grids, per-side spacing, sizing, sticky positioning, visibility and other
  style additions. Values pass the same CSS escape, length, keyword or number
  gate as JavaScript. Selector ids cannot terminate an inline stylesheet.
- Static headings, paragraphs, safe links/buttons, lists, landmark containers
  and image attributes, plus bindings into the explicitly supplied data context.

### Changed

- **BREAKING:** `PageRenderer` now puts the stable `data-cms` handle on a
  semantic element (p, h1–h6, a, ul/ol or a landmark) instead of always a div.
  Update custom `div[data-cms]` selectors to `[data-cms]` where needed, and
  set margins explicitly when the browser's semantic-element defaults differ.
  Legacy `sections[]` ordering stays authoritative for legacy documents.
- Invalid CSS units/keywords/numbers and declaration/rule escapes are dropped
  by the emitter itself. Valid data URLs and quoted semicolons stay supported.

### Validation

- 87 PHP tests / 254 assertions. Initial operation tests were red on 0.3;
  semantic/emitter and boundary controls were red before each implementation.
- Cross-runtime parity: 28 sequences, 1,090 intermediate documents, inverse
  operations and wire inverse replays, 520 key cases and 14,200 number strings; committed seed
  `0x5eedc0de`. Only JSON key order is normalized. Isolated mutations that
  ignore property patches or return wrong fractional keys both fail the gate.
- Four canonical CSS documents match byte for byte, including hostile ids,
  malformed declarations and JSON/number-format edge cases. No dependencies,
  manifest constraints or workflow changes.

## 0.3.0 — 2026-08-09

### Fixed

- **`PageRenderer` renders documents written by `@particle-academy/fancy-cms-ui`
  after it dropped `sections[]`.** That release orders top-level nodes by their
  fractional `order` key like any other sibling group; this renderer only ever
  read `$doc['sections']`, so a current-shape document rendered as an **empty
  page** — no error, no warning, just nothing.

  Both shapes are now read, distinguished by presence rather than a version
  field, because a host upgrades its PHP and its JS at different moments and has
  to serve both in between.

  Where `sections` exists it **wins over the order keys**, which is the subtle
  half: the old JS `reorder_sections` op permuted the array and left every
  node's `order` key untouched, so on a page whose sections were ever
  rearranged the keys are stale and the array is the only record of the real
  order. Preferring the keys there would have rendered a live page in the order
  it was first authored in.

  An empty `sections: []` is treated as the legacy shape — "this document has no
  sections" — not as the new shape, so removed roots stay removed.

### Added

- A test suite. This package had no `tests/` directory, no `composer test`
  script, and no CI, so the renderer's behaviour was never executed anywhere —
  which is how the blank-page break above could have shipped unnoticed. 11 tests
  now cover both document shapes, nested ordering, island placeholders, stable
  `data-cms` handles, and escaping of author content and node ids.
- `composer test`, a `phpunit.xml`, and a `Tests` workflow on push + PR, matching
  the CI standard used across the suite's PHP packages.

## 0.2.0 — 2026-08-07

### Changed

- **BREAKING — PHP 8.2 is no longer supported.** `require.php` moves from `^8.2` to `^8.4`.

  **What you must do:** on PHP 8.4 or newer, nothing. On 8.2, either upgrade PHP first or stay on the previous release — it keeps working and is unaffected by this.

- **BREAKING — Laravel 11 and 12 are no longer supported.** The framework requirement narrows from `^11.0 || ^12.0 || ^13.0` to `^13.0`.

  **What you must do:** on Laravel 13, nothing. On 11 or 12, stay on the previous release until you upgrade the framework.

- CI now tests PHP 8.4 with Laravel 13 only, instead of a matrix spanning versions this package no longer claims to support. A matrix that tests what the manifest forbids is worse than none — it reports green for a combination nobody can install.

### Why

These are the kit 0.5 platform floors. The suite was split across PHP 8.2 and 8.3 with the framework spanning 11–13, so no package could rely on anything newer than its weakest sibling. Every PHP package in the kit takes the same floors at once, so a consumer never has to resolve a mix.

Pre-1.0, so this lands in a MINOR. **No API changed, nothing was removed, nothing was renamed** — only what the package requires.


## 0.1.0 — 2026-07-05

### Added

- **render:** emit node className as a class attribute
- fancy-cms — PHP CssEmitter (dual-emitter mirror) + PageRenderer + ServiceProvider (Phase 0+1)
