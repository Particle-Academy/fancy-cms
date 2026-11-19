<?php

declare(strict_types=1);

/*
 * Run in a fresh PHP process by FullIconCatalogueTest: proves that loading the
 * Icons class reads neither the index nor any glyph until one is asked for.
 * Prints what it observed as JSON; the test asserts on it.
 */

require dirname(__DIR__, 2).'/vendor/autoload.php';

use FancyCms\Document\Icons;

$reflection = new ReflectionClass(Icons::class);
$observed = [
    'indexBefore' => $reflection->getStaticPropertyValue('index'),
    'glyphsBefore' => $reflection->getStaticPropertyValue('glyphs'),
];
Icons::glyph('check');
$observed['indexAfterIsArray'] = is_array($reflection->getStaticPropertyValue('index'));
$observed['glyphKeysAfter'] = array_keys($reflection->getStaticPropertyValue('glyphs'));

echo json_encode($observed, JSON_THROW_ON_ERROR);
