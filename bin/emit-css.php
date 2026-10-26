<?php

declare(strict_types=1);

/**
 * Standalone CSS emitter CLI for the dual-emitter parity harness. No Composer
 * autoload required — it requires CssEmitter directly so it runs anywhere PHP
 * is available.
 *
 * Usage: php bin/emit-css.php <doc.json>
 * Or --fonts-stdin accepts {doc, assets, fontDisplay} for offline parity cases.
 */

require __DIR__.'/../src/Render/CssEmitter.php';
require __DIR__.'/../src/Render/JsValue.php';
require __DIR__.'/../src/Render/FontAssets.php';
require __DIR__.'/../src/Exceptions/FontResolutionException.php';
require __DIR__.'/../src/Document/Ordering.php';
require __DIR__.'/../src/Document/PageJson.php';

use FancyCms\Document\PageJson;
use FancyCms\Exceptions\FontResolutionException;
use FancyCms\Render\CssEmitter;

$path = $argv[1] ?? null;
if ($path === '--fonts-stdin') {
    try {
        $input = json_decode(stream_get_contents(STDIN), false, 128, JSON_THROW_ON_ERROR);
        $doc = PageJson::decodeDocument(json_encode($input->doc, JSON_THROW_ON_ERROR));
        $assets = (array) ($input->assets ?? new stdClass);
        echo CssEmitter::emit($doc, static fn ($ref) => $assets[$ref['source'].':'.$ref['family']] ?? null, $input->fontDisplay ?? 'swap');
        exit(0);
    } catch (FontResolutionException $e) {
        fwrite(STDERR, $e->reason."\n");
        exit(3);
    } catch (Throwable) {
        fwrite(STDERR, "invalid font parity input\n");
        exit(2);
    }
}
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
