<?php

declare(strict_types=1);

namespace FancyCms\Tests;

use FancyCms\Render\NumberFormat;
use PHPUnit\Framework\TestCase;

/**
 * The digit-string helpers NumberFormat shares with fancy-cms-ui's
 * render/number-format.ts are linear. A finite number's digits are bounded
 * (about 330), but the helpers take any string, so they are held to linear time
 * on one far longer than that, and to the bytes the regexes they replace gave.
 */
final class NumberFormatLinearTest extends TestCase
{
    private const N = 100_000;

    public function test_trims_trailing_zeros_from_a_long_run_quickly_and_correctly(): void
    {
        $run = str_repeat('0', self::N).'1';
        $start = hrtime(true);
        self::assertSame($run, NumberFormat::trimTrailingZeros($run));
        self::assertSame('1', NumberFormat::trimTrailingZeros('1'.str_repeat('0', self::N)));
        self::assertSame('', NumberFormat::trimTrailingZeros(str_repeat('0', self::N)));
        self::assertLessThan(1000, (hrtime(true) - $start) / 1e6);
    }

    public function test_groups_a_long_integer_quickly_and_correctly(): void
    {
        $int = '1'.str_repeat('0', self::N - 1);
        $start = hrtime(true);
        $out = NumberFormat::groupThousands($int);
        self::assertLessThan(1000, (hrtime(true) - $start) / 1e6);
        self::assertSame(self::N + intdiv(self::N - 1, 3), strlen($out));
        self::assertSame($int, str_replace(',', '', $out));
        self::assertStringStartsWith('1,000,', $out);
    }

    public function test_groups_exactly_as_the_lookahead_regex_did(): void
    {
        for ($len = 1; $len <= 12; $len++) {
            $int = substr('123456789012', 0, $len);
            self::assertSame(preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $int), NumberFormat::groupThousands($int), $int);
        }
    }

    public function test_still_formats_the_longest_digit_strings_a_number_can_have(): void
    {
        $spec = NumberFormat::spec(null, null, null);
        self::assertSame('1'.str_repeat('0', 300), str_replace(',', '', NumberFormat::format(1e300, $spec)));
        self::assertSame('0.000000', NumberFormat::format(5e-324, NumberFormat::spec(null, null, 6)));
        self::assertSame('0', NumberFormat::format(1.5e-300, $spec));
    }
}
