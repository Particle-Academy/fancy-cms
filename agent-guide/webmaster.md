# Webmaster guide: fancy-cms (PHP host)

For whoever runs a site on `particle-academy/fancy-cms`: the developer wiring
routes and resolvers, and the human or agent publishing pages through it. It
covers the server side: rendering, resolvers, the render route's security
headers, the CLIs and pre-publish checks.

Related guides:

- `@particle-academy/fancy-cms-ui` ships its own `agent-guide/webmaster.md` for
  the authoring side (React renderer, editor surfaces, building pages).
- `agent-guide/professional-pages.md` is the page bar agents build against
  (`Agent::guide()` returns it).
- `agent-guide/fonts.md` is the full font reference/asset contract.

## What the package does, and what the host does

The package validates documents and ops (`PageDoc`, `PageOp`, `PageJson`,
`PageSession`), renders a document to static HTML plus CSS
(`PageRenderer::render`, `CssEmitter::emit`), resolves host images and fonts
through callbacks you supply, and exposes the agent catalogue
(`Agent::guide()`, `Agent::describe()`, `Agent::icons()`).

The host must do the rest. `FancyCmsServiceProvider` currently registers
nothing: there are no routes, no storage, no authorization and no `<head>`
output. In particular:

- The host owns the publish route, the HTML shell, `<title>` and meta tags
  (read them from `$doc['meta']` and escape them yourself).
- The host owns storage, revision transactions and who may write.
- The host serves every image and font file. The package never fetches
  anything over the network.
- The host owns screenshots/captures and the checks run on them.

## 1. The render route

`PageRenderer::render(array $doc, array $data = [], ?callable $imageResolver = null, ?callable $fontResolver = null, string $fontDisplay = 'swap')`
returns `['html' => string, 'css' => string]`. The HTML is the page body: eager
image preload hints first, then each root node. The CSS is the fixed base
stylesheet (`CssEmitter::BASE_CSS`), any `@font-face` blocks, theme tokens and
per-node rules. Serve both together.

```php
use FancyCms\Document\PageJson;
use FancyCms\Exceptions\FontResolutionException;
use FancyCms\Render\PageRenderer;
use Illuminate\Support\Facades\Route;

Route::get('/p/{slug}', function (string $slug) use ($pages, $imageResolver, $fontResolver) {
    $doc = PageJson::decodeDocument($pages->publishedJson($slug)); // host storage

    try {
        $page = PageRenderer::render($doc, [], $imageResolver, $fontResolver, 'swap');
    } catch (FontResolutionException $e) {
        // $e->reason is a constant string, safe to show to an operator.
        report($e);
        return response()->view('cms.render-error', ['reason' => $e->reason], 500);
    }

    $nonce = base64_encode(random_bytes(16));

    return response()
        ->view('cms.page', [
            'title' => $doc['meta']['title'] ?? '',
            'css' => $page['css'],
            'body' => $page['html'],
            'nonce' => $nonce,
        ])
        ->header('Content-Security-Policy', implode('; ', [
            "default-src 'none'",
            "script-src 'none'",
            "style-src 'nonce-{$nonce}'",
            "img-src 'self'",
            "font-src 'self'",
            "base-uri 'none'",
            "form-action 'none'",
            "frame-ancestors 'none'",
        ]));
});
```

```blade
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{{ $title }}</title>
  <style nonce="{{ $nonce }}">{!! $css !!}</style>
</head>
<body>{!! $body !!}</body>
</html>
```

The rendered body is already escaped by the renderer; do not escape it again.
Escape everything you add to the shell yourself, as Blade's `{{ }}` does.

## 2. Security: CSP and inert output

What the renderer guarantees:

- **No inline style attributes.** Every rule is in the returned CSS, keyed by
  `[data-cms="<id>"]`. Icon wrapper sizing lives in `CssEmitter::BASE_CSS`
  (`:where([data-react-fancy-icon])`). `tests/NoInlineStylesTest.php` holds
  this. So `style-src` needs only the nonce, or `'self'` if you write the CSS to
  a same-origin file.
