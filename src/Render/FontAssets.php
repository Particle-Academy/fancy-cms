<?php

declare(strict_types=1);

namespace FancyCms\Render;

use FancyCms\Exceptions\FontResolutionException;
use stdClass;
use Throwable;

/** Pure synchronous host lookup. No network, file reads, global cache or local() sources. */
final class FontAssets
{
    private static function fail(string $reason): never
    {
        throw new FontResolutionException($reason);
    }

    private static function record(mixed $value): ?array
    {
        if ($value instanceof stdClass && get_class($value) === stdClass::class) {
            return (array) $value;
        }

        return is_array($value) && ! array_is_list($value) ? $value : null;
    }

    private static function weight(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 1 && $value <= 1000 && floor((float) $value) === (float) $value;
    }

    private static function style(mixed $value): bool
    {
        return in_array($value, ['normal', 'italic'], true);
    }

    private static function digest(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    private static function path(mixed $value): bool
    {
        return is_string($value) && strlen($value) <= 1024 && preg_match('~^/(?:[A-Za-z0-9_-][A-Za-z0-9._-]*/)*[A-Za-z0-9_-][A-Za-z0-9._-]*\.woff2$~D', $value) === 1;
    }

    private static function reference(mixed $value): array
    {
        $v = self::record($value);
        if ($v === null || array_diff(array_keys($v), ['family', 'source', 'weights', 'styles']) !== []) {
            self::fail('invalid-reference');
        }
        $family = $v['family'] ?? null;
        $source = $v['source'] ?? null;
        $weights = $v['weights'] ?? null;
        $styles = array_key_exists('styles', $v) ? $v['styles'] : ['normal'];
        if (! is_string($family) || preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._+-]{0,99}$/D', $family) !== 1 || trim($family) !== $family || ! in_array($source, ['bundled', 'google'], true) || ! is_array($weights) || ! array_is_list($weights) || count($weights) < 1 || count($weights) > 16 || ! is_array($styles) || ! array_is_list($styles) || count($styles) < 1 || count($styles) > 2) {
            self::fail('invalid-reference');
        }
        foreach ($weights as $w) {
            if (! self::weight($w)) {
                self::fail('invalid-reference');
            }
        }
        foreach ($styles as $s) {
            if (! self::style($s)) {
                self::fail('invalid-reference');
            }
        }
        $weights = array_map(static fn ($w): int => (int) $w, $weights);
        if (count(array_unique($weights)) !== count($weights) || count(array_unique($styles)) !== count($styles)) {
            self::fail('invalid-reference');
        }
        sort($weights, SORT_NUMERIC);
        sort($styles, SORT_STRING);

        return ['family' => $family, 'source' => $source, 'weights' => $weights, 'styles' => $styles];
    }

    private static function unicode(mixed $value): string
    {
        if (! is_string($value) || $value === '' || strlen($value) > 8192 || preg_match('/[^\x00-\x7F]/', $value)) {
            self::fail('invalid-asset');
        }
        $ranges = explode(',', $value);
        if (count($ranges) > 256) {
            self::fail('invalid-asset');
        }
        $result = [];
        foreach ($ranges as $part) {
            // Match JS trim for the permitted ASCII grammar; non-ASCII is rejected below.
            $range = strtoupper(trim($part, " \t\r\n\f"));
            if (preg_match('/^U\+(?:[0-9A-F]{1,6}(?:-[0-9A-F]{1,6})?|[0-9A-F]{0,5}\?{1,6})$/D', $range) !== 1) {
                self::fail('invalid-asset');
            }
            $pair = explode('-', substr($range, 2));
            $left = $pair[0];
            if (strlen($left) > 6) {
                self::fail('invalid-asset');
            }
            $low = hexdec(str_replace('?', '0', $left));
            $high = hexdec($pair[1] ?? str_replace('?', 'F', $left));
            if ($low > $high || $high > 0x10FFFF) {
                self::fail('invalid-asset');
            }
            $result[] = $range;
        }

        return implode(', ', $result);
    }

