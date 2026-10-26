# Host-resolved fonts

Legacy `theme.font.body: "Inter, system-ui"` remains a CSS string with its
existing system fallback. A structured reference explicitly requires a host
asset. The renderer never downloads fonts or contacts Google.

```json
{"theme":{"font":{"body":{"family":"Inter","source":"bundled","weights":[400,600,700],"styles":["normal"]}}}}
```

References are recognized only at `theme.font.<role>`; custom role names work.
Use `fontFamily: "var(--cms-font-body)"` in node styles. Sources are `bundled`
or `google`. Family labels are exact, trimmed ASCII letters/digits/spaces and
`._+-`, at most 100 characters, beginning with a letter/digit. The host must
allowlist catalogue families. Weights are unique integers 1–1000, at most 16;
styles are unique `normal`/`italic`, defaulting to normal. The renderer sorts
requests, limits a page to 8 distinct source/family pairs and 32 unique face
requests, and resolves each unique request once per entrypoint invocation.

The synchronous host resolver receives normalized family/source/weights/styles
and returns `null` for a missing font, or an immutable asset:

```json
{
  "family":"Inter","source":"bundled","manifestSha256":"<64 lowercase hex>",
  "faces":[{"src":"/site-fonts/<content-sha>.woff2","sha256":"<64 lowercase hex>","weight":[100,900],"style":"normal"}]
}
```

The manifest hash identifies the exact pinned host manifest, including ordered
face descriptors and file digests. The host verifies bytes and licences before
publishing. Faces support scalar weights or increasing variable ranges, and
optional `unicodeRange` CSS ranges. Preserve all required unicode shards and
their order; at most 256 faces per manifest. URLs must be canonical root-relative
paths ending in `.woff2`, with ASCII alphanumeric/underscore/hyphen path starts
and alphanumeric/underscore/hyphen/dot continuations. Queries, fragments,
percent escapes, dot segments, empty segments, backslashes, controls and
external/protocol-relative/data/blob/file URLs are refused. Serve without redirects.

The CSS family is `cms-font-<manifestSha256>`, independent of installed fonts.
The theme variable contains this quoted alias; no reference internals leak into
tokens. Output has no `local()` sources, disables synthesized faces and optical
sizing on CMS nodes, and preserves the static base stylesheet and all legacy
string output. Select `swap` for live pages, `block` for captures:

```php
use FancyCms\Render\BundledFonts;
use FancyCms\Render\PageRenderer;

// Explicitly publish interFiles() to your same-origin immutable route first.
$inter = BundledFonts::interAsset(fn (array $file) => '/site-fonts/'.$file['sha256'].'.woff2');
$resolve = fn (array $ref) => $ref['source'] === 'bundled' && $ref['family'] === 'Inter' ? $inter : null;
$page = PageRenderer::render($doc, fontResolver: $resolve, fontDisplay: 'block');
// Direct CSS: CssEmitter::emit($doc, $resolve, 'block').
```

`fonts/inter-4.1/` contains the official, unmodified variable normal/italic files,
OFL.txt, NOTICE and a manifest recording the archive and per-file hashes. No
route, stylesheet or font request is installed automatically. Keep the licence
and NOTICE with copied assets; the package's MIT licence does not replace OFL.

In JS pass `{fontResolver, fontDisplay}` to `emitDocCss` or the same named props
to CmsPage, CmsRegion, RenderNode, Editor, Canvas and EditablePage. Setting
`includeStyles={false}` still resolves and validates references; supply identical
options when emitting the host stylesheet. JS exports FontRef/FontReference,
FontAsset, FontAssetResolver, FontRenderOptions and FontResolutionException.
Npm assets are available at `@particle-academy/fancy-cms-ui/fonts/inter/*`;
the host reads manifest.json and maps its ordered faces to published local URLs.

Missing resolver/font/weight/style, invalid reference/metadata, and unsupported
display policy throw `FontResolutionException` with a constant `reason`; callback
exceptions become `resolver-failed` without leaking host details. Live hosts
should catch the error and show an operator-visible state; capture hosts must
refuse capture. `document.fonts.ready` alone does not prove a web font loaded:
capture validation must check actual loaded faces and custom-font glyph use.
There is no Google importer in these renderer packages; importing, licence
verification, same-origin publication, manifest pinning and capture checks are
host responsibilities.
