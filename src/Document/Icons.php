<?php

declare(strict_types=1);

namespace FancyCms\Document;

/** Closed native icon catalogue. Documents never supply SVG markup or paths. */
final class Icons
{
    private static ?array $index = null;

    private static array $glyphs = [];

    private static function index(): array
    {
        return self::$index ??= json_decode(file_get_contents(dirname(__DIR__, 2).'/resources/icons/index.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::index());
    }

    public static function search(string $query = '', int $cursor = 0): array
    {
        if (strlen($query) > 128 || preg_match('/^[A-Za-z0-9 -]*$/D', $query) !== 1 || $cursor < 0) {
            throw new \InvalidArgumentException('Invalid icon search.');
        }
        $words = preg_split('/[ -]+/', strtolower(trim($query, ' -')), -1, PREG_SPLIT_NO_EMPTY);
        $matches = array_values(array_filter(self::names(), static function (string $name) use ($words): bool {
            foreach ($words as $word) {
                if (! str_contains($name, $word)) {
                    return false;
                }
            }

            return true;
        }));
        $exact = implode('-', $words);
        if (isset(self::index()[$exact])) {
            $matches = [$exact, ...array_values(array_diff($matches, [$exact]))];
        }
        $metadata = json_decode(file_get_contents(dirname(__DIR__, 2).'/resources/icons/metadata.json'), true, 512, JSON_THROW_ON_ERROR);
        $names = array_slice($matches, $cursor, 20);

        return ['source' => 'lucide-react@'.$metadata['version'], 'names' => $names, 'total' => count($matches), 'nextCursor' => $cursor + count($names) < count($matches) ? $cursor + count($names) : null];
    }

    public static function validProps(mixed $props): bool
    {
        if (! is_array($props) || array_diff(array_keys($props), ['name', 'decorative', 'label']) !== []) {
            return false;
        }
        if (! is_string($props['name'] ?? null) || ! isset(self::index()[$props['name']]) || ! is_bool($props['decorative'] ?? null)) {
            return false;
        }
        if (array_key_exists('label', $props) && (! is_string($props['label']) || strlen($props['label']) > 1024 || preg_match('//u', $props['label']) !== 1)) {
            return false;
        }

        return $props['decorative'] || (isset($props['label']) && preg_match('/[^\x09-\x0D ]/u', $props['label']) === 1);
    }

    public static function glyph(string $name): ?array
    {
        $canonical = self::index()[$name] ?? null;
        if ($canonical === null) {
            return null;
        }

        // The filename comes exclusively from the generated closed alias map.
        return self::$glyphs[$canonical] ??= json_decode(file_get_contents(dirname(__DIR__, 2).'/resources/icons/glyphs/'.$canonical.'.json'), true, 512, JSON_THROW_ON_ERROR);
    }
}