    private static function asset(mixed $value, array $ref): array
    {
        $v = self::record($value);
        if ($v === null || ($v['family'] ?? null) !== $ref['family'] || ($v['source'] ?? null) !== $ref['source'] || ! self::digest($v['manifestSha256'] ?? null) || ! is_array($v['faces'] ?? null) || ! array_is_list($v['faces']) || count($v['faces']) < 1 || count($v['faces']) > 256) {
            self::fail('invalid-asset');
        }
        $faces = [];
        foreach ($v['faces'] as $raw) {
            $face = self::record($raw);
            if ($face === null || ! self::path($face['src'] ?? null) || ! self::digest($face['sha256'] ?? null) || ! self::style($face['style'] ?? null)) {
                self::fail('invalid-asset');
            }
            $w = $face['weight'] ?? null;
            if (self::weight($w)) {
                $w = (int) $w;
            } elseif (is_array($w) && array_is_list($w) && count($w) === 2 && self::weight($w[0]) && self::weight($w[1]) && $w[0] < $w[1]) {
                $w = [(int) $w[0], (int) $w[1]];
            } else {
                self::fail('invalid-asset');
            }
            $normalized = ['src' => $face['src'], 'sha256' => $face['sha256'], 'weight' => $w, 'style' => $face['style']];
            if (array_key_exists('unicodeRange', $face)) {
                $normalized['unicodeRange'] = self::unicode($face['unicodeRange']);
            }
            $faces[] = $normalized;
        }
        foreach ($ref['styles'] as $s) {
            foreach ($ref['weights'] as $w) {
                $found = false;
                foreach ($faces as $face) {
                    if ($face['style'] === $s && (is_int($face['weight']) ? $face['weight'] === $w : $face['weight'][0] <= $w && $face['weight'][1] >= $w)) {
                        $found = true;
                        break;
                    }
                }
                if (! $found) {
                    self::fail('missing-face');
                }
            }
        }

        return ['family' => $ref['family'], 'source' => $ref['source'], 'manifestSha256' => $v['manifestSha256'], 'faces' => $faces];
    }

    /** @return array{aliases:array<string,string>,blocks:list<string>} */
    public static function prepare(array $doc, ?callable $resolver = null, string $display = 'swap'): array
    {
        $empty = ['aliases' => [], 'blocks' => []];
        if (! is_array($doc['nodes'] ?? null)) {
            return $empty;
        }
        $theme = self::record($doc['theme'] ?? null);
        $font = self::record($theme['font'] ?? null);
        if ($font === null) {
            return $empty;
        }
        $requests = [];
        $families = [];
        foreach (JsValue::entries($font) as $token => $value) {
            if (! is_array($value) && ! is_object($value)) {
                continue;
            }
            $ref = self::reference($value);
            $key = json_encode($ref, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $families[$ref['source'].':'.$ref['family']] = true;
            if (count($families) > 8 || count($requests) >= 32 && ! isset($requests[$key])) {
                self::fail('too-many-fonts');
            }
            if (isset($requests[$key])) {
                $requests[$key]['tokens'][] = $token;
            } else {
                $requests[$key] = ['ref' => $ref, 'tokens' => [$token]];
            }
        }
        if ($requests === []) {
            return $empty;
        }
        if (! in_array($display, ['swap', 'block'], true)) {
            self::fail('invalid-display');
        }
        if ($resolver === null) {
            self::fail('missing-resolver');
        }
        ksort($requests, SORT_STRING);
        $aliases = [];
        $blocks = [];
        $seen = [];
        $manifests = [];
        foreach ($requests as $request) {
            $lookup = $request['ref'];
            try {
                $raw = $resolver($lookup);
            } catch (Throwable) {
                self::fail('resolver-failed');
            }
            if ($raw === null) {
                self::fail('missing-font');
            }
            $asset = self::asset($raw, $request['ref']);
            $alias = 'cms-font-'.$asset['manifestSha256'];
            $canonical = json_encode($asset, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (isset($manifests[$alias]) && $manifests[$alias] !== $canonical) {
                self::fail('invalid-asset');
            }
            $manifests[$alias] = $canonical;
            foreach ($request['tokens'] as $token) {
                $aliases[$token] = $alias;
            }
            foreach ($asset['faces'] as $face) {
                $w = is_int($face['weight']) ? (string) $face['weight'] : implode(' ', $face['weight']);
                $css = "@font-face {\n  font-display: ".$display.";\n  font-family: \"".$alias."\";\n  font-style: ".$face['style'].";\n  font-weight: ".$w.";\n  src: url(\"".$face['src'].'") format("woff2");'.(isset($face['unicodeRange']) ? "\n  unicode-range: ".$face['unicodeRange'].';' : '')."\n}";
                if (! isset($seen[$css])) {
                    $seen[$css] = true;
                    $blocks[] = $css;
                }
            }
        }
        $blocks[] = ":where([data-cms]) {\n  font-optical-sizing: none;\n  font-synthesis: none;\n}";

        return ['aliases' => $aliases, 'blocks' => $blocks];
    }
}
