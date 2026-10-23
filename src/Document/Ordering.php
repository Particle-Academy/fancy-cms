<?php

declare(strict_types=1);

namespace FancyCms\Document;

use InvalidArgumentException;

/** First-party port of fancy-doc-commons' fractionalKey; no numeric fractions. */
final class Ordering
{
    private const BASE = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public static function keyBetween(?string $a, ?string $b): string
    {
        $a ??= '';
        if ($b === null) {
            return $a.'V';
        }
        if (self::compare($a, $b) >= 0) {
            throw new InvalidArgumentException('fractionalKey: lower bound "'.$a.'" is not below upper bound "'.$b.'"');
        }
        $left = self::units($a);
        $right = self::units($b);
        $out = '';
        $i = 0;
        for (; ;) {
            $ca = isset($left[$i]) ? self::value($left[$i]) : 0;
            $cb = isset($right[$i]) ? self::value($right[$i]) : 62;
            if ($cb - $ca > 1) {
                return $out.self::BASE[$ca + intdiv($cb - $ca, 2)];
            }
            if ($ca === $cb) {
                $out .= self::BASE[$ca];
                $i++;

                continue;
            }
            $out .= self::BASE[$ca];
            $i++;
            for (; ;) {
                $da = isset($left[$i]) ? self::value($left[$i]) : 0;
                if (62 - $da > 1) {
                    return $out.self::BASE[$da + intdiv(62 - $da, 2)];
                }
                $out .= self::BASE[$da];
                $i++;
            }
        }
    }

    public static function fractionalKey(?string $a, ?string $b): string
    {
        return self::keyBetween($a, $b);
    }

    /** JavaScript orders strings by UTF-16 code units, including astral ids. */
    public static function compare(string $a, string $b): int
    {
        if (! preg_match('/[\x80-\xff]/', $a.$b)) {
            return strcmp($a, $b);
        }

        return strcmp(self::utf16($a), self::utf16($b));
    }

    private static function value(int $unit): int
    {
        $i = $unit < 128 ? strpos(self::BASE, chr($unit)) : false;

        return $i === false ? 0 : $i;
    }

    private static function units(string $s): array
    {
        return array_values(unpack('n*', self::utf16($s)) ?: []);
    }

    private static function utf16(string $s): string
    {
        $out = '';
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $c = ord($s[$i]);
            if ($c < 128) {
                $cp = $c;
            } elseif ($c < 224 && $i + 1 < $n) {
                $cp = (($c & 31) << 6) | (ord($s[++$i]) & 63);
            } elseif ($c < 240 && $i + 2 < $n) {
                $cp = (($c & 15) << 12) | ((ord($s[++$i]) & 63) << 6) | (ord($s[++$i]) & 63);
            } elseif ($i + 3 < $n) {
                $cp = (($c & 7) << 18) | ((ord($s[++$i]) & 63) << 12) | ((ord($s[++$i]) & 63) << 6) | (ord($s[++$i]) & 63);
            } else {
                $cp = 0xFFFD;
            }
            if ($cp > 0xFFFF) {
                $cp -= 0x10000;
                $out .= pack('nn', 0xD800 + ($cp >> 10), 0xDC00 + ($cp & 1023));
            } else {
                $out .= pack('n', $cp);
            }
        }

        return $out;
    }
}
