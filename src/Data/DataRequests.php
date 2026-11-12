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
        $out['expects'] = $type === null ? null : self::expectedShape($catalogue, $type, $prop);

        return $out;
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
