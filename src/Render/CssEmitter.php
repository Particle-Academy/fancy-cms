<?php

declare(strict_types=1);

namespace FancyCms\Render;

use FancyCms\Document\Ordering;
use stdClass;

/** Pure PHP twin of fancy-cms-ui/src/render/css.ts; canonical parity is the gate. */
final class CssEmitter
{
    private const BREAKPOINTS = ['base' => 0, 'md' => 768, 'lg' => 1024];

    private const ALIGN = ['start' => 'flex-start', 'center' => 'center', 'end' => 'flex-end', 'stretch' => 'stretch'];

    private const JUSTIFY = ['start' => 'flex-start', 'center' => 'center', 'end' => 'flex-end', 'between' => 'space-between', 'around' => 'space-around'];

    public static function emit(array $doc): string
    {
        if (! is_array($doc['nodes'] ?? null)) {
            return '';
        }
        $blocks = [];
        $theme = self::serializeRule(':root', self::themeDecls($doc['theme'] ?? []), '');
        if ($theme !== '') {
            $blocks[] = $theme;
        }
        $nodes = $doc['nodes'];
        $ids = array_keys($nodes);
        usort($ids, static fn ($a, $b): int => Ordering::compare((string) $a, (string) $b));
        foreach ($ids as $id) {
            $node = $nodes[$id];
            $selector = self::selectorFor((string) $id);
            $parentFree = self::isParentFree($doc, $node);
            $style = $node['style']['base'] ?? [];
            $base = array_replace(
                self::containerDecls($node, $style, true),
                isset($node['constraints']['base']) ? self::constraintDecls($node['constraints']['base'], $parentFree) : [],
                self::styleDecls($style),
                self::visibilityDecls($node, $style, true),
            );
            $rule = self::serializeRule($selector, $base, '');
            if ($rule !== '') {
                $blocks[] = $rule;
            }
            foreach ($doc['breakpoints'] ?? [] as $bp) {
                if ($bp === 'base' || ! array_key_exists($bp, self::BREAKPOINTS)) {
                    continue;
                }
                $style = $node['style'][$bp] ?? null;
                $decls = array_replace(
                    $style !== null ? self::containerDecls($node, $style, false) : [],
                    isset($node['constraints'][$bp]) ? self::constraintDecls($node['constraints'][$bp], $parentFree) : [],
                    $style !== null ? self::styleDecls($style) : [],
                    $style !== null ? self::visibilityDecls($node, $style, false) : [],
                );
                $inner = self::serializeRule($selector, $decls, '  ');
                if ($inner !== '') {
                    $blocks[] = '@media (min-width: '.self::BREAKPOINTS[$bp]."px) {\n".$inner."\n}";
                }
            }
        }

        return implode("\n\n", $blocks).($blocks !== [] ? "\n" : '');
    }

    private static function selectorFor(string $id): string
    {
        return '[data-cms="'.self::cssString($id).'"]';
    }

    public static function cssString(string $value): string
    {
        $out = '';
        for ($i = 0, $n = strlen($value); $i < $n; $i++) {
            $ch = $value[$i];
            $code = ord($ch);
            if ($ch === '\\' || $ch === '"') {
                $out .= '\\'.$ch;
            } elseif ($code < 0x20 || $code === 0x7F || $ch === '<') {
                $out .= '\\'.dechex($code).' ';
            } else {
                $out .= $ch;
            }
        }

        return $out;
    }

    /** Identical quote/parenthesis state machine to JS; not a CSS parser. */
    public static function safeCssValue(string $value): ?string
    {
        if (strpbrk($value, '{}<') !== false) {
            return null;
        }
        $depth = 0;
        $quote = null;
        for ($i = 0, $n = strlen($value); $i < $n; $i++) {
            $ch = $value[$i];
            if ($quote !== null) {
                if ($ch === $quote) {
                    $quote = null;
                }
            } elseif ($ch === '"' || $ch === "'") {
                $quote = $ch;
            } elseif ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($ch === ';' && $depth === 0) {
                return null;
            }
        }

        return $value;
    }

    private static function put(array &$decls, string $prop, mixed $value): void
    {
        $safe = self::safeCssValue(JsValue::string($value));
        if ($safe !== null) {
            $decls[$prop] = $safe;
        }
    }

