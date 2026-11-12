<?php

declare(strict_types=1);

namespace FancyCms\Data;

use FancyCms\Document\Ordering;
use FancyCms\Render\JsValue;
use stdClass;

/**
 * Page inputs: the values a page's `input` nodes hold, and how they travel in a
 * query string. The PHP twin of fancy-cms-ui's render/inputs.ts, with identical
 * results (tests/fixtures/inputs.json is the shared spec).
 *
 * An input's value is part of the data-context request. A host reads it from
 * the request, fetches, and renders with both:
 *
 *   $inputs = PageInputs::fromQuery($doc, $request->query(), $lists);
 *   $data = $reports->for($inputs);
 *   PageRenderer::render($doc, $data, inputs: $inputs);
 *
 * A value that is not one of an input's options, a malformed date or a reversed
 * range falls back to the input's default, so a URL can never put the page in a
 * state its controls cannot show. Pass the data holding any bound option lists
 * (people, teams) so a value is checked against them; without it a value is
 * checked for its shape only.
 */
final class PageInputs
{
    public const KINDS = ['period', 'daterange', 'person', 'select', 'multiselect'];

    /**
     * What an input's `name` must look like: it becomes a query parameter, an
     * element id, and what a binding's `{"$input": "<name>"}` names (the same
     * grammar Charlotte checks those references against).
     */
    public const NAME_PATTERN = '/\A[a-z][a-z0-9-]{0,47}\z/';

    public const EMPTY_TEXT = 'No options available';

    /** The periods a `period` input offers when it declares no options of its own. The host decides what each means. */
    public const PERIOD_PRESETS = [
        ['value' => '7d', 'label' => 'Last 7 days'],
        ['value' => '30d', 'label' => 'Last 30 days'],
        ['value' => '90d', 'label' => 'Last 90 days'],
        ['value' => '12m', 'label' => 'Last 12 months'],
    ];

    private const MAX_OPTIONS = 500;

    public static function kind(mixed $kind): string
    {
        return is_string($kind) && in_array($kind, self::KINDS, true) ? $kind : 'select';
    }

    /** A real calendar date in `YYYY-MM-DD` form. */
    public static function isIsoDate(mixed $v): bool
    {
        if (! is_string($v) || preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $v, $m) !== 1) {
            return false;
        }

