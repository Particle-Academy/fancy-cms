<?php

declare(strict_types=1);

/** Static HTML CLI for the dual-renderer parity gate. No styles or added newline. */
require __DIR__.'/../src/Render/CssEmitter.php';
require __DIR__.'/../src/Render/JsValue.php';
require __DIR__.'/../src/Render/PageRenderer.php';
require __DIR__.'/../src/Document/Ordering.php';
require __DIR__.'/../src/Document/PageJson.php';

use FancyCms\Document\PageJson;
use FancyCms\Render\PageRenderer;

$path = $argv[1] ?? null;
if ($path === null || ! is_file($path)) {
    fwrite(STDERR, "usage: php bin/render-html.php <doc.json>\n");
    exit(2);
}
try {
    $doc = PageJson::decodeDocument((string) file_get_contents($path));
} catch (JsonException|InvalidArgumentException) {
    fwrite(STDERR, "invalid JSON\n");
    exit(2);
}
echo PageRenderer::render($doc)['html'];
