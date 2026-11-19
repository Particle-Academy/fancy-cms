<?php

declare(strict_types=1);

namespace FancyCms\Tests\IconTest;

use FancyCms\Agent;
use FancyCms\Document\PageDoc;
use FancyCms\Document\PageOp;
use FancyCms\Document\Reducer;
use FancyCms\Render\PageRenderer;
use FancyCms\Tests\Support\PageFixtures as F;

const NAMES = ['arrow-right', 'calculator', 'check', 'file-text', 'quote', 'receipt-text', 'users'];

function icon(array $props = []): array {
    return array_replace(F::node('icon'), ['type' => 'icon', 'props' => $props + ['name' => 'receipt-text', 'decorative' => true, 'label' => '']]);
}

function doc(array $props = []): array {
    return array_replace(F::doc(), ['nodes' => ['icon' => icon($props)]]);
}

test('all allowlisted icons validate and render inline without runtime lookup', function () {
    $this->assertSame('Lucide name; search cms_icons(query,cursor)', Agent::describe()['elements']['icon']['name'] ?? null);
    foreach (NAMES as $name) {
        $doc = doc(['name' => $name]);
        $this->assertSame([], PageDoc::validate($doc), $name);
        $this->assertSame([], PageOp::validate(['t' => 'insert_node', 'node' => $doc['nodes']['icon']]));
        $html = PageRenderer::render($doc)['html'];
        $this->assertStringContainsString('data-react-fancy-icon=""', $html);
        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString('stroke="currentColor"', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
        $this->assertStringNotContainsString('role="img"', $html);
    }
});

test('meaningful icons have an escaped label and decorative icons hide it', function () {
    $label = 'Receipt <script> & "tax"';
    $html = PageRenderer::render(doc(['decorative' => false, 'label' => $label]))['html'];
    $this->assertStringContainsString('role="img"', $html);
    $this->assertStringContainsString('aria-label="Receipt &lt;script&gt; &amp; &quot;tax&quot;"', $html);
    $this->assertStringNotContainsString('<script>', $html);
    $decorative = PageRenderer::render(doc(['label' => $label]))['html'];
    $this->assertStringNotContainsString('aria-label', $decorative);
});

test('invalid icon props fail document validation and are logged reducer noops', function () {
    $bad = [
        ['name' => '__proto__'], ['name' => 'constructor'], ['name' => 'toString'], ['name' => 'not-an-icon'],
        ['name' => '<svg onload="bad()">'], ['name' => ['$bind' => 'icon']],
        ['decorative' => 'true'], ['decorative' => null], ['decorative' => false, 'label' => '  '],
        ['label' => 3], ['label' => ['$bind' => 'label']], ['label' => str_repeat('é', 513)],
        ['svg' => '<svg/>'], ['path' => 'M0 0'], ['onClick' => 'bad()'],
    ];
    foreach ($bad as $props) {
        $this->assertNotSame([], PageDoc::validate(doc($props)), json_encode($props));
        $before = F::doc();
        $invalid = [];
        $out = Reducer::reduce($before, ['t' => 'insert_node', 'node' => icon($props)], ['onInvalid' => static function ($_, $reason) use (&$invalid): void {
            $invalid[] = $reason;
        }]);
        $this->assertSame($before, $out);
        $this->assertSame(['invalid icon props'], $invalid);
        $before = doc();
        $invalid = [];
        $out = Reducer::reduce($before, ['t' => 'set_node_props', 'id' => 'icon', 'patch' => $props], ['onInvalid' => static function ($_, $reason) use (&$invalid): void {
            $invalid[] = $reason;
        }]);
        $this->assertSame($before, $out);
        $this->assertSame(['invalid icon props'], $invalid);
        $html = PageRenderer::render(doc($props))['html'];
        $this->assertStringNotContainsString('<svg', $html);
        $this->assertStringNotContainsString('role="img"', $html);
    }
});

test('icons are leaves and valid changes undo through the same spine', function () {
    $doc = doc();
    $op = ['t' => 'set_node_props', 'id' => 'icon', 'patch' => ['name' => 'calculator', 'decorative' => false, 'label' => 'Tax estimate']];
    $inverse = Reducer::invert($doc, $op);
    $changed = Reducer::reduce($doc, $op);
    $this->assertSame('calculator', $changed['nodes']['icon']['props']['name']);
    $undone = Reducer::reduceAll($changed, $inverse);
    $this->assertSame($doc['nodes'], $undone['nodes']);
    foreach ([['t' => 'insert_node', 'node' => F::node('child', 'icon')], ['t' => 'move_node', 'id' => 'child', 'parent' => 'icon', 'order' => 'V']] as $op) {
        $before = $doc;
        $before['nodes']['child'] = F::node('child');
        if ($op['t'] === 'insert_node') {
            unset($before['nodes']['child']);
        }
        $this->assertSame($before, Reducer::reduce($before, $op));
    }
    $doc['nodes']['child'] = F::node('child', 'icon');
    $this->assertNotSame([], PageDoc::validate($doc));
});

test('wire replay undo restores an absent optional label', function () {
    $doc = doc();
    unset($doc['nodes']['icon']['props']['label']);
    $op = ['t' => 'set_node_props', 'id' => 'icon', 'patch' => ['decorative' => false, 'label' => 'Receipt']];
    $inverse = json_decode(json_encode(Reducer::invert($doc, $op)), true);
    $this->assertSame($doc['nodes'], Reducer::reduceAll(Reducer::reduce($doc, $op), $inverse)['nodes']);
});

test('required icon props cannot be omitted', function () {
    foreach (['name', 'decorative'] as $key) {
        $node = icon();
        unset($node['props'][$key]);
        $this->assertNotSame([], PageOp::validate(['t' => 'insert_node', 'node' => $node]));
        $this->assertSame(F::doc(), Reducer::reduce(F::doc(), ['t' => 'insert_node', 'node' => $node]));
    }
});
