<?php

declare(strict_types=1);

namespace FancyCms\Tests\CssEmitterTest;

use FancyCms\Render\CssEmitter;
use FancyCms\Render\JsValue;
use FancyCms\Tests\Support\PageFixtures as F;

function css(array $style, array $theme = []): string {
    $doc = F::doc();
    $doc['nodes'] = ['a' => F::node('a')];
    $doc['nodes']['a']['style']['base'] = $style;
    $doc['theme'] = $theme;

    $css = CssEmitter::emit($doc);
    // These assertions isolate authored declarations; Renderer09Test and
    // the cross-runtime byte comparison cover the fixed base stylesheet.
    test()->assertStringStartsWith(CssEmitter::BASE_CSS, $css);

    return $css === CssEmitter::BASE_CSS."\n" ? '' : substr($css, strlen(CssEmitter::BASE_CSS) + 2);
}

function unsafeValues(): iterable {
    foreach (['red; color: blue', 'red } body { display: none', '</style><script>x</script>', 'url(x); color:red', 'rgb(0,0,0)); color:red'] as $value) {
        yield $value => [$value];
    }
}

test('declaration escape attempts are dropped', function (string $value) {
    $this->assertSame('', css(['color' => $value]));
})->with(fn (): iterable => unsafeValues());

function safeValues(): iterable {
    foreach (['var(--brand)', 'url(data:image/png;base64,AA==)', '"text; still quoted"', "'text; still quoted'", 'linear-gradient(red, blue)'] as $value) {
        yield $value => [$value];
    }
}

test('parenthesized or quoted semicolons remain legal', function (string $value) {
    $this->assertStringContainsString('background: '.$value.';', css(['background' => $value]));
})->with(fn (): iterable => safeValues());

test('nested theme tokens skip name arrays and unsafe values', function () {
    $theme = ['name' => 'brand', 'Color' => ['Primary' => '#123', 'bad' => 'red; } body { display:none'], 'Space' => 2.9, 'list' => [1, 2], 'empty' => (object) []];
    $this->assertSame(":root {\n  --cms-color-primary: #123;\n  --cms-space: 2.9;\n}\n", css([], $theme));
});

test('keyword allowlists and integer zindex are respected', function () {
    $this->assertSame('', css(['overflow' => 'bad', 'backgroundSize' => 'fill', 'textTransform' => 'bad', 'zIndex' => INF]));
    $this->assertSame("[data-cms=\"a\"] {\n  z-index: 2;\n}\n", css(['zIndex' => 2.9]));
});

test('responsive containers visibility and autofit have js precedence', function () {
    $doc = F::doc();
    $node = F::node('a');
    $node['layout'] = 'grid';
    $node['style'] = ['base' => ['columns' => 1, 'hidden' => true], 'md' => ['columns' => 2.9, 'hidden' => false], 'lg' => ['columns' => 9, 'columnMin' => ['value' => 240, 'unit' => 'px']]];
    $doc['nodes'] = ['a' => $node];
    $css = CssEmitter::emit($doc);
    $this->assertStringContainsString('display: none;', $css);
    $this->assertStringContainsString('display: grid;', $css);
    $this->assertStringContainsString('repeat(2, 1fr)', $css);
    $this->assertStringContainsString('repeat(auto-fit, minmax(min(240px, 100%), 1fr))', $css);
    $this->assertStringNotContainsString('repeat(9, 1fr)', $css);
});

test('style overrides constraints and sticky overrides free layout', function () {
    $doc = F::doc();
    $doc['nodes']['a']['layout'] = 'free';
    $doc['nodes']['child']['constraints'] = ['base' => ['width' => 'hug', 'height' => 'hug', 'centerX' => true]];
    $doc['nodes']['child']['style']['base'] = ['sticky' => true, 'transform' => 'scale(2)', 'paddingX' => ['value' => 2, 'unit' => 'rem'], 'marginX' => 'auto'];
    $css = CssEmitter::emit($doc);
    $this->assertStringContainsString('position: sticky;', $css);
    $this->assertStringContainsString('top: 0;', $css);
    $this->assertStringContainsString('transform: scale(2);', $css);
    $this->assertStringContainsString('padding-left: 2rem;', $css);
    $this->assertStringContainsString('margin-right: auto;', $css);
});

test('number formatting is independent of php serialization precision', function () {
    $previous = ini_get('serialize_precision');
    try {
        ini_set('serialize_precision', '3');
        $this->assertStringContainsString('opacity: 0.123456789;', css(['opacity' => 0.123456789]));
    } finally {
        ini_set('serialize_precision', $previous);
    }
});

test('node ids cannot break a selector or end an inline style', function () {
    $doc = F::doc();
    $id = "bad\"\\\n</style><script>";
    $node = F::node($id);
    $node['style']['base']['color'] = 'red';
    $doc['nodes'] = [$id => $node];
    $css = CssEmitter::emit($doc);
    $this->assertStringNotContainsString('<', $css);
    $this->assertStringContainsString('\\3c /style>', $css);
    $this->assertStringContainsString('\\a ', $css);
});

test('every emitted value has a length keyword or number gate', function () {
    $this->assertSame('', css(['padding' => ['value' => 2, 'unit' => 'px; color:red'], 'color' => [], 'align' => 'center; color:red', 'justify' => '__proto__', 'textAlign' => 'left; color:red', 'opacity' => '1; color:red', 'fontSize' => 'huge']));
});

test('theme sanitization collisions follow js object entry order', function () {
    $this->assertSame(":root {\n  --cms-1: from-space;\n  --cms-nested-1: nested-space;\n}\n", css([], ['1 ' => 'from-space', 1 => 'from-number', 'nested' => (object) ['1 ' => 'nested-space', '1' => 'nested-number']]));
});

test('shortest decimal search checks asymmetric power of two boundaries', function () {
    foreach ([7.120236347223045e-307, 7.291122019556398e-304, 8.209073602596753e-289] as $i => $value) {
        $expected = ['7.120236347223045e-307', '7.291122019556398e-304', '8.209073602596753e-289'][$i];
        $this->assertSame($expected, JsValue::number($value));
        $this->assertSame('-'.$expected, JsValue::number(-$value));
    }
});
