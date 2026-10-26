<?php

declare(strict_types=1);

/** Static HTML CLI for the dual-renderer parity gate. No styles or added newline. */
require __DIR__.'/../src/Render/CssEmitter.php';
require __DIR__.'/../src/Render/JsValue.php';
require __DIR__.'/../src/Render/ImageAssets.php';
require __DIR__.'/../src/Render/PageRenderer.php';
require __DIR__.'/../src/Document/Ordering.php';
require __DIR__.'/../src/Document/Icons.php';
require __DIR__.'/../src/Document/Decorative.php';
require __DIR__.'/../src/Document/PageJson.php';

use FancyCms\Document\PageJson;
use FancyCms\Render\PageRenderer;

$path = $argv[1] ?? null;
if ($path === null || ! is_file($path)) {
    fwrite(STDERR, "usage: php bin/render-html.php <doc.json> [host-assets.json]\n");
    exit(2);
}
try {
    $doc = PageJson::decodeDocument((string) file_get_contents($path));
} catch (JsonException|InvalidArgumentException) {
    fwrite(STDERR, "invalid JSON\n");
    exit(2);
}
$manifest = [];
if (isset($argv[2])) {
    if (! is_file($argv[2])) {
        fwrite(STDERR, "missing asset manifest\n");
        exit(2);
    }
    try {
        $manifest = json_decode((string) file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($manifest)) {
            throw new InvalidArgumentException('asset manifest must be an object');
        }
    } catch (JsonException|InvalidArgumentException) {
        fwrite(STDERR, "invalid asset manifest\n");
        exit(2);
    }
}
echo PageRenderer::render($doc, [], static fn (string $handle): mixed => $manifest[$handle] ?? null)['html'];