- **No scripts.** The static HTML carries no script and no event-handler
  attributes. Islands (`data-cms-island`), animations, actions and repeater
  behaviour need a browser runtime, so under `script-src 'none'` they stay
  static or empty.
- **Safe links.** `PageRenderer::safeHref` keeps relative URLs, fragments and
  `http`, `https`, `mailto` and `tel`, and drops every other scheme. External
  http(s) links get `rel="noopener noreferrer"`.
- **Escaped text.** Text, labels, list items, alt text and attributes are
  escaped. `richtext` is not emitted by the PHP renderer at all.

The CSP above is **recommended host configuration**, not something the package
sets. It suits a route serving agent-authored content. Its consequences:

- `img-src 'self'` means your `imageResolver` must return root-relative
  sources. If it returns CDN URLs, add that exact origin to `img-src`.
- `font-src 'self'` means every font face must be a same-origin WOFF2. The font
  contract already refuses anything else (see `fonts.md`).
- Keep the authoring editor on a separate, authenticated route with its own
  policy. That editor lives in the UI package.

For agent writes, pass an explicit type list without `richtext`; the browser
renderer injects `richtext` HTML as-is:

```php
use FancyCms\Document\PageDoc;
use FancyCms\PageSession;

$types = array_values(array_diff(PageDoc::NODE_TYPES, ['richtext']));
$session = new PageSession($doc, $types);
```

## 3. Fonts

**What goes wrong.** A theme can name a font without loading it:
`"font": {"body": "Inter, system-ui, sans-serif"}` is a plain CSS string, and
each visitor gets whatever their machine has. In practice, a 1280px headline
wrapped to 3 lines on Windows and 4 on Linux. A layout "fitted" on one machine
broke on another.

**The fix.** Use a structured reference at `theme.font.<role>` and a host
`fontResolver` that returns same-origin WOFF2 faces:

```json
{"theme": {"font": {"body": {"family": "Inter", "source": "bundled", "weights": [400, 600, 700]}}}}
```

Node styles then use `"fontFamily": "var(--cms-font-body)"`. The token holds
only the quoted alias `"cms-font-<manifestSha256>"`, with no fallback stack.

The bundled Inter 4.1 is opt-in. Publish its files yourself, then resolve:

```php
use FancyCms\Render\BundledFonts;

// Deploy step: copy the verified files to an immutable same-origin path.
// interFiles() checks each file's size and SHA-256 and throws on a mismatch.
foreach (BundledFonts::interFiles() as $file) {
    copy($file['path'], public_path('site-fonts/'.$file['sha256'].'.woff2'));
}

// Request time: a synchronous, closed lookup.
$inter = BundledFonts::interAsset(fn (array $file) => '/site-fonts/'.$file['sha256'].'.woff2');
$fontResolver = fn (array $ref) => $ref['source'] === 'bundled' && $ref['family'] === 'Inter' ? $inter : null;
```

Ship `fonts/inter-4.1/OFL.txt` and `NOTICE` with the copied files.

Rules:

- **Never hot-link Google Fonts.** The package has no Google importer. For a
  `"source": "google"` family, the host downloads the WOFF2 files, checks the
  licence, serves them same-origin and returns them from the resolver. This
  keeps `font-src 'self'` intact and sends no visitor data to a third party.
- **Display policy.** Use `'swap'` (the default) for live pages and `'block'`
  for captures. Anything else throws with reason `invalid-display`.
- **Failures throw `FontResolutionException`**, with a constant `$e->reason`:
  `invalid-reference`, `too-many-fonts`, `missing-resolver`,
  `resolver-failed`, `missing-font`, `invalid-asset`, `missing-face` or
  `invalid-display`. A live page should show an operator-visible error (route
  sample above). A capture must refuse; never capture with a fallback font.