    private static function lengthToCss(mixed $length): ?string
    {
        if (! is_array($length) && ! $length instanceof stdClass) {
            return null;
        }
        $length = (array) $length;
        if (self::numberToCss($length['value'] ?? null) === null || ! in_array($length['unit'] ?? null, ['px', '%', 'vw', 'vh', 'rem', 'fr'], true)) {
            return null;
        }

        return JsValue::string($length['value']).$length['unit'];
    }

    private static function sizeToCss(mixed $size): ?string
    {
        return match ($size) {
            'fill' => '100%', 'hug' => 'auto', default => self::lengthToCss($size)
        };
    }

    private static function numberToCss(mixed $value): ?string
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) ? JsValue::string($value) : null;
    }

    private static function putLen(array &$decls, array $props, mixed $length): void
    {
        $value = self::lengthToCss($length);
        if ($value !== null) {
            foreach ($props as $prop) {
                $decls[$prop] = $value;
            }
        }
    }

    private static function styleDecls(array $s): array
    {
        $d = [];
        foreach (['padding' => 'padding', 'margin' => 'margin', 'radius' => 'border-radius', 'maxWidth' => 'max-width', 'minHeight' => 'min-height'] as $key => $prop) {
            self::putLen($d, [$prop], $s[$key] ?? null);
        }
        foreach (['paddingX' => ['padding-left', 'padding-right'], 'paddingY' => ['padding-top', 'padding-bottom'], 'marginX' => ['margin-left', 'margin-right'], 'marginY' => ['margin-top', 'margin-bottom']] as $key => $props) {
            if ($key === 'marginX' && ($s[$key] ?? null) === 'auto') {
                foreach ($props as $prop) {
                    $d[$prop] = 'auto';
                }
            } else {
                self::putLen($d, $props, $s[$key] ?? null);
            }
        }
        if (is_string($s['aspectRatio'] ?? null)) {
            self::put($d, 'aspect-ratio', $s['aspectRatio']);
        }
        if (($s['sticky'] ?? null) === true) {
            $d['position'] = 'sticky';
            $d['top'] = '0';
        }
        if ((is_int($s['zIndex'] ?? null) || is_float($s['zIndex'] ?? null)) && is_finite((float) $s['zIndex'])) {
            $v = (float) $s['zIndex'];
            $d['z-index'] = JsValue::number($v < 0 ? ceil($v) : floor($v));
        }
        foreach (['overflow' => ['overflow', ['visible', 'hidden', 'auto']], 'backgroundSize' => ['background-size', ['cover', 'contain', 'auto']], 'textTransform' => ['text-transform', ['none', 'uppercase', 'lowercase', 'capitalize']], 'textAlign' => ['text-align', ['left', 'center', 'right', 'justify']]] as $key => [$prop, $allowed]) {
            if (isset($s[$key]) && in_array($s[$key], $allowed, true)) {
                $d[$prop] = $s[$key];
            }
        }
        foreach (['background' => 'background', 'backgroundPosition' => 'background-position', 'color' => 'color', 'fontFamily' => 'font-family', 'border' => 'border', 'boxShadow' => 'box-shadow', 'transform' => 'transform', 'filter' => 'filter'] as $key => $prop) {
            if (is_string($s[$key] ?? null)) {
                self::put($d, $prop, $s[$key]);
            }
        }
        foreach (['opacity' => 'opacity', 'fontWeight' => 'font-weight', 'lineHeight' => 'line-height'] as $key => $prop) {
            $value = self::numberToCss($s[$key] ?? null);
            if ($value !== null) {
                $d[$prop] = $value;
            }
        }
        foreach (['fontSize' => 'font-size', 'letterSpacing' => 'letter-spacing', 'gap' => 'gap'] as $key => $prop) {
            self::putLen($d, [$prop], $s[$key] ?? null);
        }
        if (is_string($s['align'] ?? null) && array_key_exists($s['align'], self::ALIGN)) {
            $d['align-items'] = self::ALIGN[$s['align']];
        }
        if (is_string($s['justify'] ?? null) && array_key_exists($s['justify'], self::JUSTIFY)) {
            $d['justify-content'] = self::JUSTIFY[$s['justify']];
        }

        return $d;
    }

    private static function shownDisplay(array $node): string
    {
        return match ($node['layout'] ?? null) {
            'stack' => 'flex', 'grid' => 'grid', default => 'block'
        };
    }

    private static function containerDecls(array $node, array $style, bool $base): array
    {
        $d = [];
        switch ($node['layout'] ?? null) {
            case 'stack':
                if ($base) {
                    $d['display'] = 'flex';
                }
                $direction = in_array($style['direction'] ?? null, ['row', 'column'], true) ? $style['direction'] : null;
                if ($base || $direction !== null) {
                    $d['flex-direction'] = $direction ?? 'column';
                }
                if (array_key_exists('wrap', $style)) {
                    $d['flex-wrap'] = JsValue::truthy($style['wrap']) ? 'wrap' : 'nowrap';
                }
                break;
            case 'grid':
                if ($base) {
                    $d['display'] = 'grid';
                }
                $columns = null;
                $min = self::lengthToCss($style['columnMin'] ?? null);
                if ($min !== null) {
                    $columns = 'repeat(auto-fit, minmax(min('.$min.', 100%), 1fr))';
                } elseif (self::numberToCss($style['columns'] ?? null) !== null) {
                    $v = (float) $style['columns'];
                    $columns = 'repeat('.JsValue::number(max(1, $v < 0 ? ceil($v) : floor($v))).', 1fr)';
                }
                if ($base || $columns !== null) {
                    $d['grid-template-columns'] = $columns ?? 'repeat(1, 1fr)';
                }
                break;
            case 'free':
                if ($base) {
                    $d['position'] = 'relative';
                }
                break;
        }

        return $d;
    }

    private static function visibilityDecls(array $node, array $style, bool $base): array
    {
        if (($style['hidden'] ?? null) === true) {
            return ['display' => 'none'];
        }
        if (($style['hidden'] ?? null) === false && ! $base) {
            return ['display' => self::shownDisplay($node)];
        }

        return [];
    }

    private static function themeDecls(mixed $theme): array
    {
        if (! is_array($theme) && ! (is_object($theme) && get_class($theme) === stdClass::class)) {
            return [];
        }
        $d = [];
        $walk = static function ($value, array $path, int $depth) use (&$walk, &$d): void {
            if ($depth > 64) {
                return;
            }
            if (is_string($value) || ((is_int($value) || is_float($value)) && is_finite((float) $value))) {
                $parts = [];
                foreach ($path as $p) {
                    // These two Unicode uppercase forms lower to ASCII in JS.
                    $key = preg_replace('/[^a-z0-9-]/', '', strtolower(str_replace(["\u{0130}", "\u{212A}"], ['i', 'k'], (string) $p)));
                    if ($key !== '') {
                        $parts[] = $key;
                    }
                }
                if ($parts !== []) {
                    self::put($d, '--cms-'.implode('-', $parts), $value);
                }
            } elseif ((is_object($value) && get_class($value) === stdClass::class) || (is_array($value) && ! array_is_list($value))) {
                foreach (JsValue::entries($value) as $key => $child) {
                    $walk($child, [...$path, $key], $depth + 1);
                }
            }
        };
        foreach (JsValue::entries($theme) as $key => $value) {
            if ($key !== 'name') {
                $walk($value, [$key], 0);
            }
        }

        return $d;
    }

    private static function constraintDecls(array $c, bool $parentFree): array
    {
        $d = [];
        $transforms = [];
        if ($parentFree) {
            $d['position'] = 'absolute';
            foreach (['left', 'right', 'top', 'bottom'] as $key) {
                self::putLen($d, [$key], $c[$key] ?? null);
            }
            if (JsValue::truthy($c['centerX'] ?? null)) {
                $d['left'] = '50%';
                $transforms[] = 'translateX(-50%)';
            }
            if (JsValue::truthy($c['centerY'] ?? null)) {
                $d['top'] = '50%';
                $transforms[] = 'translateY(-50%)';
            }
            if ($transforms !== []) {
                $d['transform'] = implode(' ', $transforms);
            }
        }
        foreach (['width', 'height'] as $key) {
            $value = self::sizeToCss($c[$key] ?? null);
            if ($value !== null) {
                $d[$key] = $value;
            }
        }

        return $d;
    }

    private static function isParentFree(array $doc, array $node): bool
    {
        if (($node['parent'] ?? null) === null) {
            return false;
        }
        $parent = $doc['nodes'][$node['parent']] ?? null;

        return $parent === null || ! array_key_exists('layout', $parent) || $parent['layout'] === 'free';
    }

    private static function serializeRule(string $selector, array $decls, string $indent): string
    {
        if ($decls === []) {
            return '';
        }
        ksort($decls, SORT_STRING);
        $lines = [];
        foreach ($decls as $prop => $value) {
            $lines[] = $indent.'  '.$prop.': '.$value.';';
        }

        return $indent.$selector." {\n".implode("\n", $lines)."\n".$indent.'}';
    }
}
