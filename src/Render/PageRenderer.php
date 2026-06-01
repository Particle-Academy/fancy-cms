<?php

declare(strict_types=1);

namespace FancyCms\Render;

/**
 * Renders a Stages document to the static skeleton: HTML (with `data-cms`
 * handles) + the compiled stylesheet. Interactive / 3rd-party addons become
 * sized **island placeholders** hydrated client-side.
 *
 * Phase 0 skeleton — text + containers + islands. Data bindings, repeaters, and
 * the full element set land in later phases.
 */
final class PageRenderer
{
    /**
     * @param  array<string,mixed>  $doc
     * @param  array<string,mixed>  $data  data context for bindings (Phase 3)
     * @return array{html:string,css:string}
     */
    public static function render(array $doc, array $data = []): array
    {
        $html = '';
        foreach (($doc['sections'] ?? []) as $id) {
            $html .= self::renderNode($doc, (string) $id);
        }

        return ['html' => $html, 'css' => CssEmitter::emit($doc)];
    }

    /**
     * @param  array<string,mixed>  $doc
     */
    private static function renderNode(array $doc, string $id): string
    {
        $node = $doc['nodes'][$id] ?? null;
        if ($node === null) {
            return '';
        }
        $attr = ' data-cms="'.htmlspecialchars($id, ENT_QUOTES).'"';

        // Interactive / 3rd-party addons → sized placeholder, hydrated client-side.
        if (! empty($node['island'])) {
            $type = htmlspecialchars((string) ($node['type'] ?? ''), ENT_QUOTES);

            return "<div{$attr} data-cms-island=\"{$type}\"></div>";
        }

        if (($node['type'] ?? '') === 'text') {
            $content = $node['props']['content'] ?? '';
            $inner = is_string($content) ? htmlspecialchars($content, ENT_QUOTES) : '';

            return "<div{$attr}>{$inner}</div>";
        }

        $inner = '';
        foreach (self::childrenOf($doc, $id) as $childId) {
            $inner .= self::renderNode($doc, $childId);
        }

        return "<div{$attr}>{$inner}</div>";
    }

    /**
     * @param  array<string,mixed>  $doc
     * @return list<string>
     */
    private static function childrenOf(array $doc, string $parent): array
    {
        $kids = [];
        foreach (($doc['nodes'] ?? []) as $cid => $n) {
            if (($n['parent'] ?? null) === $parent) {
                $kids[] = ['id' => (string) $cid, 'order' => (string) ($n['order'] ?? '')];
            }
        }
        usort($kids, static fn ($a, $b): int => strcmp($a['order'], $b['order']));

        return array_map(static fn ($k): string => $k['id'], $kids);
    }
}
