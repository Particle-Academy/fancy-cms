<?php

declare(strict_types=1);

namespace FancyCms\Data;

use FancyCms\Document\Ordering;
use FancyCms\Islands\IslandCatalogue;
use FancyCms\Render\JsValue;
use stdClass;

/**
 * What data a page asks its host for: every `$bind` in a document, with the
 * literal JSON Schema the island catalogue declares for that prop. The host
 * fetches what is listed and re-supplies it as `$data` to PageRenderer::render,
 * the argument that already exists. Page inputs (phase 2) and a host data
 * catalogue (phase 3) build on this list.
 *
 * Twin of fancy-cms-ui's `dataRequests()`; the two produce identical output
 * (tests/fixtures/infographics.json).
 */
final class DataRequests
{
    private const BINDING_REF = '#/$defs/Binding';

    /**
     * @param  array<string,mixed>  $doc
     * @return list<array{node:string,prop:string,path:string,source?:list<string|int|float>,expects:mixed}>
     */
    public static function of(array $doc, ?IslandCatalogue $catalogue = null): array
    {
        $catalogue ??= IslandCatalogue::builtin();
        $nodes = $doc['nodes'] ?? null;
        $nodes = $nodes instanceof stdClass ? (array) $nodes : $nodes;
        if (! is_array($nodes)) {
            return [];
        }
        $ids = array_map('strval', array_keys($nodes));
        usort($ids, static fn (string $a, string $b): int => Ordering::compare($a, $b) <=> 0);
        $out = [];
        foreach ($ids as $id) {
            $node = $nodes[$id];
            $node = $node instanceof stdClass ? (array) $node : $node;
            if (! is_array($node)) {
                continue;
            }
            $type = is_string($node['type'] ?? null) ? $node['type'] : null;
            $props = $node['props'] ?? [];
            $props = $props instanceof stdClass ? (array) $props : $props;
            if (is_array($props) && ($props === [] || ! array_is_list($props))) {
                foreach (JsValue::entries($props) as $key => $value) {
                    $request = self::request($id, $type, (string) $key, $value, $catalogue);
                    if ($request !== null) {
                        $out[] = $request;
                    }
                }
            }
            $repeat = $node['repeat'] ?? null;
            $repeat = $repeat instanceof stdClass ? (array) $repeat : $repeat;
            $request = self::request($id, $type, 'repeat.each', is_array($repeat) ? ($repeat['each'] ?? null) : null, $catalogue);
            if ($request !== null) {
                $out[] = $request;
            }
        }

        return $out;
    }

    /** The literal (non-binding) schema the catalogue declares for `$type`'s `$prop`. */
    public static function expectedShape(IslandCatalogue $catalogue, string $type, string $prop): mixed
    {
        $definition = $catalogue->definition($type);
        if ($definition === null) {
            return null;
        }
        $properties = self::get($definition['props'], 'properties');
        $schema = self::get($properties, $prop, $found);

        return $found ? self::deref($schema, $catalogue->toArray()['$defs'], 0) : null;
    }

    private static function request(string $id, ?string $type, string $prop, mixed $binding, IslandCatalogue $catalogue): ?array
    {
        $b = $binding instanceof stdClass ? (array) $binding : $binding;
        if (! is_array($b) || ! is_string($b['$bind'] ?? null)) {
            return null;
        }
        $out = ['node' => $id, 'prop' => $prop, 'path' => $b['$bind']];
        $source = $b['$source'] ?? null;
        if (is_array($source) && $source !== [] && array_is_list($source) && count(array_filter($source, static fn ($s): bool => is_string($s) || is_int($s) || is_float($s))) === count($source)) {
            $out['source'] = $source;
        }
        foreach (['$params' => 'params', '$scope' => 'scope'] as $key => $name) {
            if (array_key_exists($key, $b) && self::isRecord($b[$key])) {
                $out[$name] = $b[$key];
            }
        }
        $out['expects'] = $type === null ? null : self::expectedShape($catalogue, $type, $prop);

        return $out;
    }

