<?php

declare(strict_types=1);

namespace FancyCms\Tests\JsValueTrimTest;

use FancyCms\Render\JsValue;
use FancyCms\Render\PageRenderer;

/**
 * JsValue::trim is JavaScript's String.prototype.trim (the React renderer
 * trims an author's href with it), and it is linear: an author-written href is
 * consumer input, so a long whitespace run must neither stall the renderer nor
 * read as empty.
 */

/**
 * PCRE's JIT happened to run the old `[…]+$` regex in linear time; the
 * interpreter, which PHP uses with pcre.jit=0 or when JIT memory cannot be
 * allocated, took 105 s on a 100k run and returned null (an empty href)
 * past the backtrack limit. These tests run as such a host does.
 */
beforeEach(function () {
    $this->jit = false;
    $this->jit = ini_get('pcre.jit');
    ini_set('pcre.jit', '0');
});

afterEach(function () {
    if ($this->jit !== false) {
        ini_set('pcre.jit', $this->jit);
    }
});

/** Every code point JS trims. */
const TRIMMED = [
    0x09, 0x0A, 0x0B, 0x0C, 0x0D, 0x20, 0xA0, 0x1680,
    0x2000, 0x2001, 0x2002, 0x2003, 0x2004, 0x2005, 0x2006, 0x2007, 0x2008, 0x2009, 0x200A,
    0x2028, 0x2029, 0x202F, 0x205F, 0x3000, 0xFEFF,
];

/** Near misses JS keeps: NUL, the C1 NEL, Mongolian vowel separator, ZWSP, ZWNJ, word joiner. */
const KEPT = [0x00, 0x1F, 0x85, 0x180E, 0x200B, 0x200C, 0x2060];

test('trims exactly the code points javascript trims', function () {
    foreach (TRIMMED as $cp) {
        $c = mb_chr($cp, 'UTF-8');
        $this->assertSame('a'.$c.'b', JsValue::trim($c.$c.'a'.$c.'b'.$c.$c), sprintf('U+%04X', $cp));
    }
    foreach (KEPT as $cp) {
        $c = mb_chr($cp, 'UTF-8');
        $this->assertSame($c.'a'.$c, JsValue::trim($c.'a'.$c), sprintf('U+%04X', $cp));
    }
});

test('edge inputs', function () {
    $this->assertSame('', JsValue::trim(''));
    $this->assertSame('', JsValue::trim(" \u{3000}\n\u{FEFF}"));
    $this->assertSame('x', JsValue::trim('x'));
    $this->assertSame("a \u{00A0} b", JsValue::trim("\u{2028}a \u{00A0} b\u{2029}"));
    $this->assertSame('', JsValue::trim("a\xC3"), 'invalid UTF-8 reads as empty, as the /u regex did');
});

test('a long whitespace run inside the value is linear and kept', function () {
    $inner = 'x'.str_repeat(' ', 1_000_000).'y';
    $start = hrtime(true);
    $this->assertSame($inner, JsValue::trim(" \u{3000}".$inner."\u{2028} "));
    $this->assertLessThan(1000, (hrtime(true) - $start) / 1e6);
});

test('a long href with a whitespace run still renders', function () {
    $href = '/a'.str_repeat(' ', 1_000_000).'b';
    $start = hrtime(true);
    $html = PageRenderer::render([
        'id' => 'p',
        'nodes' => ['l' => ['id' => 'l', 'type' => 'link', 'parent' => null, 'order' => 'a', 'props' => ['label' => 'go', 'href' => ' '.$href.' '], 'style' => ['base' => []]]],
        'breakpoints' => ['base'],
    ])['html'];
    $this->assertLessThan(2000, (hrtime(true) - $start) / 1e6);
    $this->assertStringContainsString('href="'.$href.'"', $html);
});
