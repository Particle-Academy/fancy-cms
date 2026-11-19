<?php

declare(strict_types=1);

namespace FancyCms\Tests\DecorativeTest;

use FancyCms\Document\Decorative;
use FancyCms\Document\PageDoc;
use FancyCms\Document\PageOp;
use FancyCms\Document\Reducer;
use FancyCms\Render\PageRenderer;
use FancyCms\Tests\Support\PageFixtures as F;

function node(string $id, string $type = 'frame', ?string $parent = null, array $props = []): array {
    return array_replace(F::node($id, $parent), ['type' => $type, 'props' => $props]);
}

function doc(array $nodes): array {
    return array_replace(F::doc(), ['nodes' => array_column($nodes, null, 'id')]);
}

test('only literal boolean frame and shape flags are valid', function () {
    foreach (['frame', 'shape'] as $type) {
        foreach ([true, false] as $flag) {
            $doc = doc([node('art', $type, null, ['decorative' => $flag])]);
            $this->assertSame([], PageDoc::validate($doc));
            $this->assertSame($flag, str_contains(PageRenderer::render($doc)['html'], 'aria-hidden="true"'));
        }
        foreach (['true', null, 1, ['$bind' => 'flag']] as $flag) {
            $node = node('art', $type, null, ['decorative' => $flag]);
            $this->assertNotSame([], PageOp::validate(['t' => 'insert_node', 'node' => $node]));
            $this->assertNotSame([], PageDoc::validate(doc([$node])));
            $this->assertSame(F::doc(), Reducer::reduce(F::doc(), ['t' => 'insert_node', 'node' => $node]));
            $before = doc([node('art', $type)]);
            $invalid = [];
            $this->assertSame($before, Reducer::reduce($before, ['t' => 'set_node_props', 'id' => 'art', 'patch' => ['decorative' => $flag]], ['onInvalid' => static function ($_, $reason) use (&$invalid): void {
                $invalid[] = $reason;
            }]));
            $this->assertSame(['invalid decorative props'], $invalid);
        }
    }
    $node = node('link', 'link', null, ['content' => 'Pricing', 'href' => '#pricing']);
    $doc = doc([$node]);
    $this->assertSame($doc, Reducer::reduce($doc, ['t' => 'set_node_props', 'id' => 'link', 'patch' => ['decorative' => true]]));
    $this->assertNotSame([], PageDoc::validate(doc([node('link', 'link', null, ['decorative' => false])])));
});

test('meaningful nodes cannot enter or be wrapped by a decorative ancestor', function () {
    foreach (['text', 'heading', 'richtext', 'link', 'button', 'list', 'image', 'callout', 'code', 'card', 'stack', 'repeater', 'host-widget'] as $type) {
        $root = node('art', 'frame', null, ['decorative' => true]);
        $nested = node('nested', 'shape', 'art');
        $child = node('content', $type, 'nested', ['content' => 'Visible content', 'alt' => 'Meaningful image']);
        $before = doc([$root, $nested]);
        $this->assertSame($before, Reducer::reduce($before, ['t' => 'insert_node', 'node' => $child]), $type);
        $before['nodes']['content'] = array_replace($child, ['parent' => null]);
        $this->assertSame($before, Reducer::reduce($before, ['t' => 'move_node', 'id' => 'content', 'parent' => 'nested', 'order' => 'V']), $type);
        $raw = doc([$root, $nested, $child]);
        $this->assertNotSame([], PageDoc::validate($raw, [...PageDoc::NODE_TYPES, 'host-widget']), $type);
        $raw['nodes']['art']['props']['decorative'] = false;
        $this->assertSame($raw, Reducer::reduce($raw, ['t' => 'set_node_props', 'id' => 'art', 'patch' => ['decorative' => true]]), $type);
    }
});

