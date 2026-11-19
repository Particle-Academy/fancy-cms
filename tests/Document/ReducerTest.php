<?php

declare(strict_types=1);

namespace FancyCms\Tests\Document\ReducerTest;

use FancyCms\Document\Ordering;
use FancyCms\Document\PageJson;
use FancyCms\Document\Reducer;
use FancyCms\Tests\Support\PageFixtures as F;

function ops(): iterable {
    foreach (F::ops() as $name => $op) {
        yield $name => [$op];
    }
}

test('each operation is pure and increments seq once', function (array $op) {
    $doc = F::doc();
    $copy = $doc;
    $out = Reducer::reduce($doc, $op);
    $this->assertSame($copy, $doc);
    $this->assertSame(1, $out['seq']);
    $this->assertSame(Reducer::reduceAll($doc, [$op]), $out);
})->with(fn (): iterable => ops());

test('invalid targets and cycle moves are exact noops', function () {
    $doc = F::doc();
    foreach (F::ops() as $op) {
        if (isset($op['id'])) {
            $op['id'] = 'missing';
            $this->assertSame($doc, Reducer::reduce($doc, $op));
        }
    }
    foreach ([
        ['t' => 'insert_node', 'node' => F::node('a')],
        ['t' => 'insert_node', 'node' => F::node('x', 'missing')],
        ['t' => 'move_node', 'id' => 'a', 'parent' => 'a', 'order' => 'Z'],
        ['t' => 'move_node', 'id' => 'a', 'parent' => 'grandchild', 'order' => 'Z'],
        ['t' => 'move_node', 'id' => 'a', 'parent' => 'missing', 'order' => 'Z'],
        ['t' => 'reorder_roots', 'order' => ['a']],
        ['t' => 'unknown'],
    ] as $op) {
        $this->assertSame($doc, Reducer::reduce($doc, $op));
    }
});

test('remove drops subtree and helpers follow js depth first order', function () {
    $doc = F::doc();
    $this->assertSame(['child', 'grandchild'], Reducer::descendantIds($doc, 'a'));
    $this->assertSame(['a', 'b'], Reducer::rootIds($doc));
    $this->assertSame(['b'], array_keys(Reducer::reduce($doc, ['t' => 'remove_node', 'id' => 'a'])['nodes']));
});

test('a root permutation cannot repeat an id', function () {
    $doc = F::doc();
    $this->assertSame($doc, Reducer::reduce($doc, ['t' => 'reorder_roots', 'order' => ['a', 'a']]));
});

test('patches are shallow null is data and missing layout clears', function () {
    $doc = F::doc();
    $doc['nodes']['a']['layout'] = 'stack';
    $doc['nodes']['a']['props']['nested'] = ['old' => 1];
    $out = Reducer::reduceAll($doc, [
        ['t' => 'set_node_props', 'id' => 'a', 'patch' => ['nested' => ['new' => 2], 'content' => null]],
        ['t' => 'set_layout', 'id' => 'a'],
        ['t' => 'set_style', 'id' => 'a', 'breakpoint' => 'lg', 'patch' => ['color' => null]],
        ['t' => 'set_constraints', 'id' => 'a', 'breakpoint' => 'md', 'patch' => ['left' => null]],
    ]);
    $this->assertSame(['new' => 2], $out['nodes']['a']['props']['nested']);
    $this->assertNull($out['nodes']['a']['props']['content']);
    $this->assertArrayNotHasKey('layout', $out['nodes']['a']);
    $this->assertSame(['color' => null], $out['nodes']['a']['style']['lg']);
    $this->assertSame(['base' => ['width' => 'hug', 'height' => 'hug'], 'md' => ['left' => null]], $out['nodes']['a']['constraints']);
});

test('animation replacement and action indices match js', function () {
    $doc = F::doc();
    $anim = F::ops()['set_animation'];
    $doc = Reducer::reduce($doc, $anim);
    $anim['anim']['duration'] = 10;
    $doc = Reducer::reduce($doc, $anim);
    $this->assertCount(1, $doc['nodes']['a']['animations']);
    $this->assertSame(10, $doc['nodes']['a']['animations'][0]['duration']);
    $action = F::ops()['set_action'];
    $action['index'] = 99;
    $doc = Reducer::reduce($doc, $action);
    foreach ([-1, 0.5] as $index) {
        $action['index'] = $index;
        $next = Reducer::reduce($doc, $action);
        $this->assertSame($doc['nodes']['a']['actions'], $next['nodes']['a']['actions']);
        $this->assertSame($doc['seq'] + 1, $next['seq']);
    }
    $doc = Reducer::reduce($doc, ['t' => 'set_action', 'id' => 'a', 'index' => 0, 'action' => null]);
    $this->assertSame([], $doc['nodes']['a']['actions']);
});

test('inverses match the js wire contract and unsupported ops are explicit', function () {
    $doc = F::doc();
    $this->assertSame([['t' => 'remove_node', 'id' => 'new']], Reducer::invert($doc, F::ops()['insert_node']));
    $inverse = Reducer::invert($doc, ['t' => 'remove_node', 'id' => 'a']);
    $this->assertSame(['a', 'child', 'grandchild'], array_column(array_column(array_slice($inverse, 0, 3), 'node'), 'id'));
    $this->assertSame(['t' => 'reorder_roots', 'order' => ['a', 'b']], $inverse[3]);
    $this->assertSame([['t' => 'set_layout', 'id' => 'a']], Reducer::invert($doc, ['t' => 'set_layout', 'id' => 'a', 'layout' => 'grid']));
    $this->assertSame([['t' => 'set_style', 'id' => 'a', 'breakpoint' => 'md', 'patch' => []]], Reducer::invert($doc, ['t' => 'set_style', 'id' => 'a', 'breakpoint' => 'md', 'patch' => ['color' => 'red']]));
    foreach (['set_constraints', 'set_animation', 'remove_animation', 'set_action'] as $name) {
        $this->assertSame([], Reducer::invert($doc, F::ops()[$name]));
    }
});

test('fractional order strings are the shared base62 algorithm', function () {
    foreach ([[null, null, 'V'], ['V', null, 'VV'], [null, 'V', 'F'], ['A', 'B', 'AV'], ['Az', 'B', 'AzV'], ['A0', 'A1', 'A0V']] as [$a, $b, $expected]) {
        $this->assertSame($expected, Ordering::keyBetween($a, $b));
    }
    $this->assertSame(['b', 'a'], Reducer::rootIds(Reducer::reduce(F::doc(), ['t' => 'reorder_roots', 'order' => ['b', 'a']])));
});

test('json codec preserves empty objects arrays numeric keys and unicode', function () {
    $json = '{"id":"p","seq":0,"meta":{"title":"é / 😀","slug":"/","scrollMode":"smooth"},"theme":{"empty":{},"list":[],"numeric":{"0":"zero"}},"breakpoints":["base"],"nodes":{}}';
    $doc = PageJson::decodeDocument($json);
    $this->assertEquals(json_decode($json), json_decode(PageJson::encodeDocument($doc)));
    $op = PageJson::decodeOp('{"t":"set_theme","patch":{"obj":{},"arr":[]}}');
    $out = json_decode(PageJson::encodeDocument(Reducer::reduce($doc, $op)));
    $this->assertInstanceOf(\stdClass::class, $out->theme->obj);
    $this->assertSame([], $out->theme->arr);
});
