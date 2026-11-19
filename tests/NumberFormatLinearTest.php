<?php

declare(strict_types=1);

namespace FancyCms\Tests\NumberFormatLinearTest;

use FancyCms\Render\NumberFormat;

/**
 * The digit-string helpers NumberFormat shares with fancy-cms-ui's
 * render/number-format.ts are linear. A finite number's digits are bounded
 * (about 330), but the helpers take any string, so they are held to linear time
 * on one far longer than that, and to the bytes the regexes they replace gave.
 */

const N = 100_000;

test('trims trailing zeros from a long run quickly and correctly', function () {
    $run = str_repeat('0', N).'1';
    $start = hrtime(true);
    $this->assertSame($run, NumberFormat::trimTrailingZeros($run));
    $this->assertSame('1', NumberFormat::trimTrailingZeros('1'.str_repeat('0', N)));
    $this->assertSame('', NumberFormat::trimTrailingZeros(str_repeat('0', N)));
    $this->assertLessThan(1000, (hrtime(true) - $start) / 1e6);
});

test('groups a long integer quickly and correctly', function () {
    $int = '1'.str_repeat('0', N - 1);
    $start = hrtime(true);
    $out = NumberFormat::groupThousands($int);
    $this->assertLessThan(1000, (hrtime(true) - $start) / 1e6);
    $this->assertSame(N + intdiv(N - 1, 3), strlen($out));
    $this->assertSame($int, str_replace(',', '', $out));
    $this->assertStringStartsWith('1,000,', $out);
});

test('groups exactly as the lookahead regex did', function () {
    for ($len = 1; $len <= 12; $len++) {
        $int = substr('123456789012', 0, $len);
        $this->assertSame(preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $int), NumberFormat::groupThousands($int), $int);
    }
});

test('still formats the longest digit strings a number can have', function () {
    $spec = NumberFormat::spec(null, null, null);
    $this->assertSame('1'.str_repeat('0', 300), str_replace(',', '', NumberFormat::format(1e300, $spec)));
    $this->assertSame('0.000000', NumberFormat::format(5e-324, NumberFormat::spec(null, null, 6)));
    $this->assertSame('0', NumberFormat::format(1.5e-300, $spec));
});
