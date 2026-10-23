<?php

declare(strict_types=1);

namespace FancyCms\Document;

use FancyCms\Exceptions\SchemaException;

/** The same closed wire vocabulary as fancy-cms-ui/src/document/ops.ts. */
final class PageOp
{
    public const TYPES = ['insert_node', 'remove_node', 'move_node', 'set_node_props', 'set_layout', 'set_style', 'set_constraints', 'set_animation', 'remove_animation', 'set_action', 'set_meta', 'set_theme', 'reorder_roots'];

    public static function validate(mixed $op, ?array $knownTypes = null): array
    {
        $s = new Schema;
        $s->json($op);
        if ($s->errors !== []) {
            return $s->errors;
        }
        if (! $s->record($op, '$')) {
            return $s->errors;
        }
        $type = $op['t'] ?? null;
        if (! $s->enum($type, self::TYPES, '$.t')) {
            return $s->errors;
        }
        $required = match ($type) {
            'insert_node' => ['node'], 'remove_node' => ['id'], 'move_node' => ['id', 'parent', 'order'],
            'set_node_props' => ['id', 'patch'], 'set_layout' => ['id'], 'set_style', 'set_constraints' => ['id', 'breakpoint', 'patch'],
            'set_animation' => ['id', 'anim'], 'remove_animation' => ['id', 'animId'], 'set_action' => ['id', 'index', 'action'],
            'set_meta', 'set_theme' => ['patch'], 'reorder_roots' => ['order'],
        };
        $s->fields($op, ['t', ...$required], ['t', ...$required, ...($type === 'set_layout' ? ['layout'] : [])], '$');
        if (in_array('id', $required, true)) {
            $s->string($op['id'] ?? null, '$.id');
        }
        if (in_array('patch', $required, true)) {
            $s->record($op['patch'] ?? null, '$.patch');
        }
        if (in_array('breakpoint', $required, true)) {
            $s->string($op['breakpoint'] ?? null, '$.breakpoint');
        }
        switch ($type) {
            case 'set_style': $s->style($op['patch'] ?? null, '$.patch');
                break;
            case 'set_constraints': $s->constraints($op['patch'] ?? null, '$.patch');
                break;
            case 'insert_node': $s->node($op['node'] ?? null, '$.node', $knownTypes ?? PageDoc::NODE_TYPES);
                break;
            case 'move_node':
                if (($op['parent'] ?? null) !== null) {
                    $s->string($op['parent'], '$.parent');
                }
                $s->string($op['order'] ?? null, '$.order');
                break;
            case 'set_layout': if (array_key_exists('layout', $op)) {
                $s->enum($op['layout'], ['free', 'stack', 'grid'], '$.layout');
            } break;
            case 'set_animation': $s->animation($op['anim'] ?? null, '$.anim');
                break;
            case 'remove_animation': $s->string($op['animId'] ?? null, '$.animId');
                break;
            case 'set_action':
                $s->number($op['index'] ?? null, '$.index');
                if (($op['action'] ?? null) !== null) {
                    $s->action($op['action'], '$.action');
                }
                break;
            case 'reorder_roots':
                if (! is_array($op['order'] ?? null) || ! array_is_list($op['order'])) {
                    $s->error('$.order', 'list of ids', $op['order'] ?? null);
                } else {
                    foreach ($op['order'] as $i => $id) {
                        $s->string($id, '$.order.'.$i);
                    }
                }
                break;
        }

        return $s->errors;
    }

    public static function assert(mixed $op, ?array $knownTypes = null): void
    {
        $errors = self::validate($op, $knownTypes);
        if ($errors !== []) {
            throw new SchemaException($errors);
        }
    }
}
