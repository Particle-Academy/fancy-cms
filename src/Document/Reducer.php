<?php

declare(strict_types=1);

namespace FancyCms\Document;

/**
 * The array-based PHP twin of fancy-cms-ui/document/reduce.ts.
 * Structural invalidity is handled at PageOp/PageDoc boundaries. An operation
 * that cannot apply is a no-op, including its seq, just as in JavaScript.
 */
final class Reducer
{
    public static function reduce(array $doc, array $op, array $opts = []): array
    {
        $invalid = static function (string $reason) use ($doc, $op, $opts): array {
            if (isset($opts['onInvalid'])) {
                ($opts['onInvalid'])($op, $reason);
            }

            return $doc;
        };
        $type = $op['t'] ?? null;
        $nodes = $doc['nodes'] ?? [];
        $id = $op['id'] ?? '';
        $node = $nodes[$id] ?? null;
        switch ($type) {
            case 'insert_node':
                $new = $op['node'];
                if (isset($nodes[$new['id']])) {
                    return $invalid('node id already exists');
                }
                if ($new['parent'] !== null && ! isset($nodes[$new['parent']])) {
                    return $invalid('parent not found');
                }
                if (($new['type'] ?? null) === 'icon' && ! Icons::validProps($new['props'] ?? null)) {
                    return $invalid('invalid icon props');
                }
                if ($new['parent'] !== null && ($nodes[$new['parent']]['type'] ?? null) === 'icon') {
                    return $invalid('icons cannot have children');
                }
                $doc['nodes'][$new['id']] = $new;
                break;
            case 'remove_node':
                if ($node === null) {
                    return $invalid('node not found');
                }
                foreach ([$id, ...self::descendantIds($doc, $id)] as $dead) {
                    unset($doc['nodes'][$dead]);
                }
                break;
            case 'move_node':
                if ($node === null) {
                    return $invalid('node not found');
                }
                if ($op['parent'] === $id) {
                    return $invalid('cannot parent a node to itself');
                }
                if ($op['parent'] !== null && ! isset($nodes[$op['parent']])) {
                    return $invalid('new parent not found');
                }
                if ($op['parent'] !== null && in_array($op['parent'], self::descendantIds($doc, $id), true)) {
                    return $invalid('move would create a cycle');
                }
                if ($op['parent'] !== null && ($nodes[$op['parent']]['type'] ?? null) === 'icon') {
                    return $invalid('icons cannot have children');
                }
                $doc['nodes'][$id]['parent'] = $op['parent'];
                $doc['nodes'][$id]['order'] = $op['order'];
                break;
            case 'set_node_props':
                if ($node === null) {
                    return $invalid('node not found');
                }
                $props = array_replace($node['props'], $op['patch']);
                if ($node['type'] === 'icon' && ! Icons::validProps($props)) {
                    return $invalid('invalid icon props');
                }
                $doc['nodes'][$id]['props'] = $props;
                break;
            case 'set_layout':
                if ($node === null) {
                    return $invalid('node not found');
                }
                if (array_key_exists('layout', $op)) {
                    $doc['nodes'][$id]['layout'] = $op['layout'];
                } else {
                    unset($doc['nodes'][$id]['layout']);
                }
                break;
            case 'set_style':
                if ($node === null) {
                    return $invalid('node not found');
                }
                $doc['nodes'][$id]['style'][$op['breakpoint']] = array_replace($node['style'][$op['breakpoint']] ?? [], $op['patch']);
                break;
            case 'set_constraints':
                if ($node === null) {
                    return $invalid('node not found');
                }
                $base = $node['constraints'] ?? ['base' => ['width' => 'hug', 'height' => 'hug']];
                $base[$op['breakpoint']] = array_replace($base[$op['breakpoint']] ?? [], $op['patch']);
                $doc['nodes'][$id]['constraints'] = $base;
                break;
            case 'set_animation':
                if ($node === null) {
                    return $invalid('node not found');
                }
                $list = $node['animations'] ?? [];
                $found = false;
                foreach ($list as $i => $anim) {
                    if (! $found && $anim['id'] === $op['anim']['id']) {
                        $list[$i] = $op['anim'];
                        $found = true;
                    }
                }
                if (! $found) {
                    $list[] = $op['anim'];
                }
                $doc['nodes'][$id]['animations'] = $list;
                break;
            case 'remove_animation':
                if ($node === null) {
                    return $invalid('node not found');
                }
                $doc['nodes'][$id]['animations'] = array_values(array_filter($node['animations'] ?? [], static fn (array $a): bool => $a['id'] !== $op['animId']));
                break;
            case 'set_action':
                if ($node === null) {
                    return $invalid('node not found');
                }
                $list = $node['actions'] ?? [];
                if ($op['action'] === null) {
                    $list = array_values(array_filter($list, static fn ($a, $i): bool => $i != $op['index'], ARRAY_FILTER_USE_BOTH));
                } elseif ($op['index'] >= count($list)) {
                    $list[] = $op['action'];
                } else {
                    foreach ($list as $i => $action) {
                        if ($i == $op['index']) {
                            $list[$i] = $op['action'];
                        }
                    }
                }
                $doc['nodes'][$id]['actions'] = $list;
                break;
            case 'set_meta':
                $doc['meta'] = array_replace($doc['meta'], $op['patch']);
                break;
            case 'set_theme':
                $doc['theme'] = array_replace($doc['theme'], $op['patch']);
                break;
            case 'reorder_roots':
                $roots = self::rootIds($doc);
                if (count($op['order']) !== count($roots) || count(array_unique($op['order'], SORT_STRING)) !== count($op['order'])) {
                    return $invalid('reorder must be a permutation of the current roots');
                }
                foreach ($op['order'] as $root) {
                    if (! in_array($root, $roots, true)) {
                        return $invalid('reorder must be a permutation of the current roots');
                    }
                }
                $prev = null;
                foreach ($op['order'] as $root) {
                    $doc['nodes'][$root]['order'] = $prev = Ordering::keyBetween($prev, null);
                }
                break;
            default:
                return $invalid('unknown op "'.(is_string($type) ? $type : '').'"');
        }
        if (in_array($type, ['insert_node', 'move_node', 'set_node_props', 'set_action'], true)
            && ($decorationError = Decorative::error($doc['nodes'])) !== null) {
            return $invalid($decorationError);
        }
        $doc['seq']++;

        return $doc;
    }

