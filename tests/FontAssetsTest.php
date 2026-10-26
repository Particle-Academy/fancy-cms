<?php

declare(strict_types=1);

namespace FancyCms\Tests;

use FancyCms\Render\CssEmitter;
use FancyCms\Render\PageRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

final class FontAssetsTest extends TestCase
{
    public function test_non_ascii_cannot_be_normalized_into_valid_unicode_range_hex(): void
    {
        $value = self::asset();
        $value['faces'][0]['unicodeRange'] = "U+\u{fb00}";
        $this->refusal(static fn () => CssEmitter::emit(self::doc(), static fn () => $value), 'invalid-asset');
    }

    public function test_a_host_callback_cannot_mutate_the_normalized_request_used_for_validation(): void
    {
        $css = CssEmitter::emit(self::doc(), static function (array &$ref): array {
            $ref['family'] = 'Accidental mutation';

            return self::asset();
        });
        self::assertStringContainsString('cms-font-'.str_repeat('a', 64), $css);
    }

    private static function reference(): array
    {
        return ['family' => 'Inter', 'source' => 'bundled', 'weights' => [700, 400, 600]];
    }

    private static function asset(): array
    {
        return ['family' => 'Inter', 'source' => 'bundled', 'manifestSha256' => str_repeat('a', 64), 'faces' => [['src' => '/site-fonts/'.str_repeat('b', 64).'.woff2', 'sha256' => str_repeat('b', 64), 'weight' => [100, 900], 'style' => 'normal', 'unicodeRange' => 'U+0000-00FF, U+0131']]];
    }

    private static function doc(mixed $reference = null): array
    {
        return ['theme' => ['font' => ['body' => $reference ?? self::reference()]], 'breakpoints' => ['base'], 'nodes' => []];
    }

    private function refusal(callable $run, string $reason): void
    {
        $error = null;
        try {
            $run();
        } catch (Throwable $caught) {
            $error = $caught;
        }
        self::assertNotNull($error, 'Expected an explicit font refusal');
        self::assertSame('FancyCms\\Exceptions\\FontResolutionException', $error::class);
        self::assertSame($reason, $error->reason);
        self::assertSame('CMS font resolution failed: '.$reason, $error->getMessage());
    }

    public function test_normalizes_once_and_emits_the_pinned_alias(): void
    {
        $doc = self::doc();
        $doc['theme']['font']['heading'] = self::reference();
        $calls = [];
        $css = CssEmitter::emit($doc, static function ($ref) use (&$calls) {
            $calls[] = $ref;

            return self::asset();
        }, 'block');
        self::assertSame([['family' => 'Inter', 'source' => 'bundled', 'weights' => [400, 600, 700], 'styles' => ['normal']]], $calls);
        $alias = 'cms-font-'.str_repeat('a', 64);
        self::assertStringContainsString("@font-face {\n  font-display: block;\n  font-family: \"$alias\";\n  font-style: normal;\n  font-weight: 100 900;\n  src: url(\"/site-fonts/".str_repeat('b', 64).".woff2\") format(\"woff2\");\n  unicode-range: U+0000-00FF, U+0131;\n}", $css);
        self::assertSame(1, substr_count($css, '@font-face'));
        self::assertStringContainsString('--cms-font-body: "'.$alias.'";', $css);
        self::assertStringContainsString('--cms-font-heading: "'.$alias.'";', $css);
        self::assertStringNotContainsString('--cms-font-body-family', $css);
        self::assertStringContainsString("font-optical-sizing: none;\n  font-synthesis: none;", $css);
        self::assertStringNotContainsString('local(', $css);
    }

    public function test_static_italic_shards_keep_their_order_and_default_to_swap(): void
    {
        $ref = ['family' => 'Source Serif 4', 'source' => 'google', 'weights' => [400.0], 'styles' => ['italic']];
        $faces = [['src' => '/fonts/latin-ext.woff2', 'sha256' => str_repeat('b', 64), 'weight' => 400, 'style' => 'italic', 'unicodeRange' => 'U+0100-024F'], ['src' => '/fonts/latin.woff2', 'sha256' => str_repeat('b', 64), 'weight' => 400, 'style' => 'italic', 'unicodeRange' => 'U+0000-00FF']];
        $css = CssEmitter::emit(self::doc($ref), static fn () => [...$ref, 'manifestSha256' => str_repeat('a', 64), 'faces' => $faces]);
        self::assertStringContainsString('font-display: swap;', $css);
        self::assertStringContainsString('font-weight: 400;', $css);
        self::assertStringContainsString('font-style: italic;', $css);
        self::assertLessThan(strpos($css, 'latin.woff2'), strpos($css, 'latin-ext.woff2'));
    }

    public function test_legacy_string_css_is_unchanged_and_never_resolves(): void
    {
        $doc = self::doc();
        $doc['theme'] = ['font' => ['body' => 'Inter, system-ui', 'heading' => 'Georgia, serif']];
        $css = CssEmitter::emit($doc, static function () {
            self::fail('Legacy strings must not resolve');
        });
        self::assertSame(CssEmitter::BASE_CSS."\n\n:root {\n  --cms-font-body: Inter, system-ui;\n  --cms-font-heading: Georgia, serif;\n}\n", $css);
    }

    public function test_missing_font_and_resolver_are_explicit(): void
    {
        $this->refusal(static fn () => CssEmitter::emit(self::doc()), 'missing-resolver');
        $this->refusal(static fn () => CssEmitter::emit(self::doc(), static fn () => null), 'missing-font');
        $this->refusal(static fn () => CssEmitter::emit(self::doc(), static function () {
            throw new \RuntimeException('secret endpoint');
        }), 'resolver-failed');
    }

