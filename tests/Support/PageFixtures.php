<?php

declare(strict_types=1);

namespace FancyCms\Tests\Support;

final class PageFixtures
{
    public static function node(string $id, ?string $parent = null, string $order = 'V'): array
    {
        return ['id' => $id, 'type' => 'text', 'parent' => $parent, 'order' => $order, 'props' => ['content' => $id], 'style' => ['base' => []]];
    }

    public static function doc(): array
    {
        return ['id' => 'page', 'seq' => 0, 'meta' => ['title' => 'Page', 'slug' => '/', 'scrollMode' => 'smooth'], 'theme' => ['name' => 'default'], 'breakpoints' => ['base', 'md', 'lg'], 'nodes' => [
            'a' => self::node('a', null, 'A'), 'b' => self::node('b', null, 'B'),
            'child' => self::node('child', 'a'), 'grandchild' => self::node('grandchild', 'child'),
        ]];
    }

    public static function ops(): array
    {
        return [
            'insert_node' => ['t' => 'insert_node', 'node' => self::node('new')],
            'remove_node' => ['t' => 'remove_node', 'id' => 'a'],
            'move_node' => ['t' => 'move_node', 'id' => 'b', 'parent' => 'a', 'order' => 'X'],
            'set_node_props' => ['t' => 'set_node_props', 'id' => 'a', 'patch' => ['content' => null]],
            'set_layout' => ['t' => 'set_layout', 'id' => 'a', 'layout' => 'grid'],
            'set_style' => ['t' => 'set_style', 'id' => 'a', 'breakpoint' => 'md', 'patch' => ['color' => '#fff']],
            'set_constraints' => ['t' => 'set_constraints', 'id' => 'a', 'breakpoint' => 'md', 'patch' => ['left' => null]],
            'set_animation' => ['t' => 'set_animation', 'id' => 'a', 'anim' => ['id' => 'fade', 'driver' => 'enter', 'keyframes' => [['at' => 0, 'props' => ['opacity' => 0]], ['at' => 1, 'props' => ['opacity' => 1]]]]],
            'remove_animation' => ['t' => 'remove_animation', 'id' => 'a', 'animId' => 'fade'],
            'set_action' => ['t' => 'set_action', 'id' => 'a', 'index' => 0, 'action' => ['on' => 'click', 'do' => ['kind' => 'navigate', 'href' => '/next']]],
            'set_meta' => ['t' => 'set_meta', 'patch' => ['title' => 'Edited']],
            'set_theme' => ['t' => 'set_theme', 'patch' => ['accent' => null]],
            'reorder_roots' => ['t' => 'reorder_roots', 'order' => ['b', 'a']],
        ];
    }
}
