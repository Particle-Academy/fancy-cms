<?php

declare(strict_types=1);

namespace FancyCms\Tests\Renderer09Test;

use FancyCms\Render\CssEmitter;
use FancyCms\Render\PageRenderer;
use FancyCms\Tests\Support\PageFixtures as F;

function render(string $type, array $props, array $extra = []): string {
    $doc = F::doc();
    $doc['nodes'] = ['a' => array_replace(F::node('a'), ['type' => $type, 'props' => $props], $extra)];

    return PageRenderer::render($doc)['html'];
}

test('base css is present without node declarations and absent for an empty page', function () {
    $doc = F::doc();
    $doc['theme'] = [];
    $this->assertSame(CssEmitter::BASE_CSS."\n", CssEmitter::emit($doc));
    $doc['nodes'] = [];
    $this->assertSame('', CssEmitter::emit($doc));
    $doc['theme'] = ['color' => ['primary' => '#123']];
    $this->assertStringStartsWith(CssEmitter::BASE_CSS."\n\n:root", CssEmitter::emit($doc));
});

test('buttons and callouts have one element and allowlisted variants', function () {
    $this->assertSame('<a data-cms="a" class="" id="join" href="https://example.test" rel="noopener noreferrer" data-cms-button="outline">Join</a>', render('button', ['label' => 'Join', 'href' => 'https://example.test', 'variant' => 'outline', 'anchor' => 'join'], ['className' => '']));
    $this->assertSame('<div data-cms="a" data-cms-button="primary">Fallback</div>', render('button', ['content' => 'Fallback', 'variant' => 'x" onclick="x']));
    $this->assertSame('<div data-cms="a" data-cms-callout="info">Callout</div>', render('callout', ['variant' => 'evil']));
    $this->assertSame('<div data-cms="a" data-cms-callout="success">Saved</div>', render('callout', ['content' => 'Saved', 'variant' => 'success']));
});

test('native leaves use the react static markup and escaping', function () {
    $this->assertSame('<div data-cms="a"><hr/></div>', render('divider', []));
    $this->assertSame('<div data-cms="a"><pre><code>&lt;x&gt; &amp; &quot;q&quot; &#x27;s&#x27;</code></pre></div>', render('code', ['content' => '<x> & "q" \'s\'']));
    $this->assertSame('<div data-cms="a"><img alt="" loading="lazy"/></div>', render('image', []));
    $this->assertSame('<link rel="preload" as="image" href="/a.png"/><div data-cms="a"><img src="/a.png" alt="A" loading="eager" data-cms-fit="contain"/></div>', render('image', ['src' => '/a.png', 'alt' => 'A', 'fit' => 'contain', 'loading' => 'eager']));
});

test('known island keeps its semantics and unknown island is a placeholder', function () {
    $this->assertSame('<div data-cms="a" data-cms-island="button" data-cms-button="primary">Go</div>', render('button', ['label' => 'Go'], ['island' => true]));
    $this->assertSame('<div data-cms="a" data-cms-island="widget" class="widget" id="demo"></div>', render('widget', ['anchor' => 'demo'], ['island' => true, 'className' => 'widget']));
});

test('legacy nodes with missing or odd style do not crash rendering', function () {
    $doc = F::doc();
    $node = F::node('a');
    unset($node['props']);
    $node['style'] = ['base' => 'odd'];
    $doc['nodes'] = ['a' => $node];
    $doc['theme'] = [];
    $this->assertSame('<p data-cms="a"></p>', PageRenderer::render($doc)['html']);
    $this->assertSame(CssEmitter::BASE_CSS."\n", CssEmitter::emit($doc));
});

test('eager image resource hints are deduplicated and do not leak across renders', function () {
    $doc = F::doc();
    $doc['nodes'] = [];
    foreach (['a', 'b'] as $id) {
        $node = F::node($id);
        $node['type'] = 'image';
        $node['props'] = ['src' => '/shared.png?a=1&b=2', 'loading' => 'eager'];
        $doc['nodes'][$id] = $node;
    }
    $html = PageRenderer::render($doc)['html'];
    $this->assertStringStartsWith('<link rel="preload" as="image" href="/shared.png?a=1&amp;b=2"/>', $html);
    $this->assertSame(1, substr_count($html, '<link '));
    $this->assertSame(2, substr_count($html, '<img '));
    $this->assertStringNotContainsString('<link ', render('image', ['src' => '/lazy.png']));
    $this->assertStringNotContainsString('<link ', render('image', ['src' => 'DATA:image/png;base64,AA==', 'loading' => 'eager']));
});