    public static function reduceAll(array $doc, array $ops, array $opts = []): array
    {
        foreach ($ops as $op) {
            $doc = self::reduce($doc, $op, $opts);
        }

        return $doc;
    }

    public static function childrenOf(array $doc, ?string $parent): array
    {
        $out = array_values(array_filter($doc['nodes'] ?? [], static fn (array $n): bool => $n['parent'] === $parent));
        usort($out, static fn (array $a, array $b): int => Ordering::compare($a['order'], $b['order']) ?: Ordering::compare($a['id'], $b['id']));

        return $out;
    }

    public static function rootIds(array $doc): array
    {
        return array_column(self::childrenOf($doc, null), 'id');
    }

    public static function descendantIds(array $doc, string $id): array
    {
        $adjacent = [];
        foreach ($doc['nodes'] ?? [] as $node) {
            if ($node['parent'] !== null) {
                $adjacent[$node['parent']][] = $node;
            }
        }
        foreach ($adjacent as &$siblings) {
            usort($siblings, static fn (array $a, array $b): int => Ordering::compare($a['order'], $b['order']) ?: Ordering::compare($a['id'], $b['id']));
        }
        unset($siblings);
        $out = [];
        $stack = [$id];
        $seen = [$id => true];
        while ($stack !== []) {
            foreach ($adjacent[array_pop($stack)] ?? [] as $child) {
                if (isset($seen[$child['id']])) {
                    continue;
                }
                $seen[$child['id']] = true;
                $out[] = $child['id'];
                $stack[] = $child['id'];
            }
        }

        return $out;
    }

    /**
     * JSON-wire inverses. JS undefined prior values disappear on the wire;
     * this deliberately does not add deletion semantics. JS currently has NO
     * inverses for constraints, animation/removal or action operations.
     */
    public static function invert(array $doc, array $op): array
    {
        $id = $op['id'] ?? '';
        $node = $doc['nodes'][$id] ?? null;
        switch ($op['t'] ?? null) {
            case 'insert_node': return [['t' => 'remove_node', 'id' => $op['node']['id']]];
            case 'remove_node':
                if ($node === null) {
                    return [];
                }
                $ops = [];
                foreach ([$id, ...self::descendantIds($doc, $id)] as $child) {
                    $ops[] = ['t' => 'insert_node', 'node' => $doc['nodes'][$child]];
                }
                if ($node['parent'] === null) {
                    $ops[] = ['t' => 'reorder_roots', 'order' => self::rootIds($doc)];
                }

                return $ops;
            case 'move_node':
                if ($node === null) {
                    return [];
                }
                $ops = [['t' => 'move_node', 'id' => $id, 'parent' => $node['parent'], 'order' => $node['order']]];
                if ($node['parent'] === null) {
                    $ops[] = ['t' => 'reorder_roots', 'order' => self::rootIds($doc)];
                }

                return $ops;
            case 'set_node_props':
                if ($node !== null && in_array($node['type'], ['frame', 'shape'], true)
                    && array_key_exists('decorative', $op['patch']) && ! array_key_exists('decorative', $node['props'])) {
                    return [['t' => 'remove_node', 'id' => $id], ...array_map(
                        static fn ($child): array => ['t' => 'insert_node', 'node' => $doc['nodes'][$child]],
                        [$id, ...self::descendantIds($doc, $id)]
                    )];
                }
                if ($node !== null && $node['type'] === 'icon' && array_diff(array_keys($op['patch']), array_keys($node['props'])) !== []) {
                    return [['t' => 'remove_node', 'id' => $id], ['t' => 'insert_node', 'node' => $node]];
                }

                return $node === null ? [] : [['t' => 'set_node_props', 'id' => $id, 'patch' => self::pick($node['props'], array_keys($op['patch']))]];
            case 'set_layout':
                if ($node === null) {
                    return [];
                }
                $back = ['t' => 'set_layout', 'id' => $id];
                if (array_key_exists('layout', $node)) {
                    $back['layout'] = $node['layout'];
                }

                return [$back];
            case 'set_style':
                return $node === null ? [] : [['t' => 'set_style', 'id' => $id, 'breakpoint' => $op['breakpoint'], 'patch' => self::pick($node['style'][$op['breakpoint']] ?? [], array_keys($op['patch']))]];
            case 'set_meta': return [['t' => 'set_meta', 'patch' => self::pick($doc['meta'], array_keys($op['patch']))]];
            case 'set_theme': return [['t' => 'set_theme', 'patch' => self::pick($doc['theme'], array_keys($op['patch']))]];
            case 'reorder_roots': return [['t' => 'reorder_roots', 'order' => self::rootIds($doc)]];
            default: return [];
        }
    }

    private static function pick(array $record, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $record)) {
                $out[$key] = $record[$key];
            }
        }

        return $out;
    }
}
