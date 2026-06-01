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

use FancyCms\Render\CssEmitter;

$path = $argv[1] ?? null;
if ($path === null || ! is_file($path)) {
    fwrite(STDERR, "usage: php bin/emit-css.php <doc.json>\n");
    exit(2);
}

$doc = json_decode((string) file_get_contents($path), true);
if (! is_array($doc)) {
    fwrite(STDERR, "invalid JSON\n");
    exit(2);
}

echo CssEmitter::emit($doc);
