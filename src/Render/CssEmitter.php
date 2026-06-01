<?php

declare(strict_types=1);

namespace FancyCms\Render;

/**
 * PHP style→CSS emitter — the byte-for-byte mirror of the JS emitter in
 * @particle-academy/fancy-cms-ui (src/render/css.ts). Determinism (sorted
 * declarations, a fixed selector/media format, and sorted-id node order) lets
 * the parity harness diff the two engines exactly.
 *
 * Pure PHP — NO Laravel dependency — so it can run standalone (incl. the parity
 * CLI). Phase 0 covers the static subset (layout / constraints / style).
 */
final class CssEmitter
{
    /** Breakpoint → min-width (px). `base` has no media query. */
    private const BREAKPOINTS = ['base' => 0, 'md' => 768, 'lg' => 1024];

    private const ALIGN = [
        'start' => 'flex-start',
        'center' => 'center',
        'end' => 'flex-end',
        'stretch' => 'stretch',
    ];

    private const JUSTIFY = [
        'start' => 'flex-start',
        'center' => 'center',
        'end' => 'flex-end',
        'between' => 'space-between',
        'around' => 'space-around',
    ];

    /**
     * @param  array<string,mixed>  $doc
     */
    public static function emit(array $doc): string
    {
        $blocks = [];
        $nodes = $doc['nodes'] ?? [];
        $ids = array_keys($nodes);
        sort($ids, SORT_STRING);

        foreach ($ids as $id) {
            $node = $nodes[$id];
            $selector = self::selectorFor((string) $id);
            $parentFree = self::isParentFree($doc, $node);

            $base = array_merge(
                self::layoutDecls($node),
                isset($node['constraints']['base']) ? self::constraintDecls($node['constraints']['base'], $parentFree) : [],
                self::styleDecls($node['style']['base'] ?? []),
            );
            $baseRule = self::serializeRule($selector, $base, '');
            if ($baseRule !== '') {
                $blocks[] = $baseRule;
            }

            foreach (($doc['breakpoints'] ?? []) as $bp) {
                if ($bp === 'base' || ! array_key_exists($bp, self::BREAKPOINTS)) {
                    continue;
                }
                $px = self::BREAKPOINTS[$bp];
                $decls = array_merge(
                    isset($node['constraints'][$bp]) ? self::constraintDecls($node['constraints'][$bp], $parentFree) : [],
                    isset($node['style'][$bp]) ? self::styleDecls($node['style'][$bp]) : [],
                );
                $inner = self::serializeRule($selector, $decls, '  ');
                if ($inner !== '') {
                    $blocks[] = "@media (min-width: {$px}px) {\n{$inner}\n}";
                }
            }
        }

        return implode("\n\n", $blocks) . (count($blocks) ? "\n" : '');
    }

    private static function selectorFor(string $id): string
    {
        return "[data-cms=\"{$id}\"]";
    }

    /** @param array<string,mixed> $len */
    private static function lengthToCss(array $len): string
    {
        return self::num($len['value']) . $len['unit'];
    }

    private static function sizeToCss(mixed $size): string
    {
        if ($size === 'fill') {
            return '100%';
        }
        if ($size === 'hug') {
            return 'auto';
        }

        return self::lengthToCss($size);
    }

