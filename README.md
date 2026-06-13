# particle-academy/fancy-cms

[![Fancified](art/fancified.svg)](https://particle.academy)

The Laravel host for **fancy-cms** — an extendable inline-WYSIWYG website +
app-shell builder built on the Fancy UI suite. This package owns the **PHP
renderer** (node tree → HTML + compiled CSS + island placeholders), and — in
later phases — persistence, the file API on any disk, and publish routing.

> **Status: Phase 0 (spine).** Ships `FancyCms\Render\CssEmitter` — the PHP half
> of the dual-emitter pair, a byte-for-byte mirror of the JS emitter in
> `@particle-academy/fancy-cms-ui`. Verified by the parity harness
> (`fancy-cms-ui/parity`). `PageRenderer` is a skeleton; persistence + routing
> land in Phase 1.

Architecture & plan: `fancy-ui/docs/fancy-cms.md`.

## Why two emitters

The editor (JS) and the published site (PHP) must produce identical CSS from the
same document — "what you build" === "what ships". The two emitters are kept
honest by a cross-engine parity harness, the same discipline used for
dark-slide / holy-sheet (PHP ≡ Node).

```bash
php bin/emit-css.php path/to/doc.json   # emit CSS for a Stages document
```

## Principles

- **Guest, not host** — adopted per-surface; respects host auth/layout/data.
- **Human+ is opt-in** — core works with no websockets or agents.

MIT © Particle Academy

---

## ⭐ Star Fancy UI

If this package is useful to you, a quick ⭐ on the repo really helps us build a better kit. Thank you!
