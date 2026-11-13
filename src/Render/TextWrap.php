<?php

declare(strict_types=1);

namespace FancyCms\Render;

/**
 * Which text nodes may break a word anywhere (lab finding S11, and the 0.9.0
 * regression its first fix caused).
 *
 * Text nodes get `overflow-wrap: break-word` from the base stylesheet, which
 * keeps min-content, so grid tracks and flex items still size to whole words,
 * prices and stats. A node whose rendered text holds a long token (a URL, an
 * email, hostile input) is marked `data-cms-wrap="anywhere"` and gets
 * `overflow-wrap: anywhere`, which lowers that node's min-content only.
 *
 * A token is a run with no space, tab, newline, carriage return, form feed,
 * zero-width space, hyphen-minus, U+2010, en dash or em dash; its length is
 * counted in code points. PHP twin of fancy-cms-ui `render/wrap.ts`;
 * tests/fixtures/text-wrap.json is the shared spec.
 */
final class TextWrap
{
    public const LONG_TOKEN = 20;

    private const SEPARATORS = '/[ \t\n\r\f\x{200B}\x{2010}\x{2013}\x{2014}-]+/u';

    /** Node types the base text rule styles, and whose rendered text is checked. */
    private const MARKED = ['text', 'heading', 'link', 'callout', 'list'];

    /** True when $text holds a token of LONG_TOKEN code points or more. */
    public static function hasLongToken(string $text): bool
    {
        if (strlen($text) < self::LONG_TOKEN) {
            return false;
        }
        $tokens = preg_split(self::SEPARATORS, $text);
        if ($tokens === false) {
            // Not valid UTF-8: measure the bytes as whitespace-separated runs.
            $tokens = preg_split('/[ \t\n\r\f-]+/', $text) ?: [$text];
        }
        foreach ($tokens as $token) {
            if (strlen($token) >= self::LONG_TOKEN && mb_strlen($token, 'UTF-8') >= self::LONG_TOKEN) {
                return true;
            }
        }

        return false;
    }

    /**
     * The `data-cms-wrap` value for a node, or null. $content is the node's
     * rendered text and $items a list's rendered items.
     *
     * @param  list<string>  $items
     */
    public static function wrapFor(string $type, string $content, array $items): ?string
    {
        if (! in_array($type, self::MARKED, true)) {
            return null;
        }
        if ($type === 'list') {
            foreach ($items as $item) {
                if (self::hasLongToken($item)) {
                    return 'anywhere';
                }
            }

            return null;
        }

        return self::hasLongToken($content) ? 'anywhere' : null;
    }
}
