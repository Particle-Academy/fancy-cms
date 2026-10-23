<?php

declare(strict_types=1);

namespace FancyCms\Render;

use FancyCms\Document\Ordering;

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
        foreach (self::roots($doc) as $id) {
            $html .= self::renderNode($doc, $id, $data);
        }

        return ['html' => $html, 'css' => CssEmitter::emit($doc)];
    }

    /**
     * The top-level node ids, in order — for BOTH document shapes.
     *
     * `@particle-academy/fancy-cms-ui` used to carry top-level order in its own
     * `sections: string[]` array while every other level used fractional `order`
     * keys. It now orders roots like any other sibling group, and drops the
     * array. This renderer has to read documents written on either side of that
     * change, because a host upgrades its PHP and its JS at different moments.
     *
     * The two shapes are distinguished by presence, not by a version field, and
     * **`sections` wins wherever it exists**. That is not a stylistic
     * preference: the old `reorder_sections` op permuted the array and left
     * every node's `order` key untouched, so on a page whose sections were ever
     * rearranged, the keys are stale and the array is the only record of the
     * real order. Preferring the keys there would render a live page in the
     * order it was first authored in — silently, with nothing thrown.
     *
     * @param  array<string,mixed>  $doc
     * @return list<string>
     */
    private static function roots(array $doc): array
    {
        $sections = $doc['sections'] ?? null;

        if (is_array($sections)) {
            $ids = [];
            foreach ($sections as $id) {
                $id = (string) $id;
                // A dangling id is a stale write; skip it rather than emitting
                // an empty node for it.
                if (isset($doc['nodes'][$id])) {
                    $ids[] = $id;
                }
            }

            return $ids;
        }

        return self::childrenOf($doc, null);
    }

    /**
     * @param  array<string,mixed>  $doc
     */
    private static function renderNode(array $doc, string $id, array $data = []): string
    {
        $node = $doc['nodes'][$id] ?? null;
        if ($node === null) {
            return '';
        }
        $attr = ' data-cms="'.htmlspecialchars($id, ENT_QUOTES).'"';
        if (! empty($node['className']) && is_string($node['className'])) {
            $attr .= ' class="'.htmlspecialchars($node['className'], ENT_QUOTES).'"';
        }

        // Interactive / 3rd-party addons → sized placeholder, hydrated client-side.
        if (! empty($node['island'])) {
            $type = htmlspecialchars((string) ($node['type'] ?? ''), ENT_QUOTES);

            return "<div{$attr} data-cms-island=\"{$type}\"></div>";
        }

        $props = $node['props'] ?? [];
        $text = static fn ($value): string => JsValue::text(self::resolve($value, $data));
        [$tag, $attrs] = self::elementFor($node, $text);
        foreach ($attrs as $key => $value) {
            $attr .= ' '.$key.'="'.htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"';
        }
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $type = $node['type'] ?? '';
        $inner = '';
        if ($type === 'text' || $type === 'heading' || $type === 'link') {
            $content = $text($props['content'] ?? null);
            if ($content === '' && $type === 'heading') {
                $content = 'Heading';
            }
            if ($content === '' && $type === 'link') {
                $content = 'link';
            }
            $inner = $escape($content);
        } elseif ($type === 'button') {
            $content = $text($props['label'] ?? null);
            if ($content === '') {
                $content = $text($props['content'] ?? null);
            }
            $inner = $escape($content === '' ? 'Button' : $content);
        } elseif ($type === 'list') {
            $items = self::resolve($props['items'] ?? null, $data);
            if (is_array($items) && array_is_list($items)) {
                foreach ($items as $item) {
                    $inner .= '<li>'.$escape(JsValue::text($item)).'</li>';
                }
            }
        } elseif ($type === 'image') {
            $fit = $text($props['fit'] ?? null);
            if (! in_array($fit, ['cover', 'contain', 'fill', 'none', 'scale-down'], true)) {
                $fit = 'cover';
            }
            $inner = '<img src="'.$escape($text($props['src'] ?? null)).'" alt="'.$escape($text($props['alt'] ?? null)).'" loading="'.($text($props['loading'] ?? null) === 'eager' ? 'eager' : 'lazy').'" style="width:100%;height:100%;object-fit:'.$fit.';display:block">';
        } else {
            foreach (self::childrenOf($doc, $id) as $childId) {
                $inner .= self::renderNode($doc, $childId, $data);
            }
        }

        return '<'.$tag.$attr.'>'.$inner.'</'.$tag.'>';
    }

    /** The PHP twin of registry.tsx elementFor; all tags and attrs are fixed. */
    private static function elementFor(array $node, callable $text): array
    {
        $props = $node['props'] ?? [];
        $type = $node['type'] ?? '';
        $as = $text($props['as'] ?? null);
        $landmarks = ['section', 'header', 'nav', 'main', 'footer', 'article', 'aside', 'div'];
        if ($type === 'heading') {
            $level = $text($props['level'] ?? null);
            $level = $level === '' ? '2' : JsValue::trim($level);
            if (preg_match('/^0[xX][0-9a-fA-F]+$/', $level)) {
                $number = intval(substr($level, 2), 16);
            } elseif (preg_match('/^0[bB][01]+$/', $level)) {
                $number = intval(substr($level, 2), 2);
            } elseif (preg_match('/^0[oO][0-7]+$/', $level)) {
                $number = intval(substr($level, 2), 8);
            } else {
                $number = is_numeric($level) ? (float) $level : NAN;
            }

            return [is_finite((float) $number) && $number >= 1 && $number <= 6 && floor($number) == $number ? 'h'.(int) $number : 'h2', []];
        }
        if ($type === 'text') {
            return ['p', []];
        }
        if ($type === 'section') {
            return [in_array($as, $landmarks, true) ? $as : 'section', []];
        }
        if ($type === 'list') {
            return [$text($props['ordered'] ?? null) === 'true' ? 'ol' : 'ul', []];
        }
        if ($type === 'button' || $type === 'link') {
            $href = self::safeHref($text($props['href'] ?? null));
            if ($href === null) {
                return [$type === 'link' ? 'span' : 'div', []];
            }
            $attrs = ['href' => $href];
            if (preg_match('~^https?://~i', $href)) {
                $attrs['rel'] = 'noopener noreferrer';
            }

            return ['a', $attrs];
        }

        return [in_array($type, ['stack', 'grid', 'frame', 'shape', 'card'], true) && in_array($as, $landmarks, true) ? $as : 'div', []];
    }

    public static function safeHref(string $raw): ?string
    {
        $href = JsValue::trim($raw);
        if ($href === '') {
            return null;
        }
        $probe = preg_replace('/[\x00-\x20\x7f]+/', '', $href);
        if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $probe, $match) && ! in_array(strtolower($match[1]), ['http', 'https', 'mailto', 'tel'], true)) {
            return null;
        }

        return $href;
    }

    private static function resolve(mixed $value, array $data): mixed
    {
        $binding = $value instanceof \stdClass ? (array) $value : $value;
        if (! is_array($binding) || ! is_string($binding['$bind'] ?? null)) {
            return $value;
        }
        $current = $data;
        foreach (explode('.', $binding['$bind']) as $part) {
            if ($current instanceof \stdClass) {
                $current = (array) $current;
            }
            if (! is_array($current) || ! array_key_exists($part, $current)) {
                return null;
            }
            $current = $current[$part];
        }

        return $current;
    }

    /**
     * @param  array<string,mixed>  $doc
     * @return list<string>
     */
    private static function childrenOf(array $doc, ?string $parent): array
    {
        $kids = [];
        foreach (($doc['nodes'] ?? []) as $cid => $n) {
            if (($n['parent'] ?? null) === $parent) {
                $kids[] = ['id' => (string) $cid, 'order' => (string) ($n['order'] ?? '')];
            }
        }
        usort($kids, static fn ($a, $b): int => Ordering::compare($a['order'], $b['order']) ?: Ordering::compare($a['id'], $b['id']));

        return array_map(static fn ($k): string => $k['id'], $kids);
    }
}