- **`document.fonts.ready` does not prove a font loaded.** It resolves even
  when a face failed or was never requested. In the capture browser, check that
  the faces for each `cms-font-<manifestSha256>` family report `loaded`, and
  that text actually uses them.
- **Never shrink type to fit one machine's fallback font.** Load the real font
  first, then fit the layout.

## 4. Images

Use real images supplied by the brief. Near-identical generic stock photos read
as "repetitive" and "template-like".

Image nodes name a catalogue handle in `props.asset`. The host maps handles to
measured metadata:

```php
$assets = [
    'brief/hero-workshop' => [
        'src' => '/media/hero-workshop-1600.jpg', 'width' => 1600, 'height' => 1067,
        'variants' => [
            ['src' => '/media/hero-workshop-640.jpg', 'width' => 640, 'height' => 427],
            ['src' => '/media/hero-workshop-1200.jpg', 'width' => 1200, 'height' => 800],
        ],
        'sizes' => '(min-width: 1024px) 50vw, 100vw',
    ],
];
$imageResolver = static fn (string $handle): ?array => $assets[$handle] ?? null;
```

What you get:

- **`width` and `height`** from your metadata, so there is no layout shift.
  Authored props never set dimensions.
- **`srcSet` and `sizes`.** `srcSet` is built from the variants. `sizes` is
  required whenever variants exist.
- **An eager preload hint.** An image with `props.loading: "eager"` gets a
  deduplicated `<link rel="preload" as="image">`. The default is `lazy`.
- **Fail closed.** An unknown handle, a missing resolver or invalid metadata
  emits an `<img>` with no `src`. It never falls back to `props.src`.

The host must do the following:

- **Validate stored documents.** An image node without `asset` keeps the legacy
  `props.src` behaviour, so a closed-catalogue site must reject those:

```php
function cms_image_errors(array $doc, array $catalogue): array
{
    $errors = [];
    foreach ($doc['nodes'] as $id => $node) {
        if (($node['type'] ?? null) !== 'image') {
            continue;
        }
        $props = $node['props'] ?? [];
        if (! is_string($props['asset'] ?? null) || ! isset($catalogue[$props['asset']])) {
            $errors[] = "{$id}: image must use a catalogue asset handle";
        }
        if (! is_string($props['alt'] ?? null) || trim($props['alt']) === '') {
            $errors[] = "{$id}: empty alt; allowed only for a purely decorative image";
        }
    }

    return $errors;
}
```

- **Alt text.** The renderer always emits an `alt` attribute. A missing
  `props.alt` becomes `alt=""`, which marks the image decorative. Every content
  image needs real alt text. For a decorative image, leave alt empty
  deliberately. `props.decorative` is **not** accepted on image nodes: it is
  reserved for `frame`, `shape` and `icon`. A decorative frame or shape may not
  contain images at all.
- **Frames fill consistently.** The base stylesheet makes an image fill its
  node box: `width: 100%; height: 100%; object-fit: cover`. Give every image in
  a row the same `aspectRatio` style (for example `"4 / 3"`) so they crop the
  same way. Letterboxing one card (`fit: "contain"`) and not the next reads as
  broken.
- **Captures.** The package has no render-wide "eager" switch. The capture
  harness must make every image load (for example by scrolling the full page or
  switching `loading` to eager in the capture browser). It must then require
  each `<img>` to be complete with a non-zero `naturalWidth`, and refuse
  otherwise.

## 5. Accessibility

What each node renders as:

