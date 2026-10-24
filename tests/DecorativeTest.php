<?php

declare(strict_types=1);

namespace FancyCms\Tests;

use FancyCms\Document\Decorative;
use FancyCms\Document\PageDoc;
use FancyCms\Document\PageOp;
use FancyCms\Document\Reducer;
use FancyCms\Render\PageRenderer;
use FancyCms\Tests\Support\PageFixtures as F;
use PHPUnit\Framework\TestCase;

final class DecorativeTest extends TestCase
{
    private static function node(string $id, string $type = 'frame', ?string $parent = null, array $props = []): array
    {
        return array_replace(F::node($id, $parent), ['type' => $type, 'props' => $props]);
    }

    private static function doc(array $nodes): array
    {
        return array_replace(F::doc(), ['nodes' => array_column($nodes, null, 'id')]);
    }

    public function test_only_literal_boolean_frame_and_shape_flags_are_valid(): void
    {
        foreach (['frame', 'shape'] as $type) {
            foreach ([true, false] as $flag) {
                $doc = self::doc([self::node('art', $type, null, ['decorative' => $flag])]);
                self::assertSame([], PageDoc::validate($doc));
                self::assertSame($flag, str_contains(PageRenderer::render($doc)['html'], 'aria-hidden="true"'));
            }
            foreach (['true', null, 1, ['$bind' => 'flag']] as $flag) {
                $node = self::node('art', $type, null, ['decorative' => $flag]);
                self::assertNotSame([], PageOp::validate(['t' => 'insert_node', 'node' => $node]));
                self::assertNotSame([], PageDoc::validate(self::doc([$node])));
                self::assertSame(F::doc(), Reducer::reduce(F::doc(), ['t' => 'insert_node', 'node' => $node]));
                $before = self::doc([self::node('art', $type)]);
                $invalid = [];
                self::assertSame($before, Reducer::reduce($before, ['t' => 'set_node_props', 'id' => 'art', 'patch' => ['decorative' => $flag]], ['onInvalid' => static function ($_, $reason) use (&$invalid): void {
                    $invalid[] = $reason;
                }]));
                self::assertSame(['invalid decorative props'], $invalid);
            }
        }
        $node = self::node('link', 'link', null, ['content' => 'Pricing', 'href' => '#pricing']);
        $doc = self::doc([$node]);
        self::assertSame($doc, Reducer::reduce($doc, ['t' => 'set_node_props', 'id' => 'link', 'patch' => ['decorative' => true]]));
        self::assertNotSame([], PageDoc::validate(self::doc([self::node('link', 'link', null, ['decorative' => false])])));
    }

    public function test_meaningful_nodes_cannot_enter_or_be_wrapped_by_a_decorative_ancestor(): void
    {
        foreach (['text', 'heading', 'richtext', 'link', 'button', 'list', 'image', 'callout', 'code', 'card', 'stack', 'repeater', 'host-widget'] as $type) {
            $root = self::node('art', 'frame', null, ['decorative' => true]);
            $nested = self::node('nested', 'shape', 'art');
            $child = self::node('content', $type, 'nested', ['content' => 'Visible content', 'alt' => 'Meaningful image']);
            $before = self::doc([$root, $nested]);
            self::assertSame($before, Reducer::reduce($before, ['t' => 'insert_node', 'node' => $child]), $type);
            $before['nodes']['content'] = array_replace($child, ['parent' => null]);
            self::assertSame($before, Reducer::reduce($before, ['t' => 'move_node', 'id' => 'content', 'parent' => 'nested', 'order' => 'V']), $type);
            $raw = self::doc([$root, $nested, $child]);
            self::assertNotSame([], PageDoc::validate($raw, [...PageDoc::NODE_TYPES, 'host-widget']), $type);
            $raw['nodes']['art']['props']['decorative'] = false;
            self::assertSame($raw, Reducer::reduce($raw, ['t' => 'set_node_props', 'id' => 'art', 'patch' => ['decorative' => true]]), $type);
        }
    }

