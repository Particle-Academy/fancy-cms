<?php

declare(strict_types=1);

namespace FancyCms\Tests;

use FancyCms\Render\CssEmitter;
use FancyCms\Render\PageRenderer;
use FancyCms\Tests\Support\PageFixtures as F;
use PHPUnit\Framework\TestCase;

final class Renderer09Test extends TestCase
{
    private function render(string $type, array $props, array $extra = []): string
    {
        $doc = F::doc();
        $doc['nodes'] = ['a' => array_replace(F::node('a'), ['type' => $type, 'props' => $props], $extra)];

        return PageRenderer::render($doc)['html'];
    }

    public function test_base_css_is_present_without_node_declarations_and_absent_for_an_empty_page(): void
    {
        $doc = F::doc();
        $doc['theme'] = [];
        self::assertSame(CssEmitter::BASE_CSS."\n", CssEmitter::emit($doc));
        $doc['nodes'] = [];
        self::assertSame('', CssEmitter::emit($doc));
        $doc['theme'] = ['color' => ['primary' => '#123']];
        self::assertStringStartsWith(CssEmitter::BASE_CSS."\n\n:root", CssEmitter::emit($doc));
    }

    public function test_buttons_and_callouts_have_one_element_and_allowlisted_variants(): void
    {
        self::assertSame('<a data-cms="a" class="" id="join" href="https://example.test" rel="noopener noreferrer" data-cms-button="outline">Join</a>', $this->render('button', ['label' => 'Join', 'href' => 'https://example.test', 'variant' => 'outline', 'anchor' => 'join'], ['className' => '']));
        self::assertSame('<div data-cms="a" data-cms-button="primary">Fallback</div>', $this->render('button', ['content' => 'Fallback', 'variant' => 'x" onclick="x']));
        self::assertSame('<div data-cms="a" data-cms-callout="info">Callout</div>', $this->render('callout', ['variant' => 'evil']));
        self::assertSame('<div data-cms="a" data-cms-callout="success">Saved</div>', $this->render('callout', ['content' => 'Saved', 'variant' => 'success']));
    }

    public function test_native_leaves_use_the_react_static_markup_and_escaping(): void
    {
        self::assertSame('<div data-cms="a"><hr/></div>', $this->render('divider', []));
        self::assertSame('<div data-cms="a"><pre><code>&lt;x&gt; &amp; &quot;q&quot; &#x27;s&#x27;</code></pre></div>', $this->render('code', ['content' => '<x> & "q" \'s\'']));
        self::assertSame('<div data-cms="a"><img alt="" loading="lazy"/></div>', $this->render('image', []));
        self::assertSame('<link rel="preload" as="image" href="/a.png"/><div data-cms="a"><img src="/a.png" alt="A" loading="eager" data-cms-fit="contain"/></div>', $this->render('image', ['src' => '/a.png', 'alt' => 'A', 'fit' => 'contain', 'loading' => 'eager']));
    }

    public function test_known_island_keeps_its_semantics_and_unknown_island_is_a_placeholder(): void
    {
        self::assertSame('<div data-cms="a" data-cms-island="button" data-cms-button="primary">Go</div>', $this->render('button', ['label' => 'Go'], ['island' => true]));
        self::assertSame('<div data-cms="a" data-cms-island="widget" class="widget" id="demo"></div>', $this->render('widget', ['anchor' => 'demo'], ['island' => true, 'className' => 'widget']));
    }

    public function test_legacy_nodes_with_missing_or_odd_style_do_not_crash_rendering(): void
    {
        $doc = F::doc();
        $node = F::node('a');
        unset($node['props']);
        $node['style'] = ['base' => 'odd'];
        $doc['nodes'] = ['a' => $node];
        $doc['theme'] = [];
        self::assertSame('<p data-cms="a"></p>', PageRenderer::render($doc)['html']);
        self::assertSame(CssEmitter::BASE_CSS."\n", CssEmitter::emit($doc));
    }

    public function test_eager_image_resource_hints_are_deduplicated_and_do_not_leak_across_renders(): void
    {
        $doc = F::doc();
        $doc['nodes'] = [];
        foreach (['a', 'b'] as $id) {
            $node = F::node($id);
            $node['type'] = 'image';
            $node['props'] = ['src' => '/shared.png?a=1&b=2', 'loading' => 'eager'];
            $doc['nodes'][$id] = $node;
        }
        $html = PageRenderer::render($doc)['html'];
        self::assertStringStartsWith('<link rel="preload" as="image" href="/shared.png?a=1&amp;b=2"/>', $html);
        self::assertSame(1, substr_count($html, '<link '));
        self::assertSame(2, substr_count($html, '<img '));
        self::assertStringNotContainsString('<link ', $this->render('image', ['src' => '/lazy.png']));
        self::assertStringNotContainsString('<link ', $this->render('image', ['src' => 'DATA:image/png;base64,AA==', 'loading' => 'eager']));
    }
}