| Node | Element | Notes |
|---|---|---|
| `heading` | `h1`-`h6` from `props.level` | missing or invalid level gives `h2`; empty content renders the text "Heading" |
| `text` | `p` | |
| `list` | `ul`, or `ol` when `ordered` is true | |
| `section` | `props.as`: `section`, `header`, `nav`, `main`, `footer`, `article`, `aside`, `div` | containers accept the same list |
| `link`, `button` | `a` **only when `href` is set** | without `href`: a `span` (link) or `div` (button), not focusable |
| `icon` | `role="img"` + `aria-label` when `decorative: false`; else `aria-hidden` | |
| `frame`, `shape` | `aria-hidden="true"` when `props.decorative: true` | |

The checklist the reviewer scores:

- **Exactly one `h1` and no skipped levels.** Styled headings can hide a broken
  outline, so read the DOM, not the screenshot. Count `h1` elements and walk the
  heading levels in document order.
- **Text at least 14px**, including captions, chips and helper text.
- **Contrast.** Grey secondary text must clear WCAG AA (4.5:1, or 3:1 for large
  text) on its actual background. A primary button is white text on
  `--cms-color-primary`, so pick a primary that clears 4.5:1 against white.
- **Tap targets at least 44px on mobile.** The base button (10px vertical
  padding, 1.2 line height, 1px border) is about 41px tall with 16px text, so
  set `paddingY` 12. Make the mobile header CTA a `button` node, not a small
  underlined `link`.
- **Every CTA has an `href`.** Without one it is a non-focusable `div`.
- **Distinct accessible names.** Repeated CTAs need distinct visible labels
  ("Start your free trial with Solo", not three "Start trial"s). There is no
  `aria-label` prop on links or buttons (see Limitations).
- **Landmarks.** Use `props.as` `header`, `nav`, `main` and `footer` on the
  matching sections. Use `props.anchor` (a lowercase slug, rendered as `id`) for
  in-page navigation.
- **Decorative shapes** take `props.decorative: true`. Storage and both
  reducers refuse meaningful content inside them.

## 6. Responsive layout

- **Breakpoints** are `base` (0), `md` (768px) and `lg` (1024px). There is no
  1280 breakpoint: 1280 renders the `lg` styles.
- **No horizontal overflow at 375, 768 or 1280.** Measure
  `document.documentElement.scrollWidth` against the viewport width; don't
  eyeball it. A grid with `columnMin` compiles to
  `minmax(min(<min>, 100%), 1fr)` and will not overflow by itself. Fixed widths,
  long unbroken words and wide `free` layouts will.
- **Leave slack.** Keep about 10% spare on headline measure in fitted layouts.
  Fonts and platforms vary even with a loaded web font.
- **Tablet (768) is the most often cramped.** Prefer `columns` 2 at `md` (a
  third card starts a second row) and 3 at `lg`, or stacked rows, over three
  squeezed columns. Grid nodes have no column-span style. Keep the primary CTA in the
  header from 768 up. Per-breakpoint `hidden` controls what shows where.
- **Align rows.** Cards in a grid row stretch to equal height by default. Keep
  title lengths and heading sizes consistent so their baselines line up.

## 7. Content truth

The package cannot know your brief, so these checks belong to the host and the
author.

- Every fact comes from the brief: prices, plan names, integrations,
  testimonials and stats.
- Invent nothing. No "Most popular" badge, extra integrations, sample totals,
  customer names or dates.
- Include every required fact. A missing price or missing social proof fails
  review exactly like an invented one.
- Placeholder UI panels may carry brief facts as labels, with plain shapes
  (`frame`/`shape`, decorative) where no fact exists.
- Before publishing, find editor defaults left in place: `href: "#"`, empty
  `alt`, and the texts "New heading", "New text" and "Button".

## 8. Design that review rewarded

- **A balanced desktop hero.** The headline sits in about 3 lines, the image
  matches the text block's height (set the image node's `aspectRatio` per
  breakpoint, then measure both boxes at 1280), and there is clear space above
  the CTA row.
- **A designed proof band.** Align the stat and the quote, give the quote real
  typographic treatment, and pair the attribution with an initial mark.
