<?php

declare(strict_types=1);

namespace FancyCms\Tests\Document;

use FancyCms\Document\Ordering;
use FancyCms\Document\PageJson;
use FancyCms\Document\Reducer;
use FancyCms\Tests\Support\PageFixtures as F;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReducerTest extends TestCase
{
    public static function ops(): iterable
    {
        foreach (F::ops() as $name => $op) {
            yield $name => [$op];
        }
    }

    #[DataProvider('ops')]
    public function test_each_operation_is_pure_and_increments_seq_once(array $op): void
    {
        $doc = F::doc();
        $copy = $doc;
        $out = Reducer::reduce($doc, $op);
        self::assertSame($copy, $doc);
        self::assertSame(1, $out['seq']);
        self::assertSame(Reducer::reduceAll($doc, [$op]), $out);
    }

    public function test_invalid_targets_and_cycle_moves_are_exact_noops(): void
    {
        $doc = F::doc();
        foreach (F::ops() as $op) {
            if (isset($op['id'])) {
                $op['id'] = 'missing';
                self::assertSame($doc, Reducer::reduce($doc, $op));
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
            self::assertSame($doc, Reducer::reduce($doc, $op));
        }
    }

    public function test_remove_drops_subtree_and_helpers_follow_js_depth_first_order(): void
    {
        $doc = F::doc();
        self::assertSame(['child', 'grandchild'], Reducer::descendantIds($doc, 'a'));
        self::assertSame(['a', 'b'], Reducer::rootIds($doc));
        self::assertSame(['b'], array_keys(Reducer::reduce($doc, ['t' => 'remove_node', 'id' => 'a'])['nodes']));
    }

    public function test_a_root_permutation_cannot_repeat_an_id(): void
    {
        $doc = F::doc();
        self::assertSame($doc, Reducer::reduce($doc, ['t' => 'reorder_roots', 'order' => ['a', 'a']]));
    }

    public function test_patches_are_shallow_null_is_data_and_missing_layout_clears(): void
    {
        $doc = F::doc();
        $doc['nodes']['a']['layout'] = 'stack';
        $doc['nodes']['a']['props']['nested'] = ['old' => 1];
        $out = Reducer::reduceAll($doc, [
            ['t' => 'set_node_props', 'id' => 'a', 'patch' => ['nested' => ['new' => 2], 'content' => null]],
            ['t' => 'set_layout', 'id' => 'a'],
            ['t' => 'set_style', 'id' => 'a', 'breakpoint' => 'lg', 'patch' => ['color' => null]],
            ['t' => 'set_constraints', 'id' => 'a', 'breakpoint' => 'md', 'patch' => ['left' => null]],
        ]);
        self::assertSame(['new' => 2], $out['nodes']['a']['props']['nested']);
        self::assertNull($out['nodes']['a']['props']['content']);
        self::assertArrayNotHasKey('layout', $out['nodes']['a']);
        self::assertSame(['color' => null], $out['nodes']['a']['style']['lg']);
        self::assertSame(['base' => ['width' => 'hug', 'height' => 'hug'], 'md' => ['left' => null]], $out['nodes']['a']['constraints']);
    }

    public function test_animation_replacement_and_action_indices_match_js(): void
    {
        $doc = F::doc();
        $anim = F::ops()['set_animation'];
        $doc = Reducer::reduce($doc, $anim);
        $anim['anim']['duration'] = 10;
        $doc = Reducer::reduce($doc, $anim);
        self::assertCount(1, $doc['nodes']['a']['animations']);
        self::assertSame(10, $doc['nodes']['a']['animations'][0]['duration']);
        $action = F::ops()['set_action'];
        $action['index'] = 99;
        $doc = Reducer::reduce($doc, $action);
        foreach ([-1, 0.5] as $index) {
            $action['index'] = $index;
            $next = Reducer::reduce($doc, $action);
            self::assertSame($doc['nodes']['a']['actions'], $next['nodes']['a']['actions']);
            self::assertSame($doc['seq'] + 1, $next['seq']);
        }
        $doc = Reducer::reduce($doc, ['t' => 'set_action', 'id' => 'a', 'index' => 0, 'action' => null]);
        self::assertSame([], $doc['nodes']['a']['actions']);
    }

    public function test_inverses_match_the_js_wire_contract_and_unsupported_ops_are_explicit(): void
    {
        $doc = F::doc();
        self::assertSame([['t' => 'remove_node', 'id' => 'new']], Reducer::invert($doc, F::ops()['insert_node']));
        $inverse = Reducer::invert($doc, ['t' => 'remove_node', 'id' => 'a']);
        self::assertSame(['a', 'child', 'grandchild'], array_column(array_column(array_slice($inverse, 0, 3), 'node'), 'id'));
        self::assertSame(['t' => 'reorder_roots', 'order' => ['a', 'b']], $inverse[3]);
        self::assertSame([['t' => 'set_layout', 'id' => 'a']], Reducer::invert($doc, ['t' => 'set_layout', 'id' => 'a', 'layout' => 'grid']));
        self::assertSame([['t' => 'set_style', 'id' => 'a', 'breakpoint' => 'md', 'patch' => []]], Reducer::invert($doc, ['t' => 'set_style', 'id' => 'a', 'breakpoint' => 'md', 'patch' => ['color' => 'red']]));
        foreach (['set_constraints', 'set_animation', 'remove_animation', 'set_action'] as $name) {
            self::assertSame([], Reducer::invert($doc, F::ops()[$name]));
        }
    }

    public function test_fractional_order_strings_are_the_shared_base62_algorithm(): void
    {
        foreach ([[null, null, 'V'], ['V', null, 'VV'], [null, 'V', 'F'], ['A', 'B', 'AV'], ['Az', 'B', 'AzV'], ['A0', 'A1', 'A0V']] as [$a, $b, $expected]) {
            self::assertSame($expected, Ordering::keyBetween($a, $b));
        }
        self::assertSame(['b', 'a'], Reducer::rootIds(Reducer::reduce(F::doc(), ['t' => 'reorder_roots', 'order' => ['b', 'a']])));
    }

    public function test_json_codec_preserves_empty_objects_arrays_numeric_keys_and_unicode(): void
    {
        $json = '{"id":"p","seq":0,"meta":{"title":"é / 😀","slug":"/","scrollMode":"smooth"},"theme":{"empty":{},"list":[],"numeric":{"0":"zero"}},"breakpoints":["base"],"nodes":{}}';
        $doc = PageJson::decodeDocument($json);
        self::assertEquals(json_decode($json), json_decode(PageJson::encodeDocument($doc)));
        $op = PageJson::decodeOp('{"t":"set_theme","patch":{"obj":{},"arr":[]}}');
        $out = json_decode(PageJson::encodeDocument(Reducer::reduce($doc, $op)));
        self::assertInstanceOf(\stdClass::class, $out->theme->obj);
        self::assertSame([], $out->theme->arr);
    }
}
