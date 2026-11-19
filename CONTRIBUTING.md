# Contributing to particle-academy/fancy-cms

Notes for anyone, human or agent, working ON this package's code. How to USE the
package is in [AGENTS.md](./AGENTS.md); what it is, is in [README.md](./README.md).
`CLAUDE.md` is a symlink to this file, so Claude Code reads these notes when it
works in this repo.

## Test

```sh
composer test   # Pest; the suite lives in tests/
```

Update `CHANGELOG.md` in the same commit as the change.

## The agent guide is a pinned copy

`agent-guide/professional-pages.md` is byte-identical to the canonical
fancy-cms-ui guide. Tests pin its SHA-256 and compare the sibling copy when
present; CI also checks out the UI guide and compares the bytes. Change both
copies and the pin together.

## Parity checks

```sh
composer test
cd ../fancy-cms-ui
npm run build
node parity/run-ops.mjs
node parity/run.mjs
node parity/html.mjs
```

The operation harness compares every intermediate document, invalid-operation
reason, inverse, wire inverse replay and `reduceAll` result, plus fractional
keys. Only JSON object-key order is canonicalized. Hand-written edge cases and
the committed seed `0x5eedc0de` cover 28 sequences, 1,090 intermediate states
and 520 key cases, plus 14,200 number-format cases. The CSS harness requires byte-identical output for all
canonical documents, including hostile input and JSON/number-format fixtures.
The HTML runner compares `renderToStaticMarkup(CmsPage)` with
`php bin/render-html.php <doc.json>` without styles or an added newline. All
five canonical pages, including the premium reference, must match byte for
byte in both CSS and HTML; these corpora cover the supported native subset.
