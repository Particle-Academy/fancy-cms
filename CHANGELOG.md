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
