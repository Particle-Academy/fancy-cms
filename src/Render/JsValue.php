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

    public static function trim(string $value): string
    {
        return preg_replace('/^[\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+|[\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+$/u', '', $value) ?? '';
    }
}
