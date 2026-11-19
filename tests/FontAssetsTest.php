<?php

declare(strict_types=1);

namespace FancyCms\Tests\FontAssetsTest;

use FancyCms\Render\CssEmitter;
use FancyCms\Render\PageRenderer;
use Throwable;

test('non ascii cannot be normalized into valid unicode range hex', function () {
    $value = asset();
    $value['faces'][0]['unicodeRange'] = "U+\u{fb00}";
    refusal(static fn () => CssEmitter::emit(doc(), static fn () => $value), 'invalid-asset');
});

test('a host callback cannot mutate the normalized request used for validation', function () {
    $css = CssEmitter::emit(doc(), static function (array &$ref): array {
        $ref['family'] = 'Accidental mutation';

        return asset();
    });
    $this->assertStringContainsString('cms-font-'.str_repeat('a', 64), $css);
});

function reference(): array {
    return ['family' => 'Inter', 'source' => 'bundled', 'weights' => [700, 400, 600]];
}

function asset(): array {
    return ['family' => 'Inter', 'source' => 'bundled', 'manifestSha256' => str_repeat('a', 64), 'faces' => [['src' => '/site-fonts/'.str_repeat('b', 64).'.woff2', 'sha256' => str_repeat('b', 64), 'weight' => [100, 900], 'style' => 'normal', 'unicodeRange' => 'U+0000-00FF, U+0131']]];
}

function doc(mixed $reference = null): array {
    return ['theme' => ['font' => ['body' => $reference ?? reference()]], 'breakpoints' => ['base'], 'nodes' => []];
}

function refusal(callable $run, string $reason): void {
    $error = null;
    try {
        $run();
    } catch (Throwable $caught) {
        $error = $caught;
    }
    test()->assertNotNull($error, 'Expected an explicit font refusal');
    test()->assertSame('FancyCms\\Exceptions\\FontResolutionException', $error::class);
    test()->assertSame($reason, $error->reason);
    test()->assertSame('CMS font resolution failed: '.$reason, $error->getMessage());
}

test('normalizes once and emits the pinned alias', function () {
    $doc = doc();
    $doc['theme']['font']['heading'] = reference();
    $calls = [];
    $css = CssEmitter::emit($doc, static function ($ref) use (&$calls) {
        $calls[] = $ref;

        return asset();
    }, 'block');
    $this->assertSame([['family' => 'Inter', 'source' => 'bundled', 'weights' => [400, 600, 700], 'styles' => ['normal']]], $calls);
    $alias = 'cms-font-'.str_repeat('a', 64);
    $this->assertStringContainsString("@font-face {\n  font-display: block;\n  font-family: \"$alias\";\n  font-style: normal;\n  font-weight: 100 900;\n  src: url(\"/site-fonts/".str_repeat('b', 64).".woff2\") format(\"woff2\");\n  unicode-range: U+0000-00FF, U+0131;\n}", $css);
    $this->assertSame(1, substr_count($css, '@font-face'));
    $this->assertStringContainsString('--cms-font-body: "'.$alias.'";', $css);
    $this->assertStringContainsString('--cms-font-heading: "'.$alias.'";', $css);
    $this->assertStringNotContainsString('--cms-font-body-family', $css);
    $this->assertStringContainsString("font-optical-sizing: none;\n  font-synthesis: none;", $css);
    $this->assertStringNotContainsString('local(', $css);
});

test('static italic shards keep their order and default to swap', function () {
    $ref = ['family' => 'Source Serif 4', 'source' => 'google', 'weights' => [400.0], 'styles' => ['italic']];
    $faces = [['src' => '/fonts/latin-ext.woff2', 'sha256' => str_repeat('b', 64), 'weight' => 400, 'style' => 'italic', 'unicodeRange' => 'U+0100-024F'], ['src' => '/fonts/latin.woff2', 'sha256' => str_repeat('b', 64), 'weight' => 400, 'style' => 'italic', 'unicodeRange' => 'U+0000-00FF']];
    $css = CssEmitter::emit(doc($ref), static fn () => [...$ref, 'manifestSha256' => str_repeat('a', 64), 'faces' => $faces]);
    $this->assertStringContainsString('font-display: swap;', $css);
    $this->assertStringContainsString('font-weight: 400;', $css);
    $this->assertStringContainsString('font-style: italic;', $css);
    $this->assertLessThan(strpos($css, 'latin.woff2'), strpos($css, 'latin-ext.woff2'));
});

test('legacy string css is unchanged and never resolves', function () {
    $doc = doc();
    $doc['theme'] = ['font' => ['body' => 'Inter, system-ui', 'heading' => 'Georgia, serif']];
    $css = CssEmitter::emit($doc, static function () {
        $this->fail('Legacy strings must not resolve');
    });
    $this->assertSame(CssEmitter::BASE_CSS."\n\n:root {\n  --cms-font-body: Inter, system-ui;\n  --cms-font-heading: Georgia, serif;\n}\n", $css);
});

test('missing font and resolver are explicit', function () {
    refusal(static fn () => CssEmitter::emit(doc()), 'missing-resolver');
    refusal(static fn () => CssEmitter::emit(doc(), static fn () => null), 'missing-font');
    refusal(static fn () => CssEmitter::emit(doc(), static function () {
        throw new \RuntimeException('secret endpoint');
    }), 'resolver-failed');
});

