<?php

declare(strict_types=1);

namespace FancyCms\Document;

use FancyCms\Exceptions\SchemaException;
use InvalidArgumentException;
use stdClass;

/**
 * JSON boundary for array-based documents/ops. Known records encode as {},
 * including empty maps and numeric keys. Opaque JSON values keep stdClass
 * objects, so a prop/theme value {} never silently becomes []. No callbacks
 * or arbitrary object serialization are accepted by the schema validators.
 */
final class PageJson
{
    private const FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    public static function decodeDocument(string $json): array
    {
        return self::document(json_decode($json, false, 128, JSON_THROW_ON_ERROR), false);
    }

    public static function encodeDocument(array $doc): string
    {
        self::assertJson($doc);

        return json_encode(self::document($doc, true), self::FLAGS, 128);
    }

    public static function decodeOp(string $json): array
    {
        return self::operation(json_decode($json, false, 128, JSON_THROW_ON_ERROR), false);
    }

    public static function encodeOp(array $op): string
    {
        self::assertJson($op);

        return json_encode(self::operation($op, true), self::FLAGS, 128);
    }

    private static function assertJson(array $value): void
    {
        $s = new Schema;
        $s->json($value);
        if ($s->errors !== []) {
            throw new SchemaException($s->errors);
        }
    }

    private static function record(mixed $value, bool $encode, string $path = '$'): array
    {
        if (! $value instanceof stdClass && ! ($encode && is_array($value))) {
            throw new InvalidArgumentException('Expected a JSON object record at '.$path.'.');
        }

        return (array) $value;
    }

    private static function finish(array $record, bool $encode): array|stdClass
    {
        return $encode ? (object) $record : $record;
    }

    private static function document(mixed $value, bool $encode): array|stdClass
    {
        $doc = self::record($value, $encode);
        foreach (['meta', 'theme'] as $key) {
            if (isset($doc[$key])) {
                $record = self::record($doc[$key], $encode, '$.'.$key);
                if ($key === 'meta' && isset($record['seo'])) {
                    $record['seo'] = self::finish(self::record($record['seo'], $encode, '$.meta.seo'), $encode);
                }
                $doc[$key] = self::finish($record, $encode);
            }
        }
        if (isset($doc['nodes'])) {
            $nodes = self::record($doc['nodes'], $encode, '$.nodes');
            foreach ($nodes as $id => &$node) {
                $node = self::node($node, $encode, '$.nodes.'.$id);
            }
            unset($node);
            $doc['nodes'] = self::finish($nodes, $encode);
        }

        return self::finish($doc, $encode);
    }

    private static function node(mixed $value, bool $encode, string $path): array|stdClass
    {
        $node = self::record($value, $encode, $path);
        if (isset($node['props'])) {
            $node['props'] = self::finish(self::record($node['props'], $encode, $path.'.props'), $encode);
        }
        foreach (['style', 'constraints'] as $key) {
            if (isset($node[$key])) {
                $map = self::record($node[$key], $encode, $path.'.'.$key);
                foreach ($map as $bp => &$record) {
                    $record = self::finish(self::record($record, $encode, $path.'.'.$key.'.'.$bp), $encode);
                }
                unset($record);
                $node[$key] = self::finish($map, $encode);
            }
        }
        if (isset($node['animations']) && is_array($node['animations'])) {
            foreach ($node['animations'] as $i => &$anim) {
                $anim = self::animation($anim, $encode, $path.'.animations.'.$i);
            }
            unset($anim);
        }
        if (isset($node['actions']) && is_array($node['actions'])) {
            foreach ($node['actions'] as $i => &$action) {
                $action = self::action($action, $encode, $path.'.actions.'.$i);
            }
            unset($action);
        }
        if (isset($node['repeat'])) {
            $repeat = self::record($node['repeat'], $encode, $path.'.repeat');
            if (isset($repeat['each'])) {
                $repeat['each'] = self::finish(self::record($repeat['each'], $encode, $path.'.repeat.each'), $encode);
            }
            $node['repeat'] = self::finish($repeat, $encode);
        }

        return self::finish($node, $encode);
    }

    private static function animation(mixed $value, bool $encode, string $path): array|stdClass
    {
        $anim = self::record($value, $encode, $path);
        if (isset($anim['range'])) {
            $anim['range'] = self::finish(self::record($anim['range'], $encode, $path.'.range'), $encode);
        }
        if (isset($anim['keyframes']) && is_array($anim['keyframes'])) {
            foreach ($anim['keyframes'] as $i => &$value) {
                $frame = self::record($value, $encode, $path.'.keyframes.'.$i);
                if (isset($frame['props'])) {
                    $frame['props'] = self::finish(self::record($frame['props'], $encode, $path.'.keyframes.'.$i.'.props'), $encode);
                }
                $value = self::finish($frame, $encode);
            }
            unset($value);
        }

        return self::finish($anim, $encode);
    }

    private static function action(mixed $value, bool $encode, string $path): array|stdClass
    {
        $action = self::record($value, $encode, $path);
        if (isset($action['do'])) {
            $action['do'] = self::finish(self::record($action['do'], $encode, $path.'.do'), $encode);
        }

        return self::finish($action, $encode);
    }

    private static function operation(mixed $value, bool $encode): array|stdClass
    {
        $op = self::record($value, $encode);
        if (($op['t'] ?? null) === 'insert_node' && isset($op['node'])) {
            $op['node'] = self::node($op['node'], $encode, '$.node');
        }
        if (($op['t'] ?? null) === 'set_animation' && isset($op['anim'])) {
            $op['anim'] = self::animation($op['anim'], $encode, '$.anim');
        }
        if (($op['t'] ?? null) === 'set_action' && isset($op['action'])) {
            $op['action'] = self::action($op['action'], $encode, '$.action');
        }
        if (isset($op['patch'])) {
            $patch = self::record($op['patch'], $encode, '$.patch');
            if (($op['t'] ?? null) === 'set_meta' && isset($patch['seo'])) {
                $patch['seo'] = self::finish(self::record($patch['seo'], $encode, '$.patch.seo'), $encode);
            }
            $op['patch'] = self::finish($patch, $encode);
        }

        return self::finish($op, $encode);
    }
}