    /**
     * @param  array<string,mixed>  $s
     * @return array<string,string>
     */
    private static function styleDecls(array $s): array
    {
        $d = [];
        if (isset($s['padding'])) {
            $d['padding'] = self::lengthToCss($s['padding']);
        }
        if (isset($s['margin'])) {
            $d['margin'] = self::lengthToCss($s['margin']);
        }
        if (isset($s['radius'])) {
            $d['border-radius'] = self::lengthToCss($s['radius']);
        }
        if (array_key_exists('background', $s)) {
            $d['background'] = $s['background'];
        }
        if (array_key_exists('color', $s)) {
            $d['color'] = $s['color'];
        }
        if (array_key_exists('opacity', $s)) {
            $d['opacity'] = self::num($s['opacity']);
        }
        if (array_key_exists('fontFamily', $s)) {
            $d['font-family'] = $s['fontFamily'];
        }
        if (isset($s['fontSize'])) {
            $d['font-size'] = self::lengthToCss($s['fontSize']);
        }
        if (array_key_exists('fontWeight', $s)) {
            $d['font-weight'] = self::num($s['fontWeight']);
        }
        if (array_key_exists('lineHeight', $s)) {
            $d['line-height'] = self::num($s['lineHeight']);
        }
        if (array_key_exists('textAlign', $s)) {
            $d['text-align'] = $s['textAlign'];
        }
        if (isset($s['letterSpacing'])) {
            $d['letter-spacing'] = self::lengthToCss($s['letterSpacing']);
        }
        if (array_key_exists('border', $s)) {
            $d['border'] = $s['border'];
        }
        if (array_key_exists('boxShadow', $s)) {
            $d['box-shadow'] = $s['boxShadow'];
        }
        if (array_key_exists('transform', $s)) {
            $d['transform'] = $s['transform'];
        }
        if (array_key_exists('filter', $s)) {
            $d['filter'] = $s['filter'];
        }
        if (isset($s['gap'])) {
            $d['gap'] = self::lengthToCss($s['gap']);
        }
        if (array_key_exists('align', $s)) {
            $d['align-items'] = self::ALIGN[$s['align']] ?? $s['align'];
        }
        if (array_key_exists('justify', $s)) {
            $d['justify-content'] = self::JUSTIFY[$s['justify']] ?? $s['justify'];
        }

        return $d;
    }

    /**
     * @param  array<string,mixed>  $node
     * @return array<string,string>
     */
    private static function layoutDecls(array $node): array
    {
        $d = [];
        $layout = $node['layout'] ?? null;
        if ($layout === 'stack') {
            $d['display'] = 'flex';
            $d['flex-direction'] = $node['style']['base']['direction'] ?? 'column';
        } elseif ($layout === 'grid') {
            $d['display'] = 'grid';
            $cols = $node['style']['base']['columns'] ?? 1;
            $d['grid-template-columns'] = "repeat({$cols}, 1fr)";
        } elseif ($layout === 'free') {
            $d['position'] = 'relative';
        }

        return $d;
    }

    /**
     * @param  array<string,mixed>  $c
     * @return array<string,string>
     */
    private static function constraintDecls(array $c, bool $parentFree): array
    {
        $d = [];
        $transforms = [];
        if ($parentFree) {
            $d['position'] = 'absolute';
            if (isset($c['left'])) {
                $d['left'] = self::lengthToCss($c['left']);
            }
            if (isset($c['right'])) {
                $d['right'] = self::lengthToCss($c['right']);
            }
            if (isset($c['top'])) {
                $d['top'] = self::lengthToCss($c['top']);
            }
            if (isset($c['bottom'])) {
                $d['bottom'] = self::lengthToCss($c['bottom']);
            }
            if (! empty($c['centerX'])) {
                $d['left'] = '50%';
                $transforms[] = 'translateX(-50%)';
            }
            if (! empty($c['centerY'])) {
                $d['top'] = '50%';
                $transforms[] = 'translateY(-50%)';
            }
            if (count($transforms)) {
                $d['transform'] = implode(' ', $transforms);
            }
        }
        if (isset($c['width'])) {
            $d['width'] = self::sizeToCss($c['width']);
        }
        if (isset($c['height'])) {
            $d['height'] = self::sizeToCss($c['height']);
        }

        return $d;
    }

    /**
     * @param  array<string,mixed>  $doc
     * @param  array<string,mixed>  $node
     */
    private static function isParentFree(array $doc, array $node): bool
    {
        if (($node['parent'] ?? null) === null) {
            return false;
        }
        $parent = $doc['nodes'][$node['parent']] ?? null;
        if ($parent === null) {
            return true;
        }
        $layout = $parent['layout'] ?? null;

        return $layout === null || $layout === 'free';
    }

    /**
     * @param  array<string,string>  $decls
     */
    private static function serializeRule(string $selector, array $decls, string $indent): string
    {
        $props = array_keys($decls);
        sort($props, SORT_STRING);
        if (count($props) === 0) {
            return '';
        }
        $lines = [];
        foreach ($props as $p) {
            $lines[] = "{$indent}  {$p}: {$decls[$p]};";
        }
        $body = implode("\n", $lines);

        return "{$indent}{$selector} {\n{$body}\n{$indent}}";
    }

    /** Mirror JS `String(number)` for the clean authored values the schema allows. */
    private static function num(mixed $n): string
    {
        if (is_int($n)) {
            return (string) $n;
        }
        if (is_float($n) && is_finite($n) && $n === floor($n)) {
            return (string) (int) $n;
        }

        return (string) $n;
    }
}