function badReferences(): iterable {
    foreach ([[], ['family' => 'https://evil.test/font'], ['family' => ' Bad'], ['family' => "Bad\nFamily"], ['source' => 'remote'], ['weights' => []], ['weights' => [400, 400]], ['weights' => [0]], ['weights' => [1001]], ['weights' => [400.5]], ['weights' => ['400']], ['styles' => ['oblique']], ['styles' => ['normal', 'normal']], ['url' => '/authored.woff2']] as $patch) {
        yield [$patch === [] ? [] : [...reference(), ...$patch]];
    }
}

test('malformed reference is refused', function (mixed $ref) {
    refusal(static fn () => CssEmitter::emit(doc($ref), static fn () => asset()), 'invalid-reference');
})->with(fn (): iterable => badReferences());

function badUrls(): iterable {
    foreach (['https://fonts.gstatic.com/a.woff2', '//evil.test/a.woff2', 'data:font/woff2;base64,AAAA', 'blob:fake', 'file:///a.woff2', '/\\evil/a.woff2', '/fonts/a.woff2?x=1', '/fonts/a.woff2#x', '/fonts/%2f%2fevil.woff2', '/fonts/../a.woff2', '/fonts//a.woff2', '/fonts/a b.woff2', "/fonts/a\n.woff2", '/fonts/a".woff2', '/fonts/a</style>.woff2'] as $src) {
        yield [$src];
    }
}

test('external or noncanonical url is refused', function (string $src) {
    $value = asset();
    $value['faces'][0]['src'] = $src;
    refusal(static fn () => CssEmitter::emit(doc(), static fn () => $value), 'invalid-asset');
})->with(fn (): iterable => badUrls());

function badAssets(): iterable {
    foreach ([['manifestSha256' => 'short'], ['family' => 'Other'], ['source' => 'google'], ['faces' => []], ['faces' => [[...asset()['faces'][0], 'sha256' => 'bad']]], ['faces' => [[...asset()['faces'][0], 'weight' => [900, 100]]]], ['faces' => [[...asset()['faces'][0], 'style' => 'oblique']]], ['faces' => [[...asset()['faces'][0], 'unicodeRange' => 'U+110000']]], ['faces' => [[...asset()['faces'][0], 'unicodeRange' => 'U+0100-0000']]], ['faces' => [[...asset()['faces'][0], 'unicodeRange' => 'U+0000; color:red']]]] as $patch) {
        yield [$patch];
    }
}

test('malformed host metadata is refused', function (array $patch) {
    refusal(static fn () => CssEmitter::emit(doc(), static fn () => [...asset(), ...$patch]), 'invalid-asset');
})->with(fn (): iterable => badAssets());

test('requested faces must exist and limits apply', function () {
    $value = asset();
    $value['faces'][0]['weight'] = 400;
    refusal(static fn () => CssEmitter::emit(doc(), static fn () => $value), 'missing-face');
    refusal(static fn () => CssEmitter::emit(doc([...reference(), 'styles' => ['italic']]), static fn () => asset()), 'missing-face');
    refusal(static fn () => CssEmitter::emit(doc([...reference(), 'weights' => range(1, 17)]), static fn () => asset()), 'invalid-reference');
    $value = asset();
    $value['faces'] = array_fill(0, 257, $value['faces'][0]);
    refusal(static fn () => CssEmitter::emit(doc(), static fn () => $value), 'invalid-asset');
    $doc = doc();
    $doc['theme']['font'] = [];
    for ($i = 0; $i < 9; $i++) {
        $doc['theme']['font']['font'.$i] = ['family' => 'Family '.$i, 'source' => 'google', 'weights' => [400]];
    }
    refusal(static fn () => CssEmitter::emit($doc, static fn () => asset()), 'too-many-fonts');
});

test('display policy and page renderer use the same resolver', function () {
    refusal(static fn () => CssEmitter::emit(doc(), static fn () => asset(), 'optional'), 'invalid-display');
    $out = PageRenderer::render(doc(), [], null, static fn () => asset(), 'block');
    $this->assertSame(CssEmitter::emit(doc(), static fn () => asset(), 'block'), $out['css']);
    $this->assertStringContainsString('font-display: block;', $out['css']);
    refusal(static fn () => PageRenderer::render(doc()), 'missing-resolver');
});

test('trailing controls and non css unicode whitespace are refused', function () {
    foreach (["/fonts/a.woff2\n", "/fonts/a.woff2\r\n"] as $src) {
        $value = asset();
        $value['faces'][0]['src'] = $src;
        refusal(static fn () => CssEmitter::emit(doc(), static fn () => $value), 'invalid-asset');
    }
    foreach (["\0U+0000-00FF", "\u{00a0}U+0000-00FF"] as $unicode) {
        $value = asset();
        $value['faces'][0]['unicodeRange'] = $unicode;
        refusal(static fn () => CssEmitter::emit(doc(), static fn () => $value), 'invalid-asset');
    }
    $value = asset();
    $value['manifestSha256'] .= "\n";
    refusal(static fn () => CssEmitter::emit(doc(), static fn () => $value), 'invalid-asset');
});
