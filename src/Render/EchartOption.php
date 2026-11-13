<?php

declare(strict_types=1);

namespace FancyCms\Render;

/**
 * The `echart` node's option: a SAFE SUBSET of an ECharts option, validated the
 * same way here and in @particle-academy/fancy-cms-ui (`validateEchartOption`),
 * against a shared fixture (tests/fixtures/echart-options.json).
 *
 * An ECharts option can carry code and markup: formatter functions, tooltip
 * HTML, rich text, extra CSS, image URLs, links. None of it may pass:
 *
 * - only the top-level keys in TOP_LEVEL, and only the series types in SERIES;
 * - no key in DENIED, at any depth;
 * - every string is free of `<` and `>`, `javascript:`, `url(` and `image://`;
 * - a `formatter` is a plain template string (`{b}: {c}`), never code;
 * - `{"$bind": path}` is a data slot wherever a value goes (validate again once
 *   resolved, with `bindings: false`);
 * - nesting stops at MAX_DEPTH.
 *
 * Takes JSON decoded either way: objects as stdClass or as associative arrays.
 */
final class EchartOption
{
    public const TOP_LEVEL = [
        'title', 'legend', 'grid', 'xAxis', 'yAxis', 'polar', 'radiusAxis', 'angleAxis', 'radar', 'dataZoom', 'visualMap',
        'tooltip', 'axisPointer', 'dataset', 'series', 'color', 'backgroundColor', 'textStyle', 'animation', 'animationDuration',
        'animationEasing', 'animationDelay', 'animationDurationUpdate', 'animationEasingUpdate', 'animationThreshold', 'darkMode',
        'calendar', 'parallel', 'parallelAxis', 'singleAxis', 'useUTC',
    ];

    public const SERIES = [
        'line', 'bar', 'pie', 'scatter', 'effectScatter', 'radar', 'tree', 'treemap', 'sunburst', 'boxplot', 'candlestick',
        'heatmap', 'parallel', 'graph', 'sankey', 'funnel', 'gauge', 'pictorialBar', 'themeRiver',
    ];

    public const DENIED = [
        'rich', 'extraCssText', 'link', 'sublink', 'target', 'subtarget', 'image', 'appendTo', 'appendToBody', 'renderMode',
        'className', 'graphic', 'toolbox', 'geo', 'map', 'html', 'constructor', 'prototype', '__proto__',
    ];

    private const MAX_DEPTH = 12;

    private const MAX_NODES = 20000;

    private const BINDING_KEYS = ['$bind', '$source', '$params', '$scope'];

    private const CODE = '/\bfunction\b|=>|\$\{|\beval\b|\bnew\s|\breturn\b/';

    /** @var list<string> */
    private array $errors = [];

    private int $nodes = 0;

    private function __construct(private readonly bool $bindings) {}

    /**
     * The JSON paths the option breaks a rule at, in document order; empty when
     * it is accepted.
     *
     * @return list<string>
     */
    public static function errors(mixed $option, bool $bindings = true): array
    {
        $v = new self($bindings);
        $object = self::object($option);
        if ($object === null) {
            return ['$'];
        }
        foreach ($object as $key => $value) {
            $key = (string) $key;
            $at = '$.'.$key;
            if (! in_array($key, self::TOP_LEVEL, true)) {
                $v->errors[] = $at;

                continue;
            }
            if ($key === 'series') {
                $series = self::object($value);
                if (self::isList($value)) {
                    foreach (array_values((array) $value) as $i => $item) {
                        $v->seriesItem($item, $at.'['.$i.']');
                    }
                } elseif ($series !== null && ! array_key_exists('$bind', $series)) {
                    $v->seriesItem($value, $at);
                } else {
                    $v->walk($value, $at, 1, $key);
                }

                continue;
            }
            $v->walk($value, $at, 1, $key);
        }

        return $v->errors;
    }

    private static function isList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value);
    }

    /** @return array<string,mixed>|null an object's entries, or null when it is not an object */
    private static function object(mixed $value): ?array
    {
        if ($value instanceof \stdClass) {
            return get_object_vars($value);
        }
        if (is_array($value) && ($value !== [] && ! array_is_list($value))) {
            return $value;
        }

        return null;
    }

    private static function unsafeString(string $s): bool
    {
        $lower = strtolower($s);

        return str_contains($s, '<') || str_contains($s, '>') || str_contains($lower, 'javascript:') || str_contains($lower, 'url(') || str_starts_with($lower, 'image://');
    }

    private function seriesItem(mixed $item, string $path): void
    {
        $object = self::object($item);
        if ($object === null) {
            $this->walk($item, $path, 2, null);

            return;
        }
        $type = $object['type'] ?? null;
        if (! is_string($type) || ! in_array($type, self::SERIES, true)) {
            $this->errors[] = $path.'.type';
        }
        foreach ($object as $key => $value) {
            $key = (string) $key;
            if ($key === 'type') {
                continue;
            }
            $at = $path.'.'.$key;
            if (in_array($key, self::DENIED, true)) {
                $this->errors[] = $at;

                continue;
            }
            $this->walk($value, $at, 3, $key);
        }
    }

    private function walk(mixed $value, string $path, int $depth, ?string $key): void
    {
        if (++$this->nodes > self::MAX_NODES) {
            if (end($this->errors) !== '$') {
                $this->errors[] = '$';
            }

            return;
        }
        if ($depth > self::MAX_DEPTH) {
            $this->errors[] = $path;

            return;
        }
        if ($key === 'formatter') {
            if (! is_string($value) || self::unsafeString($value) || preg_match(self::CODE, $value) === 1) {
                $this->errors[] = $path;
            }

            return;
        }
        if (is_string($value)) {
            if (self::unsafeString($value)) {
                $this->errors[] = $path;
            }

            return;
        }
        if (is_int($value)) {
            return;
        }
        if (is_float($value)) {
            if (! is_finite($value)) {
                $this->errors[] = $path;
            }

            return;
        }
        if ($value === null || is_bool($value)) {
            return;
        }
        if (self::isList($value) || $value === []) {
            foreach ($value as $i => $item) {
                $this->walk($item, $path.'['.$i.']', $depth + 1, null);
            }

            return;
        }
        $object = self::object($value);
        if ($object === null) {
            $this->errors[] = $path;

            return;
        }
        if (array_key_exists('$bind', $object)) {
            $ok = $this->bindings && is_string($object['$bind']) && array_diff(array_map('strval', array_keys($object)), self::BINDING_KEYS) === [];
            if (! $ok) {
                $this->errors[] = $path;
            }

            return;
        }
        foreach ($object as $k => $v) {
            $k = (string) $k;
            $at = $path.'.'.$k;
            if (in_array($k, self::DENIED, true)) {
                $this->errors[] = $at;

                continue;
            }
            $this->walk($v, $at, $depth + 1, $k);
        }
    }
}