- **A real footer** (`props.as: "footer"`) with links to the page's own
  sections through `props.anchor`.
- **Specific, not template-generic.** Use the brief's own photos, words and
  brand colour.

## 9. Icons

The full Lucide catalogue (Fancy Lucide 0.511.0, 1,803 names and aliases) is
available through `icon` nodes.

- Search with `Agent::icons(string $query = '', int $cursor = 0)`, at most 20
  names per call. Expose it to agents as `cms_icons`.
- Props are literal: `name`, `decorative` (required) and, when
  `decorative: false`, a nonblank `label`. SVG, paths and bindings are refused.
- Sizing: the glyph is one em square. Set `style.fontSize`; colour with
  `style.color`. The wrapper sizing is in `CssEmitter::BASE_CSS`, with no inline
  styles. PHP loads each selected glyph lazily from the package.

## 10. The CLIs

These are parity and diagnostic tools, not a publishing pipeline.

| Command | What it does | Exit codes |
|---|---|---|
| `php bin/emit-css.php <doc.json>` | prints the CSS | 2 on usage or invalid JSON; 3 with the font `reason` on stderr |
| `php bin/emit-css.php --fonts-stdin` | reads `{doc, assets, fontDisplay}` from stdin; `assets` is keyed `"<source>:<family>"` | 3 with the font `reason` on stderr; 2 on bad input |
| `php bin/render-html.php <doc.json> [assets.json]` | prints the body HTML, without CSS; the optional file maps image handles to metadata | 2 on usage, JSON or manifest errors; 3 with the font `reason` on stderr |
| `php bin/page-ops.php` | differential reducer driver (stdin JSON); needs `vendor/autoload.php` | |

`render-html.php`, and `emit-css.php` without `--fonts-stdin`, take no font
resolver. On a document with a structured font they refuse it with exit 3 and
`missing-resolver` on stderr. Use `--fonts-stdin` to check font CSS offline, and
`PageRenderer::render` with a resolver for the real page.

## 11. Verify before publishing

Capture each page at 375, 768 and 1280 in a real browser, then check:

- [ ] `scrollWidth` is no wider than the viewport at all three widths.
- [ ] Exactly one `h1`, with no skipped heading levels (from the DOM).
- [ ] Every content `<img>` has non-empty alt; decorative ones are empty on purpose.
- [ ] Every `<img>` is complete with a non-zero `naturalWidth`.
- [ ] The `cms-font-*` faces report loaded (rendered with `'block'`), not just
      `document.fonts.ready`.
- [ ] Text is at least 14px and secondary text clears AA contrast.
- [ ] Tap targets are at least 44px at 375, and the header CTA is a real button.
- [ ] Every brief fact is present and exact, and nothing is invented.
- [ ] The CSP header is present and the console shows no CSP violations.

Any capture tool works; the package depends on none. If you use prism-browser,
turn on its image and font requirements (`requireImages`, `requireFonts`; check
that tool's own docs) so a broken image or a fallback font refuses instead of
passing.

## 12. Current limitations

- **No `aria-label` on link or button nodes.** The renderer emits no such
  attribute, so the visible text is the accessible name. Write distinct, full
  labels.
- **No `object-position` on image nodes.** Images always crop around the centre
  (`object-fit: cover` by default). `backgroundPosition` styles the node's own
  background, not the image. Workaround: crop the host variants so the subject
  is centred, or choose an `aspectRatio` close to the source's.
- **Code blocks are 13px.** `CssEmitter::BASE_CSS` sets `font-size: 13px` on
  the `pre` inside a `code` node. A node's `fontSize` styles the wrapper, not
  the `pre`. Workaround: add a host rule such as `[data-cms] > pre { font-size: 14px; }`
  (any selector with specificity beats the base `:where()` rule).
- **No routes, storage or `<head>` output.** The service provider binds
  nothing; see section 1.
- **CLIs have no font resolver.** See section 10.
