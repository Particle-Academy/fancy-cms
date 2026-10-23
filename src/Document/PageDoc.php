<?php

declare(strict_types=1);

namespace FancyCms\Document;

use FancyCms\Exceptions\SchemaException;

/** Server storage boundary. Host addon registries supply their known types. */
final class PageDoc
{
    public const NODE_TYPES = ['section', 'stack', 'grid', 'frame', 'shape', 'card', 'text', 'heading', 'richtext', 'link', 'list', 'divider', 'callout', 'code', 'button', 'image', 'repeater'];

    public static function validate(mixed $doc, ?array $knownTypes = null): array
    {
        $s = new Schema;
        $s->json($doc);
        if ($s->errors !== []) {
            return $s->errors;
        }
        if (! $s->record($doc, '$')) {
            return $s->errors;
        }
        $s->string($doc['id'] ?? null, '$.id');
        $seq = $doc['seq'] ?? null;
        if (! (is_int($seq) || is_float($seq)) || ! is_finite((float) $seq) || floor($seq) != $seq || $seq < 0 || $seq > 9007199254740991) {
            $s->error('$.seq', 'nonnegative safe integer', $seq);
        }
        if ($s->record($doc['meta'] ?? null, '$.meta')) {
            $s->string($doc['meta']['title'] ?? null, '$.meta.title', false);
            $s->string($doc['meta']['slug'] ?? null, '$.meta.slug', false);
            $s->enum($doc['meta']['scrollMode'] ?? null, ['smooth', 'snap'], '$.meta.scrollMode');
            if (isset($doc['meta']['seo']) && $s->record($doc['meta']['seo'], '$.meta.seo')) {
                foreach ($doc['meta']['seo'] as $k => $v) {
                    $s->string($v, '$.meta.seo.'.$k, false);
                }
            }
        }
        $s->record($doc['theme'] ?? null, '$.theme');
        $bp = $doc['breakpoints'] ?? null;
        if (! is_array($bp) || ! array_is_list($bp) || ! in_array('base', $bp, true)) {
            $s->error('$.breakpoints', 'breakpoint list including base', $bp);
        } else {
            foreach ($bp as $i => $name) {
                $s->string($name, '$.breakpoints.'.$i);
            }
        }
        if (! $s->record($doc['nodes'] ?? null, '$.nodes')) {
            return $s->errors;
        }
        $nodes = $doc['nodes'];
        if (count($nodes) > 10000) {
            $s->error('$.nodes', 'at most 10000 nodes', $nodes);

            return $s->errors;
        }
        foreach ($nodes as $key => $node) {
            $path = '$.nodes.'.$key;
            $s->node($node, $path, $knownTypes ?? self::NODE_TYPES);
            if (! is_array($node)) {
                continue;
            }
            if (($node['id'] ?? null) !== (string) $key) {
                $s->error($path.'.id', 'id matching the node map key', $node['id'] ?? null);
            }
            if (is_string($node['parent'] ?? null) && ! array_key_exists($node['parent'], $nodes)) {
                $s->error($path.'.parent', 'existing parent id or null', $node['parent']);
            }
        }
        // Linear, iterative parent-chain colouring. Never recurse down a
        // hostile tree or rescan the same already-completed ancestor chain.
        $done = [];
        foreach ($nodes as $key => $node) {
            $cur = (string) $key;
            $chain = [];
            while (isset($nodes[$cur]) && is_array($nodes[$cur]) && ! isset($done[$cur])) {
                if (isset($chain[$cur])) {
                    $s->error('$.nodes.'.$cur.'.parent', 'acyclic parent chain', $nodes[$cur]['parent'] ?? null);
                    break;
                }
                $chain[$cur] = true;
                $parent = $nodes[$cur]['parent'] ?? null;
                if (! is_string($parent)) {
                    break;
                }
                $cur = $parent;
            }
            foreach ($chain as $id => $_) {
                $done[$id] = true;
            }
        }

        return $s->errors;
    }

    public static function assert(mixed $doc, ?array $knownTypes = null): void
    {
        $errors = self::validate($doc, $knownTypes);
        if ($errors !== []) {
            throw new SchemaException($errors);
        }
    }

    /** Validate the input, reject semantic no-ops, then validate before storage. */
    public static function apply(array $doc, array $op, ?array $knownTypes = null): array
    {
        self::assert($doc, $knownTypes);
        PageOp::assert($op, $knownTypes);
        $out = Reducer::reduce($doc, $op, ['onInvalid' => static function (array $_, string $reason): never {
            throw new SchemaException([['path' => '$.op', 'expected' => 'operation applicable to this document', 'got' => $reason, 'hint' => 'Read the current document and target existing nodes.']]);
        }]);
        self::assert($out, $knownTypes);

        return $out;
    }
}
