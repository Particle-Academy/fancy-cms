<?php

declare(strict_types=1);

namespace FancyCms\Tests;

use FancyCms\Agent;
use FancyCms\Document\PageDoc;
use FancyCms\Document\PageJson;
use FancyCms\Render\CssEmitter;
use FancyCms\Render\PageRenderer;
use FancyCms\Render\TextWrap;
use FancyCms\Tests\Support\PageFixtures as F;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Lab finding S11: a text node holding an unbroken long string scrolled the
 * page sideways at 375px (hostile-facts measured 380px), and a document had
 * no way to fix it.
 *
 * 0.9.0 gave every text node `overflow-wrap: anywhere`, which lowered every
 * text node's min-content width, so grid tracks and flex items shrank below
 * ordinary words at tablet widths and broke them mid-word ("Discriminatio/n",
 * "$/9", "41/%": lab runs 542 to 554). Now text nodes get `break-word`, which
 * keeps min-content, and only a node whose rendered text holds a long token is
 * marked `data-cms-wrap="anywhere"`. tests/fixtures/text-wrap.json is
 * byte-identical in @particle-academy/fancy-cms-ui, whose suite also checks, in
 * a DOM, that each rule's selector matches exactly the elements each case says
 * it does, and whose parity/text-wrap-browser.mjs measures it in a browser.
 */
final class TextWrapParityTest extends TestCase
{
    private static function fixture(): object
    {
        return json_decode((string) file_get_contents(__DIR__.'/fixtures/text-wrap.json'), false, 512, JSON_THROW_ON_ERROR);
    }

    /** A document exactly as a host decodes one from storage. */
    private static function doc(array $nodes): array
    {
        $map = new \stdClass;
        foreach ($nodes as $node) {
            $map->{$node->id} = $node;
        }

        return PageJson::decodeDocument((string) json_encode([
            'id' => 'wrap', 'seq' => 0, 'meta' => ['title' => 'Wrap', 'slug' => '/', 'scrollMode' => 'smooth'],
            'theme' => ['name' => 'default'], 'breakpoints' => ['base', 'md', 'lg'], 'nodes' => $map,
        ], JSON_THROW_ON_ERROR));
    }

    public static function cases(): iterable
    {
        foreach (self::fixture()->cases as $case) {
            yield $case->name => [$case];
        }
    }

    public static function styles(): iterable
    {
        foreach (self::fixture()->styles as $case) {
            yield $case->name => [$case];
        }
    }

    public function test_the_base_stylesheet_carries_the_shared_rules_the_long_token_rule_straight_after(): void
    {
        self::assertStringContainsString("\n\n".self::fixture()->rule."\n\n".self::fixture()->longRule."\n\n", CssEmitter::BASE_CSS);
    }

    public function test_ordinary_text_never_lowers_its_min_content(): void
    {
        self::assertStringContainsString('overflow-wrap: break-word;', self::fixture()->rule);
        self::assertStringNotContainsString("[data-cms] {\n  overflow-wrap: anywhere;", CssEmitter::BASE_CSS);
    }

    public function test_the_threshold_is_the_shared_one(): void
    {
        $n = self::fixture()->longToken;
        self::assertSame($n, TextWrap::LONG_TOKEN);
        self::assertFalse(TextWrap::hasLongToken(str_repeat('a', $n - 1)));
        self::assertTrue(TextWrap::hasLongToken(str_repeat('a', $n)));
    }

    public function test_a_bound_value_is_measured_after_it_resolves(): void
    {
        $node = (object) ['id' => 'b', 'type' => 'text', 'parent' => null, 'order' => 'a0', 'props' => (object) ['content' => (object) ['$bind' => 'site.url']], 'style' => (object) ['base' => new \stdClass]];
        self::assertSame(
            '<p data-cms="b" data-cms-wrap="anywhere">https://example.com/a/very/long/path</p>',
            PageRenderer::render(self::doc([$node]), ['site' => ['url' => 'https://example.com/a/very/long/path']])['html'],
        );
    }

    public function test_the_rule_comes_after_the_margin_reset(): void
    {
        $css = CssEmitter::BASE_CSS;
        self::assertGreaterThan(strpos($css, ':where(h1, h2, h3, h4, h5, h6, p, ul, ol)[data-cms] {'), strpos($css, self::fixture()->rule));
    }

    #[DataProvider('cases')]
    public function test_renders_the_shared_markup(object $case): void
    {
        self::assertSame($case->html, PageRenderer::render(self::doc($case->nodes))['html']);
    }

    #[DataProvider('styles')]
    public function test_overflow_wrap_emits_the_shared_declarations(object $case): void
    {
        $doc = F::doc();
        $doc['nodes'] = ['a' => F::node('a')];
        $doc['nodes']['a']['style']['base'] = (array) $case->style;
        $css = CssEmitter::emit($doc);
        self::assertStringStartsWith(CssEmitter::BASE_CSS, $css);
        self::assertSame($case->css, $css === CssEmitter::BASE_CSS."\n" ? '' : substr($css, strlen(CssEmitter::BASE_CSS) + 2));
    }

    public function test_the_schema_accepts_the_keywords_and_refuses_anything_else(): void
    {
        foreach (['normal', 'break-word', 'anywhere'] as $value) {
            $doc = F::doc();
            $doc['nodes']['a']['style']['base'] = ['overflowWrap' => $value];
            self::assertSame([], PageDoc::validate($doc), $value);
        }
        foreach (['break-all', 'normal; color: red', 1] as $value) {
            $doc = F::doc();
            $doc['nodes']['a']['style']['base'] = ['overflowWrap' => $value];
            $errors = PageDoc::validate($doc);
            self::assertNotSame([], $errors, (string) $value);
            self::assertSame('$.nodes.a.style.base.overflowWrap', $errors[0]['path']);
        }
    }

    public function test_an_agent_is_told_the_keywords(): void
    {
        self::assertSame(['normal', 'break-word', 'anywhere'], Agent::describe()['style']['overflowWrap']);
    }

    public function test_the_fixture_is_byte_identical_to_fancy_cms_ui_when_beside_it(): void
    {
        $theirs = __DIR__.'/../../fancy-cms-ui/tests/fixtures/text-wrap.json';
        if (! is_file($theirs)) {
            self::markTestSkipped('fancy-cms-ui is not checked out beside this repo.');
        }
        self::assertSame(file_get_contents($theirs), file_get_contents(__DIR__.'/fixtures/text-wrap.json'));
    }
}
