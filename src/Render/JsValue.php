<?php

declare(strict_types=1);

namespace FancyCms\Render;

/** @internal JavaScript scalar formatting shared by the two PHP emitters. */
final class JsValue
{
    public static function string(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if ($value === true) {
            return 'true';
        }
        if ($value === false) {
            return 'false';
        }
        if (is_int($value)) {
            return abs($value) > 9007199254740991 ? self::number((float) $value) : (string) $value;
        }
        if (is_float($value)) {
            return self::number($value);
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_array($value) && array_is_list($value)) {
            return implode(',', array_map(static fn ($v): string => $v === null ? '' : self::string($v), $value));
        }

        return '[object Object]';
    }

    public static function text(mixed $value): string
    {
        return $value === null ? '' : self::string($value);
    }

    public static function number(float $value): string
    {
        if (is_nan($value)) {
            return 'NaN';
        }
        if (! is_finite($value)) {
            return $value < 0 ? '-Infinity' : 'Infinity';
        }
        if ($value == 0) {
            return '0';
        }
        // Search increasing significant precision. Around an exact power of
        // two the binary rounding interval is asymmetric: the closest decimal
        // can miss it while its adjacent decimal still round-trips. Check both
        // neighbours before increasing precision. No global precision/locale
        // state changes and no integer conversion of the coefficient.
        $negative = $value < 0;
        for ($precision = 1; $precision <= 17; $precision++) {
            $nearest = preg_split('/[eE]/', sprintf('%.*h', $precision, abs($value)));
            $parts = explode('.', $nearest[0]);
            $coefficient = implode('', $parts);
            $leading = strspn($coefficient, '0');
            $position = strlen($parts[0]) + (int) ($nearest[1] ?? 0) - $leading;
            $coefficient = str_pad(rtrim(substr($coefficient, $leading), '0'), $precision, '0');
            $power = $position - strlen($coefficient);
            foreach ([$coefficient, self::stepDigits($coefficient, 1), self::stepDigits($coefficient, -1)] as $digits) {
                $s = ($negative ? '-' : '').$digits.'e'.$power;
                if ((float) $s === $value) {
                    break 2;
                }
            }
        }
        if (! str_contains($s, 'e') && ! str_contains($s, 'E')) {
            return $s;
        }
        [$mantissa, $exponent] = preg_split('/[eE]/', $s);
        $negative = str_starts_with($mantissa, '-');
        $mantissa = ltrim($mantissa, '-');
        $parts = explode('.', $mantissa);
        $digits = rtrim(implode('', $parts), '0');
        $position = strlen($parts[0]) + (int) $exponent;
        if (abs($value) >= 1e-6 && abs($value) < 1e21) {
            if ($position <= 0) {
                $body = '0.'.str_repeat('0', -$position).$digits;
            } elseif ($position >= strlen($digits)) {
                $body = $digits.str_repeat('0', $position - strlen($digits));
            } else {
                $body = substr($digits, 0, $position).'.'.substr($digits, $position);
            }
        } else {
            $scientificExponent = $position - 1;
            $body = $digits[0].(strlen($digits) > 1 ? '.'.substr($digits, 1) : '').'e'.($scientificExponent >= 0 ? '+' : '').$scientificExponent;
        }

        return ($negative ? '-' : '').$body;
    }

    private static function stepDigits(string $digits, int $step): string
    {
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $next = ord($digits[$i]) - 48 + $step;
            if ($next >= 0 && $next <= 9) {
                $digits[$i] = chr(48 + $next);
                $step = 0;
                break;
            }
            $digits[$i] = $step > 0 ? '0' : '9';
        }
        if ($step > 0) {
            $digits = '1'.$digits;
        }
        $digits = ltrim($digits, '0');

        return $digits === '' ? '0' : $digits;
    }

    public static function truthy(mixed $value): bool
    {
        if ($value === null || $value === false || $value === '') {
            return false;
        }
        if (is_int($value) || is_float($value)) {
            return $value != 0 && ! is_nan((float) $value);
        }

        return true;
    }

    /** Object.entries enumerates array-index keys first, then other own keys. */
    public static function entries(array|\stdClass $value): array
    {
        $indices = $rest = [];
        foreach ((array) $value as $key => $item) {
            $name = (string) $key;
            $index = (int) $name;
            if ($index >= 0 && $index < 4294967295 && (string) $index === $name) {
                $indices[$key] = $item;
            } else {
                $rest[$key] = $item;
            }
        }
        uksort($indices, static fn ($a, $b): int => (int) $a <=> (int) $b);

        return $indices + $rest;
    }

    /**
     * JavaScript's String.prototype.trim: its WhiteSpace and LineTerminator
     * code points, U+0009-U+000D, U+0020, U+00A0, U+1680, U+2000-U+200A,
     * U+2028, U+2029, U+202F, U+205F, U+3000 and U+FEFF.
     *
     * A loop, not the `^[…]+|[…]+$` regex this used to be: the unanchored
     * `[…]+$` retries from every character of a long whitespace run, which is
     * quadratic, and past PCRE's limit returned null, so an author's href read
     * as empty. Invalid UTF-8 still gives '', as that regex's /u did.
     */
    public static function trim(string $value): string
    {
        if (preg_match('//u', $value) !== 1) {
            return '';
        }
        $start = 0;
        $end = strlen($value);
        while ($start < $end && ($n = self::whitespaceAt($value, $start)) > 0) {
            $start += $n;
        }
        while ($end > $start && ($n = self::whitespaceBefore($value, $end)) > 0) {
            $end -= $n;
        }

        return substr($value, $start, $end - $start);
    }

    /** The UTF-8 encodings of the code points JS trims, by byte length. */
    private const WHITESPACE = [
        1 => ["\t" => true, "\n" => true, "\v" => true, "\f" => true, "\r" => true, ' ' => true],
        2 => ["\u{00A0}" => true],
        3 => [
            "\u{1680}" => true, "\u{2000}" => true, "\u{2001}" => true, "\u{2002}" => true, "\u{2003}" => true,
            "\u{2004}" => true, "\u{2005}" => true, "\u{2006}" => true, "\u{2007}" => true, "\u{2008}" => true,
            "\u{2009}" => true, "\u{200A}" => true, "\u{2028}" => true, "\u{2029}" => true, "\u{202F}" => true,
            "\u{205F}" => true, "\u{3000}" => true, "\u{FEFF}" => true,
        ],
    ];

    /** Byte length of the trimmable character starting at $i, or 0. */
    private static function whitespaceAt(string $value, int $i): int
    {
        foreach (self::WHITESPACE as $length => $set) {
            if (isset($set[substr($value, $i, $length)])) {
                return $length;
            }
        }

        return 0;
    }

    /** Byte length of the trimmable character ending just before $end, or 0. */
    private static function whitespaceBefore(string $value, int $end): int
    {
        foreach (self::WHITESPACE as $length => $set) {
            if ($end >= $length && isset($set[substr($value, $end - $length, $length)])) {
                return $length;
            }
        }

        return 0;
    }
}