test('subtree moves and nested accessibility changes are checked', function () {
    $doc = doc([node('art', 'frame', null, ['decorative' => true]), node('group'), node('button', 'button', 'group', ['label' => 'Trial', 'href' => '/signup'])]);
    $this->assertSame($doc, Reducer::reduce($doc, ['t' => 'move_node', 'id' => 'group', 'parent' => 'art', 'order' => 'Z']));
    $doc['nodes']['glyph'] = node('glyph', 'icon', 'art', ['name' => 'check', 'decorative' => true, 'label' => 'Check']);
    $this->assertSame($doc, Reducer::reduce($doc, ['t' => 'set_node_props', 'id' => 'glyph', 'patch' => ['decorative' => false]]));
    $doc['nodes']['glyph']['props']['decorative'] = false;
    $this->assertSame('decorative subtree contains meaningful content', Decorative::error($doc['nodes']));
});

test('safe compositions render hidden and malformed stored content fails visible', function () {
    $doc = doc([node('art', 'frame', null, ['decorative' => true]), node('shape', 'shape', 'art')]);
    $this->assertSame([], PageDoc::validate($doc));
    $this->assertStringContainsString('data-cms="art" aria-hidden="true"', PageRenderer::render($doc)['html']);
    $doc['nodes']['cta'] = node('cta', 'button', 'shape', ['label' => 'Start trial', 'href' => '/signup']);
    $html = PageRenderer::render($doc)['html'];
    $this->assertStringContainsString('Start trial', $html);
    $this->assertStringNotContainsString('aria-hidden', $html);
    unset($doc['nodes']['cta']);
    $this->assertStringContainsString('aria-hidden="true"', PageRenderer::render($doc)['html']);
});

test('adding the flag has exact wire undo for the whole subtree', function () {
    $doc = doc([node('art'), node('child', 'shape', 'art')]);
    $op = ['t' => 'set_node_props', 'id' => 'art', 'patch' => ['decorative' => true]];
    $inverse = json_decode(json_encode(Reducer::invert($doc, $op)), true);
    $next = Reducer::reduce($doc, $op);
    $this->assertTrue($next['nodes']['art']['props']['decorative']);
    $this->assertSame($doc['nodes'], Reducer::reduceAll($next, $inverse)['nodes']);
});

test('decorative artwork cannot hide actionable native shapes', function () {
    $action = ['on' => 'click', 'do' => ['kind' => 'navigate', 'href' => '/signup']];
    $before = doc([node('art', 'frame', null, ['decorative' => true]), node('shape', 'shape', 'art')]);
    $this->assertSame($before, Reducer::reduce($before, ['t' => 'set_action', 'id' => 'shape', 'index' => 0, 'action' => $action]));
    $active = node('active', 'shape', 'art') + ['actions' => [$action]];
    $this->assertSame($before, Reducer::reduce($before, ['t' => 'insert_node', 'node' => $active]));
    $raw = doc([...array_values($before['nodes']), $active]);
    $this->assertNotSame([], PageDoc::validate($raw));
    $this->assertStringNotContainsString('aria-hidden', PageRenderer::render($raw)['html']);
    $raw['nodes']['active']['parent'] = null;
    $this->assertSame($raw, Reducer::reduce($raw, ['t' => 'move_node', 'id' => 'active', 'parent' => 'art', 'order' => 'Z']));
});

test('deep decorative validation is iterative and does not hide malformed props', function () {
    $nodes = [];
    for ($i = 0; $i < 1500; $i++) {
        $nodes[] = node('n'.$i, 'frame', $i === 0 ? null : 'n'.($i - 1), ['decorative' => true]);
    }
    $doc = doc($nodes);
    $this->assertSame([], PageDoc::validate($doc));
    $this->assertSame($doc, Reducer::reduce($doc, ['t' => 'insert_node', 'node' => node('cta', 'button', 'n1499', ['label' => 'Trial'])]));
    foreach ([null, false, (object) ['decorative' => true]] as $props) {
        $raw = doc([node('art')]);
        $raw['nodes']['art']['props'] = $props;
        $this->assertStringNotContainsString('aria-hidden', PageRenderer::render($raw)['html']);
    }
});