    /**
     * What the host must fetch to render a document: one entry per distinct
     * `(stream, alias, params, scope)`, in the order the page first reads it.
     * The twin of fancy-cms-ui's `dataQueries()`, with identical results.
     *
     * A binding's first path segment names its query: `<stream>`, or
     * `<stream>#<alias>` to read one stream twice with different params or
     * scope. The host puts each result in the data context under `key`, so the
     * bindings resolve unchanged. Every binding of one query shares its
     * `$params` and `$scope`: one that states neither joins the first query on
     * its key that does. `{"$input": "<name>"}` values are filled from the
     * page's input values (PageInputs), null when the page has no such input.
     *
     * fancy-cms never interprets a scope. The host applies it against the
     * viewer, and only it can narrow or refuse it.
     *
     * @param  array<string,mixed>  $inputs  page input values by name, e.g. PageInputs::fromQuery()
     * @param  array<string,mixed>  $data  the context bound input options resolve against
     * @return list<array{key:string,stream:string,alias:string|null,params:array<string,mixed>,scope:array<string,mixed>,inputs:list<string>,bindings:list<array{node:string,prop:string,path:string}>}>
     */
    public static function queries(array $doc, array $inputs = [], array $data = [], ?IslandCatalogue $catalogue = null): array
    {
        $values = PageInputs::values($doc, $inputs, $data);
        $requests = self::of($doc, $catalogue);
        $nodes = $doc['nodes'] ?? [];
        $nodes = $nodes instanceof stdClass ? (array) $nodes : (is_array($nodes) ? $nodes : []);
        $queries = [];
        $later = [];
        $make = static function (string $key, array $params, array $scope, array $used, ?string $sig) use (&$queries): int {
            $hash = strpos($key, '#');
            sort($used, SORT_STRING);
            $queries[] = ['key' => $key, 'stream' => $hash === false ? $key : substr($key, 0, $hash), 'alias' => $hash === false ? null : substr($key, $hash + 1),
                'params' => $params, 'scope' => $scope, 'inputs' => array_values(array_unique($used)), 'bindings' => [], 'sig' => $sig];

            return array_key_last($queries);
        };
        $find = static function (string $key, ?string $sig, bool $anySig) use (&$queries): ?int {
            foreach ($queries as $i => $q) {
                if ($q['key'] === $key && ($anySig || $q['sig'] === $sig)) {
                    return $i;
                }
            }

            return null;
        };
        foreach ($requests as $i => $r) {
            $key = explode('.', $r['path'])[0];
            if ($key === '' || in_array($key, self::repeatVars($nodes, $r['node'], $r['prop']), true)) {
                continue;
            }
            $binding = ['node' => $r['node'], 'prop' => $r['prop'], 'path' => $r['path'], 'at' => $i];
            if (! array_key_exists('params', $r) && ! array_key_exists('scope', $r)) {
                $later[] = [$key, $binding];

                continue;
            }
            $used = [];
            $params = self::withInputs($r['params'] ?? [], $values, $used);
            $scope = self::withInputs($r['scope'] ?? [], $values, $used);
            $sig = self::canonical([$params, $scope]);
            $q = $find($key, $sig, false) ?? $make($key, $params, $scope, $used, $sig);
            $queries[$q]['bindings'][] = $binding;
        }
        foreach ($later as [$key, $binding]) {
            $q = $find($key, null, true) ?? $make($key, [], [], [], null);
            $queries[$q]['bindings'][] = $binding;
        }
        foreach ($queries as &$q) {
            usort($q['bindings'], static fn (array $a, array $b): int => $a['at'] <=> $b['at']);
        }
        unset($q);
        usort($queries, static fn (array $a, array $b): int => $a['bindings'][0]['at'] <=> $b['bindings'][0]['at']);

        return array_map(static function (array $q): array {
            unset($q['sig']);
            $q['bindings'] = array_map(static fn (array $b): array => ['node' => $b['node'], 'prop' => $b['prop'], 'path' => $b['path']], $q['bindings']);

            return $q;
        }, $queries);
    }

    /** `{"$input": name}` and nothing else. */
    private static function inputRef(mixed $v): ?string
    {
        $a = $v instanceof stdClass ? (array) $v : $v;

        return is_array($a) && array_keys($a) === ['$input'] && is_string($a['$input']) ? $a['$input'] : null;
    }

