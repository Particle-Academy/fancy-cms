<?php

declare(strict_types=1);

namespace FancyCms\Document;

/** Linear, iterative safety check shared by storage, mutations and rendering. */
final class Decorative
{
    public static function propsError(array $node): ?string
    {
        $props = $node['props'] ?? [];
        if (! is_array($props) || ! array_key_exists('decorative', $props) || ($node['type'] ?? null) === 'icon') {
            return null;
        }

        return in_array($node['type'] ?? null, ['frame', 'shape'], true) && is_bool($props['decorative'])
            ? null : 'invalid decorative props';
    }

    public static function error(array $nodes): ?string
    {
        $queue = [];
        foreach ($nodes as $id => $node) {
            if (! is_array($node)) {
                continue;
            }
            if (self::propsError($node) !== null) {
                return 'invalid decorative props';
            }
            $props = is_array($node['props'] ?? null) ? $node['props'] : [];
            if (in_array($node['type'] ?? null, ['frame', 'shape'], true) && ($props['decorative'] ?? null) === true) {
                $queue[] = (string) $id;
            }
        }
        if ($queue === []) {
            return null;
        }
        $children = [];
        foreach ($nodes as $id => $node) {
            if (is_array($node) && is_string($node['parent'] ?? null)) {
                $children[$node['parent']][] = (string) $id;
            }
        }
        $seen = [];
        for ($cursor = 0; $cursor < count($queue); $cursor++) {
            $id = $queue[$cursor];
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $node = $nodes[$id];
            $props = is_array($node['props'] ?? null) ? $node['props'] : [];
            if (($node['actions'] ?? []) !== []) {
                return 'decorative subtree contains meaningful content';
            }
            if (! in_array($node['type'] ?? null, ['frame', 'shape'], true)
                && ! (($node['type'] ?? null) === 'icon' && ($props['decorative'] ?? null) === true)) {
                return 'decorative subtree contains meaningful content';
            }
            foreach ($children[$id] ?? [] as $child) {
                $queue[] = $child;
            }
        }

        return null;
    }
}
