<?php

declare(strict_types=1);

namespace FancyCms\Render;

/**
 * The PHP twin of fancy-cms-ui's render/number-format.ts, byte for byte.
 *
 * Not NumberFormatter: its output follows the ICU build PHP was linked
 * against, which is not the ICU a browser carries. This rounds on the digits
 * of the shortest round-trip decimal string (JsValue::number, which reproduces
 * JavaScript's String(n)), half away from zero, so the two renderers agree on
 * every value, 1.005 included.
 *
 * @internal
 */
final class NumberFormat
{
    private const SYMBOLS = ['USD' => '$', 'EUR' => '€', 'GBP' => '£', 'JPY' => '¥', 'INR' => '₹', 'CAD' => 'CA$', 'AUD' => 'A$'];

    private const ZERO_DECIMAL = ['JPY', 'KRW'];

    /** A finite number from a number or a plain decimal string; anything else is null. */
    public static function toNumber(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            $n = (float) $value;

            return is_finite($n) ? $n : null;
        }
        if (is_string($value) && preg_match('/\A-?\d+(\.\d+)?\z/', $value) === 1) {
            $n = (float) $value;

            return is_finite($n) ? $n : null;
        }

        return null;
    }

    /** A fixed-decimals value: an integer 0..6 (2.0 counts, as it does in JavaScript). */
    public static function decimals(mixed $value): ?int
    {
        if (is_int($value) || (is_float($value) && is_finite($value) && floor($value) === $value)) {
            $n = (int) $value;

            return $n >= 0 && $n <= 6 && (float) $n === (float) $value ? $n : null;
        }

        return null;
    }

    /**
     * @return array{format:string,currency:string,decimals:?int}
     */
    public static function spec(mixed $format, mixed $currency, mixed $decimals, string $fallback = 'number'): array
    {
        return [
            'format' => is_string($format) && in_array($format, ['number', 'currency', 'percent'], true) ? $format : $fallback,
            'currency' => is_string($currency) && preg_match('/\A[A-Z]{3}\z/', $currency) === 1 ? $currency : 'USD',
            'decimals' => self::decimals($decimals),
        ];
    }

    /** @param array{format:string,currency:string,decimals:?int} $spec */
    public static function format(float $n, array $spec): string
    {
        return self::formatted($n, $spec)['text'];
    }

    /** @param array{format:string,currency:string,decimals:?int} $spec */
    public static function signed(float $n, array $spec): string
    {
        $f = self::formatted($n, $spec);

        return $f['negative'] || $f['zero'] ? $f['text'] : '+'.$f['text'];
    }

    /**
     * @param  array{format:string,currency:string,decimals:?int}  $spec
     * @return array{text:string,negative:bool,zero:bool}
     */
    private static function formatted(float $n, array $spec): array
    {
        [$negative, $int, $frac] = self::digits($n);
        if ($spec['format'] === 'percent') {
            $frac = str_pad($frac, 2, '0');
            $int = self::stripLeading($int.substr($frac, 0, 2));
            $frac = (string) substr($frac, 2);
        }
        $auto = $spec['format'] === 'percent' ? 1 : 2;
        $places = $spec['decimals'] ?? ($spec['format'] === 'currency' ? (in_array($spec['currency'], self::ZERO_DECIMAL, true) ? 0 : 2) : $auto);
        $fixed = $spec['decimals'] !== null || $spec['format'] === 'currency';
        if (strlen($frac) > $places) {
            $keep = $int.substr($frac, 0, $places);
            if (ord($frac[$places]) >= 53) {
                $keep = self::increment($keep);
            }
            $int = self::stripLeading(substr($keep, 0, strlen($keep) - $places));
            $frac = (string) substr($keep, strlen($keep) - $places);
        }
        $frac = $fixed ? str_pad($frac, $places, '0') : rtrim($frac, '0');
        $zero = preg_match('/\A0*\z/', $int.$frac) === 1;
        $negative = $negative && ! $zero;
        $body = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $int).($frac !== '' ? '.'.$frac : '');
        $sign = $negative ? '-' : '';
        $text = match ($spec['format']) {
            'percent' => $sign.$body.'%',
            'currency' => $sign.(self::SYMBOLS[$spec['currency']] ?? $spec['currency'].' ').$body,
            default => $sign.$body,
        };

        return ['text' => $text, 'negative' => $negative, 'zero' => $zero];
    }

    /** @return array{0:bool,1:string,2:string} sign, integer digits, fraction digits */
    private static function digits(float $n): array
    {
        $s = JsValue::number($n);
        $negative = str_starts_with($s, '-');
        $body = $negative ? substr($s, 1) : $s;
        $parts = explode('e', $body);
        $mantissa = explode('.', $parts[0]);
        $ip = $mantissa[0];
        $fp = $mantissa[1] ?? '';
        $digits = $ip.$fp;
        $point = strlen($ip) + (isset($parts[1]) ? (int) $parts[1] : 0);
        if ($point <= 0) {
            $int = '0';
            $frac = str_repeat('0', -$point).$digits;
        } elseif ($point >= strlen($digits)) {
            $int = $digits.str_repeat('0', $point - strlen($digits));
            $frac = '';
        } else {
            $int = substr($digits, 0, $point);
            $frac = substr($digits, $point);
        }

        return [$negative, self::stripLeading($int), $frac];
    }

    private static function stripLeading(string $int): string
    {
        $s = ltrim($int, '0');

        return $s === '' ? '0' : $s;
    }

    private static function increment(string $digits): string
    {
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            if ($digits[$i] === '9') {
                $digits[$i] = '0';
            } else {
                $digits[$i] = chr(ord($digits[$i]) + 1);

                return $digits;
            }
        }

        return '1'.$digits;
    }
}
