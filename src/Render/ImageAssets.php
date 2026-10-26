<?php

declare(strict_types=1);

namespace FancyCms\Render;

/** Closed host lookup; page metadata is never trusted as image metadata. */
final class ImageAssets
{
    /** @return array{src:string,width:int,height:int,srcSet?:string,sizes?:string}|null */
    public static function resolve(mixed $handle, ?callable $resolver): ?array
    {
        if (! self::string($handle, 256) || $resolver === null) {
            return null;
        }
        $asset = $resolver($handle);
        if (! self::variant($asset)) {
            return null;
        }
        $variants = array_key_exists('variants', $asset) ? $asset['variants'] : [];
        if (! is_array($variants) || ! array_is_list($variants) || count($variants) > 16) {
            return null;
        }
        $widths = [];
        $sources = [];
        foreach ($variants as $image) {
            if (! self::variant($image) || isset($widths[(int) $image['width']]) || isset($sources[$image['src']])) {
                return null;
            }
            $widths[(int) $image['width']] = true;
            $sources[$image['src']] = true;
        }
        if ($variants !== [] && (! self::string($asset['sizes'] ?? null, 2048) || preg_match('/[^\t\n\r ]/', $asset['sizes']) !== 1)) {
            return null;
        }
        $result = ['src' => $asset['src'], 'width' => (int) $asset['width'], 'height' => (int) $asset['height']];
        if ($variants !== []) {
            usort($variants, static fn (array $a, array $b): int => $a['width'] <=> $b['width']);
            $result['srcSet'] = implode(', ', array_map(static fn (array $image): string => $image['src'].' '.(int) $image['width'].'w', $variants));
            $result['sizes'] = $asset['sizes'];
        }

        return $result;
    }

    private static function string(mixed $value, int $max): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= $max && preg_match('//u', $value) === 1;
    }

    private static function dimension(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && floor($value) === (float) $value && $value > 0 && $value <= 1000000;
    }

    private static function source(mixed $value): bool
    {
        if (! self::string($value, 4096) || preg_match('/[\x00-\x20\x7f,]/', $value) === 1 || str_contains($value, '\\')) {
            return false;
        }

        return str_starts_with($value, '/') ? ! str_starts_with($value, '//') : preg_match('~\Ahttps?://[^/?#]+(?:[/?#]|\z)~i', $value) === 1;
    }

    private static function variant(mixed $value): bool
    {
        return is_array($value) && self::source($value['src'] ?? null) && self::dimension($value['width'] ?? null) && self::dimension($value['height'] ?? null);
    }
}