    /**
     * @param  list<string>  $used
     * @return array<string,mixed>
     */
    private static function withInputs(mixed $rec, array $values, array &$used): array
    {
        $out = [];
        foreach (($rec instanceof stdClass ? (array) $rec : (array) $rec) as $key => $value) {
            $ref = self::inputRef($value);
            if ($ref !== null && array_key_exists($ref, $values)) {
                $used[] = $ref;
            }
            $out[(string) $key] = $ref === null ? $value : ($values[$ref] ?? null);
        }

        return $out;
    }

    /** A JSON value with every object's keys sorted, for comparing two specs. */
    private static function canonical(mixed $v): string
    {
        if ($v instanceof stdClass) {
            $v = (array) $v;
            if ($v === []) {
                return '{}';
            }
        }
        if (is_array($v)) {
            if (array_is_list($v)) {
                return '['.implode(',', array_map(self::canonical(...), $v)).']';
            }
            $keys = array_map('strval', array_keys($v));
            usort($keys, static fn (string $a, string $b): int => Ordering::compare($a, $b) <=> 0);

            return '{'.implode(',', array_map(static fn (string $k): string => json_encode($k).':'.self::canonical($v[$k]), $keys)).'}';
        }

        return (string) json_encode($v);
    }

    /**
     * The repeat variables a binding can see: a repeater's `item` and `index`
     * for its descendants, and the `as` of the node's own `repeat` (for its
     * props) and of every ancestor's.
     *
     * @return list<string>
     */
    private static function repeatVars(array $nodes, string $id, string $prop): array
    {
        $vars = [];
        $node = static fn (mixed $n): array => $n instanceof stdClass ? (array) $n : (is_array($n) ? $n : []);
        $asOf = static function (array $n): ?string {
            $repeat = $n['repeat'] ?? null;
            $repeat = $repeat instanceof stdClass ? (array) $repeat : $repeat;

            return is_array($repeat) && is_string($repeat['as'] ?? null) && $repeat['as'] !== '' ? $repeat['as'] : null;
        };
        $self = $node($nodes[$id] ?? null);
        if ($prop !== 'repeat.each' && $asOf($self) !== null) {
            $vars[] = $asOf($self);
        }
        $parent = $self['parent'] ?? null;
        for ($guard = 0; is_string($parent) && array_key_exists($parent, $nodes) && $guard < 256; $guard++) {
            $p = $node($nodes[$parent]);
            if ($asOf($p) !== null) {
                $vars[] = $asOf($p);
            }
            if (($p['type'] ?? null) === 'repeater') {
                array_push($vars, 'item', 'index');
            }
            $parent = $p['parent'] ?? null;
        }

        return $vars;
    }

    private static function isRecord(mixed $v): bool
    {
        return $v instanceof stdClass || (is_array($v) && ($v === [] || ! array_is_list($v)));
    }

    private static function deref(mixed $schema, mixed $defs, int $depth): mixed
    {
        if ($depth > 8 || ! ($schema instanceof stdClass || (is_array($schema) && ! array_is_list($schema)))) {
            return null;
        }
        $ref = self::get($schema, '$ref');
        if (is_string($ref) && str_starts_with($ref, '#/$defs/')) {
            $target = self::get($defs, substr($ref, strlen('#/$defs/')), $found);

            return $found ? self::deref($target, $defs, $depth + 1) : null;
        }
        $anyOf = self::get($schema, 'anyOf');
        if (is_array($anyOf)) {
            foreach ($anyOf as $branch) {
                if (self::get($branch, '$ref') === self::BINDING_REF) {
                    continue;
                }

                return self::deref($branch, $defs, $depth + 1);
            }

            return null;
        }

        return $schema;
    }

    private static function get(mixed $object, string $key, ?bool &$found = null): mixed
    {
        $array = $object instanceof stdClass ? (array) $object : $object;
        $found = is_array($array) && array_key_exists($key, $array);

        return $found ? $array[$key] : null;
    }
}
