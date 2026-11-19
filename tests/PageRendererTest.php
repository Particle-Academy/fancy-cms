<?php

declare(strict_types=1);

namespace FancyCms\Tests\PageRendererTest;

use FancyCms\Render\PageRenderer;

/**
 * @param  array<string,mixed>  $extra
 * @return array<string,mixed>
 */
function node(string $id, ?string $parent, string $order, array $extra = []): array {
    return array_merge([
        'id' => $id,
        'type' => 'text',
        'parent' => $parent,
        'order' => $order,
        'props' => ['content' => strtoupper($id)],
        'style' => [],
    ], $extra);
}

/**
 * @param  list<array<string,mixed>>  $nodes
 * @return array<string,mixed>
 */
function doc(array $nodes, ?array $sections = null): array {
    $map = [];
    foreach ($nodes as $n) {
        $map[(string) $n['id']] = $n;
    }
    $doc = ['id' => 'p1', 'seq' => 0, 'nodes' => $map, 'breakpoints' => ['base']];

    if ($sections !== null) {
        $doc['sections'] = $sections;
    }

    return $doc;
}

/** Strip tags so assertions read as the user-visible order. */
function text(string $html): string {
    return preg_replace('/\s+/', '', strip_tags($html)) ?? '';
}

test('renders roots by order key in the current shape', function () {
    // The post-migration document: no `sections`, roots ordered like any
    // other sibling group.
    $doc = doc([
        node('b', null, 'a1'),
        node('a', null, 'a0'),
        node('c', null, 'a2'),
    ]);

    $this->assertSame('ABC', text(PageRenderer::render($doc)['html']));
});

test('renders a legacy document from its sections array', function () {
    $doc = doc([
        node('a', null, 'a0'),
        node('b', null, 'a1'),
    ], sections: ['a', 'b']);

    $this->assertSame('AB', text(PageRenderer::render($doc)['html']));
});

test('legacy sections beat stale order keys', function () {
    // The case the whole dual-shape read exists for. The old JS
    // `reorder_sections` op permuted the array and left the `order` keys
    // alone, so a page dragged into c, a, b is stored with keys that still
    // read a, b, c. Trusting the keys would silently un-reorder a live page.
    $doc = doc([
        node('a', null, 'a0'),
        node('b', null, 'a1'),
        node('c', null, 'a2'),
    ], sections: ['c', 'a', 'b']);

    $this->assertSame('CAB', text(PageRenderer::render($doc)['html']));
});

test('skips a dangling section id', function () {
    $doc = doc([
        node('a', null, 'a0'),
    ], sections: ['a', 'ghost']);

    $html = PageRenderer::render($doc)['html'];

    $this->assertSame('A', text($html));
    $this->assertStringNotContainsString('ghost', $html);
});

test('an empty sections array is still the legacy shape', function () {
    // `sections: []` means "this document has no sections", NOT "this
    // document is in the new shape" — falling through to the order keys
    // would resurrect roots the author had removed.
    $doc = doc([
        node('a', null, 'a0'),
    ], sections: []);

    $this->assertSame('', text(PageRenderer::render($doc)['html']));
});

test('orders nested children by order key', function () {
    $doc = doc([
        node('s', null, 'a0', ['type' => 'stack']),
        node('y', 's', 'a1'),
        node('x', 's', 'a0'),
    ]);

    $this->assertSame('XY', text(PageRenderer::render($doc)['html']));
});

test('emits a stable handle for every node', function () {
    $doc = doc([node('hero', null, 'a0')]);

    $this->assertStringContainsString('data-cms="hero"', PageRenderer::render($doc)['html']);
});

test('renders an island as a sized placeholder not its children', function () {
    $doc = doc([
        node('w', null, 'a0', ['type' => 'fancy-widget', 'island' => true]),
        node('inner', 'w', 'a0'),
    ]);

    $html = PageRenderer::render($doc)['html'];

    $this->assertStringContainsString('data-cms-island="fancy-widget"', $html);
    $this->assertStringNotContainsString('INNER', $html);
});

test('escapes author content', function () {
    $doc = doc([
        node('a', null, 'a0', ['props' => ['content' => '<script>alert(1)</script>']]),
    ]);

    $html = PageRenderer::render($doc)['html'];

    $this->assertStringNotContainsString('<script>', $html);
    $this->assertStringContainsString('&lt;script&gt;', $html);
});

test('escapes a node id used as a handle', function () {
    $doc = doc([node('a"><b', null, 'a0')]);

    $this->assertStringNotContainsString('"><b"', PageRenderer::render($doc)['html']);
});

test('renders an empty document', function () {
    $out = PageRenderer::render(doc([]));

    $this->assertSame('', $out['html']);
    $this->assertArrayHasKey('css', $out);
});
