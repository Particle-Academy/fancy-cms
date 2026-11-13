<?php

declare(strict_types=1);

namespace FancyCms\Render;

/**
 * An island's text fallback: the content a kit island carries, as plain
 * paragraphs, lists and code, so it is in the first byte of the page. The PHP
 * twin of fancy-cms-ui's render/text-fallback.ts + islandFallback; both write the
 * same bytes (tests/fixtures/kit-islands.json).
 *
 *   {"text": "title"}                       <p>title</p>
 *   {"list": "items", "fields": ["label"]}  <ul><li>label</li>...</ul>
 *   {"list": "keys"}                        <ul><li>key</li>...</ul> (scalar items)
 *   {"code": "code"}                        <pre><code>code</code></pre>
 *
 * A step whose prop is missing, empty or the wrong shape writes nothing; item
 * fields that are present are joined with ": "; at most 100 list items.
 */
final class TextFallback
{
    private const MAX_ITEMS = 100;

    /**
     * The fallback's HTML for these resolved props: `<div data-cms-fallback="">`
     * around its blocks, or an empty string when there are none.
     *
     * @param  list<array<string,mixed>|\stdClass>  $steps
     * @param  array<string,mixed>  $props
     */
    public static function html(array $steps, array $props, callable $escape): string
    {
        $out = '';
        foreach ($steps as $step) {
            $step = (array) $step;
            if (isset($step['text'])) {
                $s = self::scalar($props[(string) $step['text']] ?? null);
                if ($s !== '') {
                    $out .= '<p>'.$escape($s).'</p>';
                }
            } elseif (isset($step['code'])) {
                $s = self::scalar($props[(string) $step['code']] ?? null);
                if ($s !== '') {
                    $out .= '<pre><code>'.$escape($s).'</code></pre>';
                }
            } elseif (isset($step['list'])) {
                $list = $props[(string) $step['list']] ?? null;
                if (! is_array($list) || ! array_is_list($list)) {
                    continue;
                }
                $fields = isset($step['fields']) ? array_values((array) $step['fields']) : [];
                $items = '';
                foreach (array_slice($list, 0, self::MAX_ITEMS) as $item) {
                    if ($fields !== []) {
                        if ($item instanceof \stdClass) {
                            $item = get_object_vars($item);
                        }
                        if (! is_array($item) || ($item !== [] && array_is_list($item))) {
                            continue;
                        }
                        $parts = [];
                        foreach ($fields as $field) {
                            $s = self::scalar($item[(string) $field] ?? null);
                            if ($s !== '') {
                                $parts[] = $s;
                            }
                        }
                        $s = implode(': ', $parts);
                    } else {
                        $s = self::scalar($item);
                    }
                    if ($s !== '') {
                        $items .= '<li>'.$escape($s).'</li>';
                    }
                }
                if ($items !== '') {
                    $out .= '<ul>'.$items.'</ul>';
                }
            }
        }

        return $out === '' ? '' : '<div data-cms-fallback="">'.$out.'</div>';
    }

    /** A string as is, a finite number as JavaScript writes it, anything else empty. */
    private static function scalar(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || (is_float($value) && is_finite($value))) {
            return JsValue::number((float) $value);
        }

        return '';
    }
}
