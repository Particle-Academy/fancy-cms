<?php

declare(strict_types=1);

namespace FancyCms\Tests\Document\ValidationTest;

use FancyCms\Document\PageDoc;
use FancyCms\Document\PageJson;
use FancyCms\Document\PageOp;
use FancyCms\Exceptions\SchemaException;
use FancyCms\Tests\Support\PageFixtures as F;

test('all canonical ops validate and vocabulary is closed', function () {
    $this->assertSame(array_keys(F::ops()), PageOp::TYPES);
    foreach (F::ops() as $op) {
        $this->assertSame([], PageOp::validate($op));
    }
    $this->assertSame([], PageDoc::validate(F::doc()));
});

function invalidOps(): iterable {
    yield 'unknown type' => [['t' => 'set_props'], '$.t'];
    yield 'missing id' => [['t' => 'remove_node'], '$.id'];
    yield 'bad parent' => [['t' => 'move_node', 'id' => 'a', 'parent' => 4, 'order' => 'V'], '$.parent'];
    yield 'bad patch' => [['t' => 'set_node_props', 'id' => 'a', 'patch' => 7], '$.patch'];
    yield 'bad breakpoint' => [['t' => 'set_style', 'id' => 'a', 'breakpoint' => 1, 'patch' => []], '$.breakpoint'];
    yield 'bad animation' => [['t' => 'set_animation', 'id' => 'a', 'anim' => ['id' => 'a', 'driver' => 'unknown', 'keyframes' => []]], '$.anim.driver'];
    yield 'bad action' => [['t' => 'set_action', 'id' => 'a', 'index' => 0, 'action' => ['on' => 'click', 'do' => ['kind' => 'unknown']]], '$.action.do.kind'];
    yield 'nonfinite index' => [['t' => 'set_action', 'id' => 'a', 'index' => INF, 'action' => null], '$.index'];
    yield 'nonjson patch' => [['t' => 'set_theme', 'patch' => ['x' => static fn () => 1]], '$.patch.x'];
    yield 'extra envelope key' => [['t' => 'remove_node', 'id' => 'a', 'extra' => 1], '$.extra'];
}

test('bad ops report the exact path', function (array $op, string $path) {
    $errors = PageOp::validate($op);
    $this->assertContains($path, array_column($errors, 'path'));
    try {
        PageOp::assert($op);
        $this->fail('Expected schema refusal');
    } catch (SchemaException $e) {
        $this->assertSame($errors, $e->getErrors());
        $this->assertStringContainsString($errors[0]['path'], $e->getMessage());
    }
})->with(fn (): iterable => invalidOps());

test('document boundary rejects shape unknown types orphans and cycles', function () {
    foreach (['unknown', 'parent', 'cycle', 'key', 'seq', 'style'] as $kind) {
        $doc = F::doc();
        match ($kind) {
            'unknown' => $doc['nodes']['a']['type'] = 'unregistered-addon',
            'parent' => $doc['nodes']['b']['parent'] = 'missing',
            'cycle' => $doc['nodes']['a']['parent'] = 'grandchild',
            'key' => $doc['nodes']['a']['id'] = 'wrong',
            'seq' => $doc['seq'] = -1,
            'style' => $doc['nodes']['a']['style'] = 'bad',
        };
        $this->assertNotSame([], PageDoc::validate($doc), $kind);
    }
    $doc = F::doc();
    $doc['nodes']['a']['type'] = 'host-widget';
    $this->assertSame([], PageDoc::validate($doc, [...PageDoc::NODE_TYPES, 'host-widget']));
});

test('safe apply validates before and after reducing', function () {
    $doc = F::doc();
    $this->assertSame('Edited', PageDoc::apply($doc, F::ops()['set_meta'])['meta']['title']);
    $this->expectException(SchemaException::class);
    PageDoc::apply($doc, ['t' => 'insert_node', 'node' => F::node('orphan', 'missing')]);
});

test('deep document validation is iterative', function () {
    $doc = F::doc();
    $doc['nodes'] = [];
    for ($i = 0; $i < 2000; $i++) {
        $doc['nodes']['n'.$i] = F::node('n'.$i, $i === 0 ? null : 'n'.($i - 1));
    }
    $this->assertSame([], PageDoc::validate($doc));
    $doc['nodes']['n0']['parent'] = 'n1999';
    $this->assertNotSame([], PageDoc::validate($doc));
});

test('known css lengths and keywords are validated before storage', function () {
    foreach ([['padding' => ['value' => 1, 'unit' => 'px; color:red']], ['padding' => ['unit' => 'px']], ['align' => 'center; color:red'], ['direction' => 'row; color:red'], ['hidden' => 'false'], ['opacity' => 'red'], ['background' => []]] as $style) {
        $this->assertNotSame([], PageOp::validate(['t' => 'set_style', 'id' => 'a', 'breakpoint' => 'base', 'patch' => $style]));
        $doc = F::doc();
        $doc['nodes']['a']['style']['base'] = $style;
        $this->assertNotSame([], PageDoc::validate($doc));
    }
});

test('integral json numbers are valid seq values', function () {
    $doc = F::doc();
    $doc['seq'] = 1.0;
    $this->assertSame([], PageDoc::validate($doc));
    $doc['seq'] = 1.5;
    $this->assertNotSame([], PageDoc::validate($doc));
});

test('wire record errors name the path instead of guessing array tags', function () {
    foreach ([['doc', '{"nodes":[]}', '$.nodes'], ['op', '{"t":"set_theme","patch":[]}', '$.patch']] as [$kind, $json, $path]) {
        try {
            if ($kind === 'doc') {
                PageJson::decodeDocument($json);
            } else {
                PageJson::decodeOp($json);
            }
            $this->fail('A JSON array is not a record.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString($path, $e->getMessage());
        }
    }
});

test('json encoding never invokes an object callback', function () {
    $bomb = new class extends \stdClass implements \JsonSerializable
    {
        public bool $called = false;

        public function jsonSerialize(): mixed
        {
            $this->called = true;

            return 'unsafe callback';
        }
    };
    $doc = F::doc();
    $doc['theme']['bad'] = $bomb;
    $this->assertNotSame([], PageDoc::validate($doc));
    try {
        PageJson::encodeDocument($doc);
        $this->fail('Expected non-JSON object refusal');
    } catch (SchemaException $e) {
        $this->assertContains('$.theme.bad', array_column($e->getErrors(), 'path'));
    } finally {
        $this->assertFalse($bomb->called);
    }
});