        // Not checkdate(): it refuses year 0000, which the JS twin accepts.
        [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        if ($month < 1 || $month > 12 || $day < 1) {
            return false;
        }
        $leap = ($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0;

        return $day <= [31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31][$month - 1];
    }

    /**
     * The options an input offers, from its resolved `options` prop. Null when
     * they are not knowable (a binding the data does not satisfy).
     *
     * @return list<array{value:string,label:string}>|null
     */
    public static function options(string $kind, mixed $raw): ?array
    {
        if ($kind === 'daterange') {
            return [];
        }
        if ($raw === null) {
            return $kind === 'period' ? self::PERIOD_PRESETS : null;
        }
        if (! is_array($raw) || ! array_is_list($raw)) {
            return null;
        }
        $out = [];
        $seen = [];
        foreach ($raw as $entry) {
            if (count($out) >= self::MAX_OPTIONS) {
                break;
            }
            $value = self::scalar($entry);
            $label = $value;
            if ($value === null && self::isRecord($entry)) {
                $value = self::scalar(self::own($entry, 'value'));
                $l = self::own($entry, 'label');
                $label = is_string($l) && $l !== '' ? $l : $value;
            }
            if ($value === null || $value === '' || isset($seen[$value])) {
                continue;
            }
            $seen[$value] = true;
            $out[] = ['value' => $value, 'label' => (string) $label];
        }

        return $out;
    }

    /**
     * The value an input holds: the raw value when it fits, else the default,
     * else the first option (or nothing).
     *
     * @param  list<array{value:string,label:string}>|null  $options
     * @return string|list<string>|array{from:string,to:string}
     */
    public static function normalize(string $kind, ?array $options, mixed $raw, mixed $fallback): string|array
    {
        $chosen = self::pick($kind, $options, $raw) ?? self::pick($kind, $options, $fallback);
        if ($chosen !== null) {
            return $chosen;
        }

        return match ($kind) {
            'daterange' => ['from' => '', 'to' => ''],
            'multiselect' => [],
            default => $options !== null && $options !== [] ? $options[0]['value'] : '',
        };
    }

    /**
     * Every input a document declares, ordered by node id. The first node (by
     * id) to use a valid name owns it.
     *
     * @return list<array{node:string,name:string,kind:string}>
     */
    public static function declared(array $doc): array
    {
        $nodes = $doc['nodes'] ?? null;
        $nodes = $nodes instanceof stdClass ? (array) $nodes : $nodes;
        if (! is_array($nodes)) {
            return [];
        }
        $ids = array_map('strval', array_keys($nodes));
        usort($ids, static fn (string $a, string $b): int => Ordering::compare($a, $b) <=> 0);
        $out = [];
        $seen = [];
        foreach ($ids as $id) {
            $node = $nodes[$id];
            if (self::own($node, 'type') !== 'input') {
                continue;
            }
            $props = self::props($node);
            $name = $props['name'] ?? null;
            if (! is_string($name) || preg_match(self::NAME_PATTERN, $name) !== 1 || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $out[] = ['node' => $id, 'name' => $name, 'kind' => self::kind($props['kind'] ?? null)];
        }

        return $out;
    }

    /**
     * The normalised value of every declared input, keyed by name.
     *
     * @param  array<string,mixed>  $raw  what the page was asked for
     * @param  array<string,mixed>  $data  the context bound option lists resolve against
     * @return array<string,string|list<string>|array{from:string,to:string}>
     */
    public static function values(array $doc, array $raw = [], array $data = []): array
    {
        $out = [];
        foreach (self::withProps($doc, $data) as [$decl, $options, $fallback]) {
            $out[$decl['name']] = self::normalize($decl['kind'], $options, array_key_exists($decl['name'], $raw) ? $raw[$decl['name']] : null, $fallback);
        }

        return $out;
    }

    /**
     * The input values a request's query asks for: `$request->query()`, or what
     * `parse_str` gives. The encoding is `name=v`, `name[]=v` per selection, and
     * `name[from]` / `name[to]`.
     */
    public static function fromQuery(array $doc, array $query, array $data = []): array
    {
        $raw = [];
        foreach (self::declared($doc) as $decl) {
            if (array_key_exists($decl['name'], $query)) {
                $raw[$decl['name']] = self::fromParam($decl['kind'], $query[$decl['name']]);
            }
        }

        return self::values($doc, $raw, $data);
    }

    /** The query string for a set of values, without the `?`. A value equal to its default is left out. */
    public static function toQuery(array $doc, array $values, array $data = []): string
    {
        $normal = self::values($doc, $values, $data);
        $defaults = self::values($doc, [], $data);
        $parts = [];
        foreach (self::declared($doc) as $decl) {
            $name = $decl['name'];
            $v = $normal[$name];
            if (json_encode($v) === json_encode($defaults[$name])) {
                continue;
            }
            if (is_string($v)) {
                $parts[] = $name.'='.rawurlencode($v);
            } elseif (array_is_list($v)) {
                foreach ($v as $item) {
                    $parts[] = $name.'[]='.rawurlencode($item);
                }
            } else {
                $parts[] = $name.'[from]='.rawurlencode($v['from']);
                $parts[] = $name.'[to]='.rawurlencode($v['to']);
            }
        }

        return implode('&', $parts);
    }

    /** A query parameter in the shape the JS twin reads off URLSearchParams. */
    private static function fromParam(string $kind, mixed $param): mixed
    {
        if ($kind === 'daterange') {
            return is_array($param) ? ['from' => is_string($param['from'] ?? null) ? $param['from'] : '', 'to' => is_string($param['to'] ?? null) ? $param['to'] : ''] : null;
        }

        return $param;
    }

    /** @return list<array{0:array{node:string,name:string,kind:string},1:list<array{value:string,label:string}>|null,2:mixed}> */
    private static function withProps(array $doc, array $data): array
    {
        $nodes = $doc['nodes'] ?? [];
        $nodes = $nodes instanceof stdClass ? (array) $nodes : $nodes;
        $out = [];
        foreach (self::declared($doc) as $decl) {
            $props = self::props($nodes[$decl['node']] ?? null);
            $out[] = [$decl, self::options($decl['kind'], self::resolve($props['options'] ?? null, $data)), self::resolve($props['default'] ?? null, $data)];
        }

        return $out;
    }

    private static function pick(string $kind, ?array $options, mixed $raw): string|array|null
    {
        if ($kind === 'daterange') {
            $from = self::own($raw, 'from');
            $to = self::own($raw, 'to');

            return self::isIsoDate($from) && self::isIsoDate($to) && strcmp($from, $to) <= 0 ? ['from' => $from, 'to' => $to] : null;
        }
        if ($kind === 'multiselect') {
            if (! is_array($raw) || ! array_is_list($raw) || count(array_filter($raw, 'is_string')) !== count($raw)) {
                return null;
            }
            if ($options === null) {
                return array_values(array_filter(array_values(array_unique($raw)), static fn (string $v): bool => $v !== ''));
            }
            $picked = array_flip($raw);

            return array_values(array_map(static fn (array $o): string => $o['value'], array_filter($options, static fn (array $o): bool => isset($picked[$o['value']]))));
        }
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        if ($options === null) {
            return $raw;
        }
        foreach ($options as $o) {
            if ($o['value'] === $raw) {
                return $raw;
            }
        }

        return null;
    }

    /** A string or a finite number as an option value (JavaScript's String(n)); anything else is not one. */
    private static function scalar(mixed $v): ?string
    {
        if (is_string($v)) {
            return $v;
        }
        if (is_int($v) || (is_float($v) && is_finite($v))) {
            return JsValue::string($v);
        }

        return null;
    }

    /** @return array<string,mixed> */
    private static function props(mixed $node): array
    {
        $props = self::own($node, 'props');
        $props = $props instanceof stdClass ? (array) $props : $props;

        return is_array($props) && ($props === [] || ! array_is_list($props)) ? $props : [];
    }

    /** A `{ $bind }` read from the data context, as the renderer reads it. */
    private static function resolve(mixed $value, array $data): mixed
    {
        $binding = $value instanceof stdClass ? (array) $value : $value;
        if (! is_array($binding) || ! is_string($binding['$bind'] ?? null)) {
            return $value;
        }
        $current = $data;
        foreach (explode('.', $binding['$bind']) as $part) {
            if ($current instanceof stdClass) {
                $current = (array) $current;
            }
            if (! is_array($current) || ! array_key_exists($part, $current)) {
                return null;
            }
            $current = $current[$part];
        }

        return $current;
    }

    private static function isRecord(mixed $v): bool
    {
        return $v instanceof stdClass || (is_array($v) && ! array_is_list($v));
    }

    private static function own(mixed $record, string $key): mixed
    {
        $array = $record instanceof stdClass ? (array) $record : $record;

        return is_array($array) && ! array_is_list($array) && array_key_exists($key, $array) ? $array[$key] : null;
    }
}
