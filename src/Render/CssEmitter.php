<?php

declare(strict_types=1);

namespace FancyCms\Render;

use FancyCms\Document\Ordering;
use stdClass;

/** Pure PHP twin of fancy-cms-ui/src/render/css.ts; canonical parity is the gate. */
final class CssEmitter
{
    /** Fixed first-party UI 0.9 preamble; LF bytes are platform-independent. */
    public const BASE_CSS = "/* fancy-cms base */\n\n".
        ":where([data-cms]) {\n  box-sizing: border-box;\n}\n\n".
        ":where(h1, h2, h3, h4, h5, h6, p, ul, ol)[data-cms] {\n  margin: 0;\n}\n\n".
        ":where(ul, ol)[data-cms] {\n  padding-left: 1.25em;\n}\n\n".
        ":where(a[data-cms]) {\n  color: inherit;\n}\n\n".
        ":where([data-react-fancy-icon]) {\n  display: inline-flex;\n  align-items: center;\n  justify-content: center;\n  flex-shrink: 0;\n  width: 1em;\n  height: 1em;\n  vertical-align: middle;\n}\n\n".
        ":where([data-cms] > img) {\n  display: block;\n  height: 100%;\n  object-fit: cover;\n  width: 100%;\n}\n\n".
        ":where([data-cms] > img[data-cms-fit=\"contain\"]) {\n  object-fit: contain;\n}\n\n".
        ":where([data-cms] > img[data-cms-fit=\"fill\"]) {\n  object-fit: fill;\n}\n\n".
        ":where([data-cms] > img[data-cms-fit=\"none\"]) {\n  object-fit: none;\n}\n\n".
        ":where([data-cms] > img[data-cms-fit=\"scale-down\"]) {\n  object-fit: scale-down;\n}\n\n".
        ":where([data-cms-button]) {\n  align-items: center;\n  border: 1px solid var(--cms-color-primary, #7c3aed);\n  border-radius: 8px;\n  cursor: pointer;\n  display: inline-flex;\n  font-weight: 600;\n  gap: 6px;\n  justify-content: center;\n  line-height: 1.2;\n  padding: 10px 18px;\n  text-decoration: none;\n}\n\n".
        ":where([data-cms-button=\"primary\"]) {\n  background: var(--cms-color-primary, #7c3aed);\n  color: #ffffff;\n}\n\n".
        ":where([data-cms-button=\"outline\"]) {\n  background: transparent;\n  color: var(--cms-color-primary, #7c3aed);\n}\n\n".
        ":where([data-cms-button=\"ghost\"]) {\n  background: transparent;\n  border-color: currentColor;\n  color: inherit;\n}\n\n".
        ":where([data-cms-callout]) {\n  background: #3b82f614;\n  border-left: 3px solid #3b82f6;\n  border-radius: 10px;\n  padding: 12px 14px;\n}\n\n".
        ":where([data-cms-callout=\"success\"]) {\n  background: #10b98114;\n  border-left-color: #10b981;\n}\n\n".
        ":where([data-cms-callout=\"warning\"]) {\n  background: #f59e0b14;\n  border-left-color: #f59e0b;\n}\n\n".
        ":where([data-cms-callout=\"danger\"]) {\n  background: #ef444414;\n  border-left-color: #ef4444;\n}\n\n".
        ":where([data-cms] > hr) {\n  border: none;\n  border-top: 1px solid #e2e8f0;\n  margin: 0;\n}\n\n".
        ":where([data-cms] > pre) {\n  background: #0b1220;\n  border-radius: 10px;\n  color: #e2e8f0;\n  font-family: ui-monospace, monospace;\n  font-size: 13px;\n  margin: 0;\n  overflow-x: auto;\n  padding: 12px 14px;\n}";

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
            if (! is_array($node)) {
                continue;
            }
            $node['style'] = is_array($node['style'] ?? null) ? $node['style'] : [];
            $node['style']['base'] = is_array($node['style']['base'] ?? null) ? $node['style']['base'] : [];
            $selector = self::selectorFor((string) $id);
            $parentFree = self::isParentFree($doc, $node);
            $style = $node['style']['base'] ?? [];
            $base = array_replace(
                self::containerDecls($node, $style, true),
                is_array($node['constraints']['base'] ?? null) ? self::constraintDecls($node['constraints']['base'], $parentFree) : [],
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
                $style = is_array($node['style'][$bp] ?? null) ? $node['style'][$bp] : null;
                $decls = array_replace(
                    $style !== null ? self::containerDecls($node, $style, false) : [],
                    is_array($node['constraints'][$bp] ?? null) ? self::constraintDecls($node['constraints'][$bp], $parentFree) : [],
                    $style !== null ? self::styleDecls($style) : [],
                    $style !== null ? self::visibilityDecls($node, $style, false) : [],
                );
                $inner = self::serializeRule($selector, $decls, '  ');
                if ($inner !== '') {
                    $blocks[] = '@media (min-width: '.self::BREAKPOINTS[$bp]."px) {\n".$inner."\n}";
                }
            }
        }

        if ($blocks === [] && $nodes === []) {
            return '';
        }

        return implode("\n\n", [self::BASE_CSS, ...$blocks])."\n";
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
