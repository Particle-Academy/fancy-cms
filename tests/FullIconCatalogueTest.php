<?php

declare(strict_types=1);

namespace FancyCms\Tests\FullIconCatalogueTest;

use FancyCms\Agent;
use FancyCms\Document\Icons;
use FancyCms\Document\PageDoc;
use FancyCms\Render\PageRenderer;
use FancyCms\Tests\Support\PageFixtures as F;
use InvalidArgumentException;

test('every published lucide name and alias is closed valid and renderable', function () {
    $names = Icons::names();
    $this->assertCount(1803, $names);
    $sorted = $names;
    sort($sorted, SORT_STRING);
    $this->assertSame($sorted, $names);
    $this->assertSame(Icons::glyph('house'), Icons::glyph('home'));
    foreach ($names as $name) {
        $node = array_replace(F::node('icon'), ['type' => 'icon', 'props' => ['name' => $name, 'decorative' => true]]);
        $doc = array_replace(F::doc(), ['nodes' => ['icon' => $node]]);
        $this->assertSame([], PageDoc::validate($doc), $name);
        $this->assertStringContainsString('<svg', PageRenderer::render($doc)['html'], $name);
    }
    $this->assertNull(Icons::glyph('../house'));
    $this->assertNull(Icons::glyph('constructor'));
});

test('search is bounded paginatable and does not expand describe', function () {
    $first = Agent::icons('FILE text', 0);
    $this->assertSame('lucide-react@0.511.0', $first['source']);
    $this->assertSame(['file-text'], $first['names']);
    $this->assertNull($first['nextCursor']);
    $seen = [];
    $cursor = 0;
    do {
        $page = Agent::icons('', $cursor);
        $this->assertLessThanOrEqual(20, count($page['names']));
        array_push($seen, ...$page['names']);
        $cursor = $page['nextCursor'];
    } while ($cursor !== null);
    $this->assertSame(Icons::names(), $seen);
    $this->assertSame([], Agent::icons('no-such-icon-name', 0)['names']);
    $this->assertSame([], Agent::icons('', 100000)['names']);
    $this->assertIsString(Agent::describe()['elements']['icon']['name']);
    $this->assertStringNotContainsString('a-arrow-down', json_encode(Agent::describe(), JSON_THROW_ON_ERROR));
});

test('search refuses markup non ascii excessive queries and negative cursors', function () {
    foreach ([['<svg>', 0], ['é', 0], ["file\n", 0], [str_repeat('a', 129), 0], ['file', -1]] as [$query, $cursor]) {
        try {
            Agent::icons($query, $cursor);
            $this->fail('Invalid search was accepted.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Invalid icon search.', $e->getMessage());
        }
    }
});

// Needs a process where Icons has never been touched: the rest of the suite
// fills its static caches, so the check runs in a fresh PHP process.
test('loading the class does not read the index or glyphs', function () {
    $script = __DIR__.'/Support/icons-lazy-load.php';
    $output = shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script));
    $this->assertIsString($output);
    $observed = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    $this->assertNull($observed['indexBefore']);
    $this->assertSame([], $observed['glyphsBefore']);
    $this->assertTrue($observed['indexAfterIsArray']);
    $this->assertSame(['check'], $observed['glyphKeysAfter']);
});
