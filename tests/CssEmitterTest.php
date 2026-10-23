<?php

declare(strict_types=1);

namespace FancyCms\Tests;

use FancyCms\Render\CssEmitter;
use FancyCms\Render\JsValue;
use FancyCms\Tests\Support\PageFixtures as F;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CssEmitterTest extends TestCase
{
    private function css(array $style, array $theme = []): string
    {
        $doc = F::doc();
        $doc['nodes'] = ['a' => F::node('a')];
        $doc['nodes']['a']['style']['base'] = $style;
        $doc['theme'] = $theme;

        $css = CssEmitter::emit($doc);
        // These assertions isolate authored declarations; Renderer09Test and
        // the cross-runtime byte comparison cover the fixed base stylesheet.
        self::assertStringStartsWith(CssEmitter::BASE_CSS, $css);

        return $css === CssEmitter::BASE_CSS."\n" ? '' : substr($css, strlen(CssEmitter::BASE_CSS) + 2);
    }

    public static function unsafeValues(): iterable
    {
        foreach (['red; color: blue', 'red } body { display: none', '</style><script>x</script>', 'url(x); color:red', 'rgb(0,0,0)); color:red'] as $value) {
            yield $value => [$value];
        }
    }

    #[DataProvider('unsafeValues')]
    public function test_declaration_escape_attempts_are_dropped(string $value): void
    {
        self::assertSame('', $this->css(['color' => $value]));
    }

    public static function safeValues(): iterable
    {
        foreach (['var(--brand)', 'url(data:image/png;base64,AA==)', '"text; still quoted"', "'text; still quoted'", 'linear-gradient(red, blue)'] as $value) {
            yield $value => [$value];
        }
    }

    #[DataProvider('safeValues')]
    public function test_parenthesized_or_quoted_semicolons_remain_legal(string $value): void
    {
        self::assertStringContainsString('background: '.$value.';', $this->css(['background' => $value]));
    }

    public function test_nested_theme_tokens_skip_name_arrays_and_unsafe_values(): void
    {
        $theme = ['name' => 'brand', 'Color' => ['Primary' => '#123', 'bad' => 'red; } body { display:none'], 'Space' => 2.9, 'list' => [1, 2], 'empty' => (object) []];
        self::assertSame(":root {\n  --cms-color-primary: #123;\n  --cms-space: 2.9;\n}\n", $this->css([], $theme));
    }

    public function test_keyword_allowlists_and_integer_zindex_are_respected(): void
    {
        self::assertSame('', $this->css(['overflow' => 'bad', 'backgroundSize' => 'fill', 'textTransform' => 'bad', 'zIndex' => INF]));
        self::assertSame("[data-cms=\"a\"] {\n  z-index: 2;\n}\n", $this->css(['zIndex' => 2.9]));
    }

    public function test_responsive_containers_visibility_and_autofit_have_js_precedence(): void
    {
        $doc = F::doc();
        $node = F::node('a');
        $node['layout'] = 'grid';
        $node['style'] = ['base' => ['columns' => 1, 'hidden' => true], 'md' => ['columns' => 2.9, 'hidden' => false], 'lg' => ['columns' => 9, 'columnMin' => ['value' => 240, 'unit' => 'px']]];
        $doc['nodes'] = ['a' => $node];
        $css = CssEmitter::emit($doc);
        self::assertStringContainsString('display: none;', $css);
        self::assertStringContainsString('display: grid;', $css);
        self::assertStringContainsString('repeat(2, 1fr)', $css);
        self::assertStringContainsString('repeat(auto-fit, minmax(min(240px, 100%), 1fr))', $css);
        self::assertStringNotContainsString('repeat(9, 1fr)', $css);
    }

    public function test_style_overrides_constraints_and_sticky_overrides_free_layout(): void
    {
        $doc = F::doc();
        $doc['nodes']['a']['layout'] = 'free';
        $doc['nodes']['child']['constraints'] = ['base' => ['width' => 'hug', 'height' => 'hug', 'centerX' => true]];
        $doc['nodes']['child']['style']['base'] = ['sticky' => true, 'transform' => 'scale(2)', 'paddingX' => ['value' => 2, 'unit' => 'rem'], 'marginX' => 'auto'];
        $css = CssEmitter::emit($doc);
        self::assertStringContainsString('position: sticky;', $css);
        self::assertStringContainsString('top: 0;', $css);
        self::assertStringContainsString('transform: scale(2);', $css);
        self::assertStringContainsString('padding-left: 2rem;', $css);
        self::assertStringContainsString('margin-right: auto;', $css);
    }

    public function test_number_formatting_is_independent_of_php_serialization_precision(): void
    {
        $previous = ini_get('serialize_precision');
        try {
            ini_set('serialize_precision', '3');
            self::assertStringContainsString('opacity: 0.123456789;', $this->css(['opacity' => 0.123456789]));
        } finally {
            ini_set('serialize_precision', $previous);
        }
    }

    public function test_node_ids_cannot_break_a_selector_or_end_an_inline_style(): void
    {
        $doc = F::doc();
        $id = "bad\"\\\n</style><script>";
        $node = F::node($id);
        $node['style']['base']['color'] = 'red';
        $doc['nodes'] = [$id => $node];
        $css = CssEmitter::emit($doc);
        self::assertStringNotContainsString('<', $css);
        self::assertStringContainsString('\\3c /style>', $css);
        self::assertStringContainsString('\\a ', $css);
    }

    public function test_every_emitted_value_has_a_length_keyword_or_number_gate(): void
    {
        self::assertSame('', $this->css(['padding' => ['value' => 2, 'unit' => 'px; color:red'], 'color' => [], 'align' => 'center; color:red', 'justify' => '__proto__', 'textAlign' => 'left; color:red', 'opacity' => '1; color:red', 'fontSize' => 'huge']));
    }

    public function test_theme_sanitization_collisions_follow_js_object_entry_order(): void
    {
        self::assertSame(":root {\n  --cms-1: from-space;\n  --cms-nested-1: nested-space;\n}\n", $this->css([], ['1 ' => 'from-space', 1 => 'from-number', 'nested' => (object) ['1 ' => 'nested-space', '1' => 'nested-number']]));
    }

    public function test_shortest_decimal_search_checks_asymmetric_power_of_two_boundaries(): void
    {
        foreach ([7.120236347223045e-307, 7.291122019556398e-304, 8.209073602596753e-289] as $i => $value) {
            $expected = ['7.120236347223045e-307', '7.291122019556398e-304', '8.209073602596753e-289'][$i];
            self::assertSame($expected, JsValue::number($value));
            self::assertSame('-'.$expected, JsValue::number(-$value));
        }
    }
}
