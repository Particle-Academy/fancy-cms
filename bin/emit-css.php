<?php

declare(strict_types=1);

/**
 * Standalone CSS emitter CLI for the dual-emitter parity harness. No Composer
 * autoload required — it requires CssEmitter directly so it runs anywhere PHP
 * is available.
 *
 * Usage: php bin/emit-css.php <path-to-doc.json>
 */

require __DIR__.'/../src/Render/CssEmitter.php';
require __DIR__.'/../src/Render/JsValue.php';
require __DIR__.'/../src/Document/Ordering.php';
require __DIR__.'/../src/Document/PageJson.php';

use FancyCms\Document\PageJson;
use FancyCms\Render\CssEmitter;

$path = $argv[1] ?? null;
if ($path === null || ! is_file($path)) {
    fwrite(STDERR, "usage: php bin/emit-css.php <doc.json>\n");
    exit(2);
}

try {
    $doc = PageJson::decodeDocument((string) file_get_contents($path));
} catch (JsonException|InvalidArgumentException) {
    fwrite(STDERR, "invalid JSON\n");
    exit(2);
}

echo CssEmitter::emit($doc);
