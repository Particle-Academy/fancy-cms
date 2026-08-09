<?php

declare(strict_types=1);

namespace FancyCms\Tests;

use FancyCms\Render\PageRenderer;
use PHPUnit\Framework\TestCase;

final class PageRendererTest extends TestCase
{
    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function node(string $id, ?string $parent, string $order, array $extra = []): array
    {
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
    private function doc(array $nodes, ?array $sections = null): array
    {
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
    private function text(string $html): string
    {
        return preg_replace('/\s+/', '', strip_tags($html)) ?? '';
    }

    public function test_renders_roots_by_order_key_in_the_current_shape(): void
    {
        // The post-migration document: no `sections`, roots ordered like any
        // other sibling group.
        $doc = $this->doc([
            $this->node('b', null, 'a1'),
            $this->node('a', null, 'a0'),
            $this->node('c', null, 'a2'),
        ]);

        $this->assertSame('ABC', $this->text(PageRenderer::render($doc)['html']));
    }

    public function test_renders_a_legacy_document_from_its_sections_array(): void
    {
        $doc = $this->doc([
            $this->node('a', null, 'a0'),
            $this->node('b', null, 'a1'),
        ], sections: ['a', 'b']);

        $this->assertSame('AB', $this->text(PageRenderer::render($doc)['html']));
    }

    public function test_legacy_sections_beat_stale_order_keys(): void
    {
        // The case the whole dual-shape read exists for. The old JS
        // `reorder_sections` op permuted the array and left the `order` keys
        // alone, so a page dragged into c, a, b is stored with keys that still
        // read a, b, c. Trusting the keys would silently un-reorder a live page.
        $doc = $this->doc([
            $this->node('a', null, 'a0'),
            $this->node('b', null, 'a1'),
            $this->node('c', null, 'a2'),
        ], sections: ['c', 'a', 'b']);

        $this->assertSame('CAB', $this->text(PageRenderer::render($doc)['html']));
    }

    public function test_skips_a_dangling_section_id(): void
    {
        $doc = $this->doc([
            $this->node('a', null, 'a0'),
        ], sections: ['a', 'ghost']);

        $html = PageRenderer::render($doc)['html'];

        $this->assertSame('A', $this->text($html));
        $this->assertStringNotContainsString('ghost', $html);
    }

    public function test_an_empty_sections_array_is_still_the_legacy_shape(): void
    {
        // `sections: []` means "this document has no sections", NOT "this
        // document is in the new shape" — falling through to the order keys
        // would resurrect roots the author had removed.
        $doc = $this->doc([
            $this->node('a', null, 'a0'),
        ], sections: []);

        $this->assertSame('', $this->text(PageRenderer::render($doc)['html']));
    }

    public function test_orders_nested_children_by_order_key(): void
    {
        $doc = $this->doc([
            $this->node('s', null, 'a0', ['type' => 'stack']),
            $this->node('y', 's', 'a1'),
            $this->node('x', 's', 'a0'),
        ]);

        $this->assertSame('XY', $this->text(PageRenderer::render($doc)['html']));
    }

    public function test_emits_a_stable_handle_for_every_node(): void
    {
        $doc = $this->doc([$this->node('hero', null, 'a0')]);

        $this->assertStringContainsString('data-cms="hero"', PageRenderer::render($doc)['html']);
    }

    public function test_renders_an_island_as_a_sized_placeholder_not_its_children(): void
    {
        $doc = $this->doc([
            $this->node('w', null, 'a0', ['type' => 'fancy-widget', 'island' => true]),
            $this->node('inner', 'w', 'a0'),
        ]);

        $html = PageRenderer::render($doc)['html'];

        $this->assertStringContainsString('data-cms-island="fancy-widget"', $html);
        $this->assertStringNotContainsString('INNER', $html);
    }

    public function test_escapes_author_content(): void
    {
        $doc = $this->doc([
            $this->node('a', null, 'a0', ['props' => ['content' => '<script>alert(1)</script>']]),
        ]);

        $html = PageRenderer::render($doc)['html'];

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_escapes_a_node_id_used_as_a_handle(): void
    {
        $doc = $this->doc([$this->node('a"><b', null, 'a0')]);

        $this->assertStringNotContainsString('"><b"', PageRenderer::render($doc)['html']);
    }

    public function test_renders_an_empty_document(): void
    {
        $out = PageRenderer::render($this->doc([]));

        $this->assertSame('', $out['html']);
        $this->assertArrayHasKey('css', $out);
    }
}