    public function test_subtree_moves_and_nested_accessibility_changes_are_checked(): void
    {
        $doc = self::doc([self::node('art', 'frame', null, ['decorative' => true]), self::node('group'), self::node('button', 'button', 'group', ['label' => 'Trial', 'href' => '/signup'])]);
        self::assertSame($doc, Reducer::reduce($doc, ['t' => 'move_node', 'id' => 'group', 'parent' => 'art', 'order' => 'Z']));
        $doc['nodes']['glyph'] = self::node('glyph', 'icon', 'art', ['name' => 'check', 'decorative' => true, 'label' => 'Check']);
        self::assertSame($doc, Reducer::reduce($doc, ['t' => 'set_node_props', 'id' => 'glyph', 'patch' => ['decorative' => false]]));
        $doc['nodes']['glyph']['props']['decorative'] = false;
        self::assertSame('decorative subtree contains meaningful content', Decorative::error($doc['nodes']));
    }

    public function test_safe_compositions_render_hidden_and_malformed_stored_content_fails_visible(): void
    {
        $doc = self::doc([self::node('art', 'frame', null, ['decorative' => true]), self::node('shape', 'shape', 'art')]);
        self::assertSame([], PageDoc::validate($doc));
        self::assertStringContainsString('data-cms="art" aria-hidden="true"', PageRenderer::render($doc)['html']);
        $doc['nodes']['cta'] = self::node('cta', 'button', 'shape', ['label' => 'Start trial', 'href' => '/signup']);
        $html = PageRenderer::render($doc)['html'];
        self::assertStringContainsString('Start trial', $html);
        self::assertStringNotContainsString('aria-hidden', $html);
        unset($doc['nodes']['cta']);
        self::assertStringContainsString('aria-hidden="true"', PageRenderer::render($doc)['html']);
    }

    public function test_adding_the_flag_has_exact_wire_undo_for_the_whole_subtree(): void
    {
        $doc = self::doc([self::node('art'), self::node('child', 'shape', 'art')]);
        $op = ['t' => 'set_node_props', 'id' => 'art', 'patch' => ['decorative' => true]];
        $inverse = json_decode(json_encode(Reducer::invert($doc, $op)), true);
        $next = Reducer::reduce($doc, $op);
        self::assertTrue($next['nodes']['art']['props']['decorative']);
        self::assertSame($doc['nodes'], Reducer::reduceAll($next, $inverse)['nodes']);
    }

    public function test_decorative_artwork_cannot_hide_actionable_native_shapes(): void
    {
        $action = ['on' => 'click', 'do' => ['kind' => 'navigate', 'href' => '/signup']];
        $before = self::doc([self::node('art', 'frame', null, ['decorative' => true]), self::node('shape', 'shape', 'art')]);
        self::assertSame($before, Reducer::reduce($before, ['t' => 'set_action', 'id' => 'shape', 'index' => 0, 'action' => $action]));
        $active = self::node('active', 'shape', 'art') + ['actions' => [$action]];
        self::assertSame($before, Reducer::reduce($before, ['t' => 'insert_node', 'node' => $active]));
        $raw = self::doc([...array_values($before['nodes']), $active]);
        self::assertNotSame([], PageDoc::validate($raw));
        self::assertStringNotContainsString('aria-hidden', PageRenderer::render($raw)['html']);
        $raw['nodes']['active']['parent'] = null;
        self::assertSame($raw, Reducer::reduce($raw, ['t' => 'move_node', 'id' => 'active', 'parent' => 'art', 'order' => 'Z']));
    }

    public function test_deep_decorative_validation_is_iterative_and_does_not_hide_malformed_props(): void
    {
        $nodes = [];
        for ($i = 0; $i < 1500; $i++) {
            $nodes[] = self::node('n'.$i, 'frame', $i === 0 ? null : 'n'.($i - 1), ['decorative' => true]);
        }
        $doc = self::doc($nodes);
        self::assertSame([], PageDoc::validate($doc));
        self::assertSame($doc, Reducer::reduce($doc, ['t' => 'insert_node', 'node' => self::node('cta', 'button', 'n1499', ['label' => 'Trial'])]));
        foreach ([null, false, (object) ['decorative' => true]] as $props) {
            $raw = self::doc([self::node('art')]);
            $raw['nodes']['art']['props'] = $props;
            self::assertStringNotContainsString('aria-hidden', PageRenderer::render($raw)['html']);
        }
    }
}
