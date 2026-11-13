<?php

declare(strict_types=1);

namespace FancyCms\Render;

use FancyCms\Data\PageInputs;
use FancyCms\Document\Decorative;
use FancyCms\Document\Icons;
use FancyCms\Document\Ordering;
use FancyCms\Islands\IslandCatalogue;

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
     * @param  array<string,mixed>  $inputs  page input values by name, e.g. PageInputs::fromQuery($doc, $request->query(), $data); normalised before use
     * @return array{html:string,css:string}
     */
    public static function render(array $doc, array $data = [], ?callable $imageResolver = null, ?callable $fontResolver = null, string $fontDisplay = 'swap', ?IslandCatalogue $islands = null, array $inputs = []): array
    {
        $css = CssEmitter::emit($doc, $fontResolver, $fontDisplay);
        $islands ??= IslandCatalogue::builtin();
        $html = '';
        $preloads = [];
        $decorativeSafe = Decorative::error(is_array($doc['nodes'] ?? null) ? $doc['nodes'] : []) === null;
        foreach (self::roots($doc) as $id) {
            $html .= self::renderNode($doc, $id, $data, $preloads, $decorativeSafe, $imageResolver, $islands, $inputs);
        }
        $hints = '';
        foreach ($preloads as $hint) {
            $hints .= $hint;
        }

        return ['html' => $hints.$html, 'css' => $css];
    }

    /**
     * The `<style>` element for a document's CSS, for the host's `<head>`: never
     * the body, where a `<style>` inside a `<div>` is invalid HTML (lab finding
     * K20). Byte-identical to fancy-cms-ui's `cmsStyleTag()`; the browser
     * renderer reuses an identical element it finds in the head. Empty CSS (an
     * empty document) gives an empty string. Safe for any `$css`: it goes
     * through {@see self::styleText()}, so a caller's CSS cannot close the element.
     *
     * @param  array<string,mixed>  $doc
     */
    public static function styleTag(array $doc, string $css): string
    {
        if ($css === '') {
            return '';
        }

        return '<style data-cms-styles="'.self::escape(is_string($doc['id'] ?? null) ? $doc['id'] : '').'">'.self::styleText($css).'</style>';
    }

    /**
     * CSS made safe as the raw text of a `<style>` element, byte-identical to
     * fancy-cms-ui's `styleText()`. Every `<` that starts `</` or `<!` becomes
     * the CSS hex escape `\3C ` (the space ends the escape and is consumed by the
     * CSS tokenizer), so no `</style` in any case can end the element and no
     * `<!--` survives. The escape means `<` in a string, an identifier, an
     * unquoted `url()` or a comment, so the CSS still says what it said there.
     * Any other `<` (a media range such as `width < 600px`) is left alone.
     */
    public static function styleText(string $css): string
    {
        return str_replace(['</', '<!'], ['\3C /', '\3C !'], $css);
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
    private static function renderNode(array $doc, string $id, array $data, array &$preloads, bool $decorativeSafe, ?callable $imageResolver, IslandCatalogue $islands, array $inputs = []): string
    {
        $node = $doc['nodes'][$id] ?? null;
        if ($node === null) {
            return '';
        }
        $attr = ' data-cms="'.self::escape($node['id'] ?? $id).'"';
        // An island when the node says so, or when its type is a registered
        // island that hydrates (the chart, and every host island).
        $isIsland = ! empty($node['island']) || (is_string($node['type'] ?? null) && $islands->hydrates($node['type']));
        if ($isIsland) {
            $attr .= ' data-cms-island="'.self::escape((string) ($node['type'] ?? '')).'"';
        }
        if (is_string($node['className'] ?? null)) {
            $attr .= ' class="'.self::escape($node['className']).'"';
        }
        $props = is_array($node['props'] ?? null) ? $node['props'] : [];
        $node['props'] = $props;
        $text = static fn ($value): string => JsValue::text(self::resolve($value, $data));
        [$tag, $attrs] = self::elementFor($node, $text);
        $anchor = $text($props['anchor'] ?? null);
        if (preg_match('/\A[a-z][a-z0-9-]{0,63}\z/', $anchor) === 1) {
            $attr .= ' id="'.$anchor.'"';
        }
        foreach ($attrs as $key => $value) {
            $attr .= ' '.$key.'="'.self::escape($value).'"';
        }
        // A long token may break anywhere; ordinary words keep their min-content (TextWrap).
        $listItems = [];
        if (($node['type'] ?? null) === 'list') {
            $resolved = self::resolve($props['items'] ?? null, $data);
            if (is_array($resolved) && array_is_list($resolved)) {
                $listItems = array_map(static fn ($item): string => JsValue::text($item), $resolved);
            }
        }
        $wrap = TextWrap::wrapFor(is_string($node['type'] ?? null) ? $node['type'] : '', ($node['type'] ?? null) === 'list' ? '' : $text($props['content'] ?? null), $listItems);
        if ($wrap !== null) {
            $attr .= ' data-cms-wrap="'.$wrap.'"';
        }
        if ($decorativeSafe && in_array($node['type'] ?? null, ['frame', 'shape'], true) && ($props['decorative'] ?? null) === true) {
            $attr .= ' aria-hidden="true"';
        }
        $escape = self::escape(...);
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
        } elseif ($type === 'icon') {
            $glyph = Icons::validProps($props) ? Icons::glyph($props['name']) : null;
            if ($glyph !== null) {
                $inner = '<span aria-hidden="true" data-react-fancy-icon="" class="inline-flex items-center justify-center flex-shrink-0 h-5 w-5">';
                $inner .= '<svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">';
                foreach ($glyph as [$primitive, $attributes]) {
                    $inner .= '<'.$primitive;
                    foreach ($attributes as $key => $value) {
                        $inner .= ' '.$key.'="'.$escape((string) $value).'"';
                    }
                    $inner .= '></'.$primitive.'>';
                }
                $inner .= '</svg></span>';
            }
        } elseif ($type === 'image') {
            $fit = $text($props['fit'] ?? null);
            $asset = array_key_exists('asset', $props);
            $image = $asset ? ImageAssets::resolve($props['asset'], $imageResolver) : null;
            $src = $asset ? ($image['src'] ?? '') : $text($props['src'] ?? null);
            // Match the native SSR resource hint for eager non-data images.
            // This collection is per render, so neither duplicate nodes nor
            // later pages can accumulate another page's resource hints.
            if ($src !== '' && $text($props['loading'] ?? null) === 'eager' && ! preg_match('/^data:/i', $src)) {
                $srcSet = $image['srcSet'] ?? null;
                $key = $srcSet === null ? 'src:'.$src : 'srcset:'.$srcSet."\n".$image['sizes'];
                $preloads[$key] = '<link rel="preload" as="image"'.($srcSet === null ? ' href="'.$escape($src).'"' : ' imageSrcSet="'.$escape($srcSet).'" imageSizes="'.$escape($image['sizes']).'"').'/>';
            }
            $inner = '<img'.($src === '' ? '' : ' src="'.$escape($src).'"').' alt="'.$escape($text($props['alt'] ?? null)).'"';
            if ($image !== null) {
                $inner .= ' width="'.$image['width'].'" height="'.$image['height'].'"';
                if (isset($image['srcSet'])) {
                    $inner .= ' srcSet="'.$escape($image['srcSet']).'" sizes="'.$escape($image['sizes']).'"';
                }
            }
            $inner .= ' loading="'.($text($props['loading'] ?? null) === 'eager' ? 'eager' : 'lazy').'"';
            if (in_array($fit, ['contain', 'fill', 'none', 'scale-down'], true)) {
                $inner .= ' data-cms-fit="'.$fit.'"';
            }
            $inner .= '/>';
        } elseif ($type === 'divider') {
            $inner = '<hr/>';
        } elseif ($type === 'code') {
            $inner = '<pre><code>'.$escape($text($props['content'] ?? null)).'</code></pre>';
        } elseif ($type === 'callout') {
            $content = $text($props['content'] ?? null);
            $inner = $escape($content === '' ? 'Callout' : $content);
        } elseif (Infographics::isType($type)) {
            $resolved = [];
            foreach ($props as $key => $value) {
                $resolved[$key] = self::resolve($value, $data);
            }
            $inner = Infographics::inner($type, $resolved);
        } elseif ($type === 'input') {
            $resolved = [];
            foreach ($props as $key => $value) {
                $resolved[$key] = self::resolve($value, $data);
            }
            $name = is_string($resolved['name'] ?? null) ? $resolved['name'] : '';
            $declared = false;
            foreach (PageInputs::declared($doc) as $decl) {
                $declared = $declared || ($decl['node'] === (string) ($node['id'] ?? $id) && $decl['name'] === $name);
            }
            $inner = Inputs::inner($resolved, array_key_exists($name, $inputs) ? $inputs[$name] : null, $declared);
        } elseif (! $isIsland || in_array($type, ['section', 'stack', 'grid', 'frame', 'shape', 'card'], true)) {
            foreach (self::childrenOf($doc, $id) as $childId) {
                $inner .= self::renderNode($doc, $childId, $data, $preloads, $decorativeSafe, $imageResolver, $islands, $inputs);
            }
        }

        return '<'.$tag.$attr.'>'.$inner.'</'.$tag.'>';
    }

    /** React's static HTML escaping, including its apostrophe spelling. */
    private static function escape(string $value): string
    {
        return str_replace('&#039;', '&#x27;', htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }

    /** The PHP twin of registry.tsx elementFor; all tags and attrs are fixed. */
    private static function elementFor(array $node, callable $text): array
    {
        $props = $node['props'] ?? [];
        $type = $node['type'] ?? '';
        $as = $text($props['as'] ?? null);
        if ($type === 'icon') {
            return ['span', Icons::validProps($props) && ! $props['decorative']
                ? ['role' => 'img', 'aria-label' => $props['label']]
                : ['aria-hidden' => 'true']];
        }
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
            $attrs = $href === null ? [] : ['href' => $href];
            if ($href !== null && preg_match('~^https?://~i', $href)) {
                $attrs['rel'] = 'noopener noreferrer';
            }
            if ($type === 'button') {
                $variant = $text($props['variant'] ?? null);
                $attrs['data-cms-button'] = in_array($variant, ['primary', 'outline', 'ghost'], true) ? $variant : 'primary';
            }

            return [$href !== null ? 'a' : ($type === 'link' ? 'span' : 'div'), $attrs];
        }
        if (Infographics::isType($type)) {
            return Infographics::element($type, $props, $text);
        }
        if ($type === 'input') {
            return Inputs::element($props, $text);
        }
        if ($type === 'callout') {
            $variant = $text($props['variant'] ?? null);

            return ['div', ['data-cms-callout' => in_array($variant, ['info', 'success', 'warning', 'danger'], true) ? $variant : 'info']];
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