    public static function badReferences(): iterable
    {
        foreach ([[], ['family' => 'https://evil.test/font'], ['family' => ' Bad'], ['family' => "Bad\nFamily"], ['source' => 'remote'], ['weights' => []], ['weights' => [400, 400]], ['weights' => [0]], ['weights' => [1001]], ['weights' => [400.5]], ['weights' => ['400']], ['styles' => ['oblique']], ['styles' => ['normal', 'normal']], ['url' => '/authored.woff2']] as $patch) {
            yield [$patch === [] ? [] : [...self::reference(), ...$patch]];
        }
    }

    #[DataProvider('badReferences')]
    public function test_malformed_reference_is_refused(mixed $ref): void
    {
        $this->refusal(static fn () => CssEmitter::emit(self::doc($ref), static fn () => self::asset()), 'invalid-reference');
    }

    public static function badUrls(): iterable
    {
        foreach (['https://fonts.gstatic.com/a.woff2', '//evil.test/a.woff2', 'data:font/woff2;base64,AAAA', 'blob:fake', 'file:///a.woff2', '/\\evil/a.woff2', '/fonts/a.woff2?x=1', '/fonts/a.woff2#x', '/fonts/%2f%2fevil.woff2', '/fonts/../a.woff2', '/fonts//a.woff2', '/fonts/a b.woff2', "/fonts/a\n.woff2", '/fonts/a".woff2', '/fonts/a</style>.woff2'] as $src) {
            yield [$src];
        }
    }

    #[DataProvider('badUrls')]
    public function test_external_or_noncanonical_url_is_refused(string $src): void
    {
        $value = self::asset();
        $value['faces'][0]['src'] = $src;
        $this->refusal(static fn () => CssEmitter::emit(self::doc(), static fn () => $value), 'invalid-asset');
    }

    public static function badAssets(): iterable
    {
        foreach ([['manifestSha256' => 'short'], ['family' => 'Other'], ['source' => 'google'], ['faces' => []], ['faces' => [[...self::asset()['faces'][0], 'sha256' => 'bad']]], ['faces' => [[...self::asset()['faces'][0], 'weight' => [900, 100]]]], ['faces' => [[...self::asset()['faces'][0], 'style' => 'oblique']]], ['faces' => [[...self::asset()['faces'][0], 'unicodeRange' => 'U+110000']]], ['faces' => [[...self::asset()['faces'][0], 'unicodeRange' => 'U+0100-0000']]], ['faces' => [[...self::asset()['faces'][0], 'unicodeRange' => 'U+0000; color:red']]]] as $patch) {
            yield [$patch];
        }
    }

    #[DataProvider('badAssets')]
    public function test_malformed_host_metadata_is_refused(array $patch): void
    {
        $this->refusal(static fn () => CssEmitter::emit(self::doc(), static fn () => [...self::asset(), ...$patch]), 'invalid-asset');
    }

    public function test_requested_faces_must_exist_and_limits_apply(): void
    {
        $value = self::asset();
        $value['faces'][0]['weight'] = 400;
        $this->refusal(static fn () => CssEmitter::emit(self::doc(), static fn () => $value), 'missing-face');
        $this->refusal(static fn () => CssEmitter::emit(self::doc([...self::reference(), 'styles' => ['italic']]), static fn () => self::asset()), 'missing-face');
        $this->refusal(static fn () => CssEmitter::emit(self::doc([...self::reference(), 'weights' => range(1, 17)]), static fn () => self::asset()), 'invalid-reference');
        $value = self::asset();
        $value['faces'] = array_fill(0, 257, $value['faces'][0]);
        $this->refusal(static fn () => CssEmitter::emit(self::doc(), static fn () => $value), 'invalid-asset');
        $doc = self::doc();
        $doc['theme']['font'] = [];
        for ($i = 0; $i < 9; $i++) {
            $doc['theme']['font']['font'.$i] = ['family' => 'Family '.$i, 'source' => 'google', 'weights' => [400]];
        }
        $this->refusal(static fn () => CssEmitter::emit($doc, static fn () => self::asset()), 'too-many-fonts');
    }

    public function test_display_policy_and_page_renderer_use_the_same_resolver(): void
    {
        $this->refusal(static fn () => CssEmitter::emit(self::doc(), static fn () => self::asset(), 'optional'), 'invalid-display');
        $out = PageRenderer::render(self::doc(), [], null, static fn () => self::asset(), 'block');
        self::assertSame(CssEmitter::emit(self::doc(), static fn () => self::asset(), 'block'), $out['css']);
        self::assertStringContainsString('font-display: block;', $out['css']);
        $this->refusal(static fn () => PageRenderer::render(self::doc()), 'missing-resolver');
    }

    public function test_trailing_controls_and_non_css_unicode_whitespace_are_refused(): void
    {
        foreach (["/fonts/a.woff2\n", "/fonts/a.woff2\r\n"] as $src) {
            $value = self::asset();
            $value['faces'][0]['src'] = $src;
            $this->refusal(static fn () => CssEmitter::emit(self::doc(), static fn () => $value), 'invalid-asset');
        }
        foreach (["\0U+0000-00FF", "\u{00a0}U+0000-00FF"] as $unicode) {
            $value = self::asset();
            $value['faces'][0]['unicodeRange'] = $unicode;
            $this->refusal(static fn () => CssEmitter::emit(self::doc(), static fn () => $value), 'invalid-asset');
        }
        $value = self::asset();
        $value['manifestSha256'] .= "\n";
        $this->refusal(static fn () => CssEmitter::emit(self::doc(), static fn () => $value), 'invalid-asset');
    }
}
