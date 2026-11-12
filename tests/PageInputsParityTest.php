<?php

declare(strict_types=1);

namespace FancyCms\Tests;

use FancyCms\Data\DataRequests;
use FancyCms\Data\PageInputs;
use FancyCms\Document\PageJson;
use FancyCms\Islands\IslandCatalogue;
use FancyCms\Render\CssEmitter;
use FancyCms\Render\Inputs;
use FancyCms\Render\PageRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The PHP half of the page-input parity spec. tests/fixtures/inputs.json is
 * byte-identical in @particle-academy/fancy-cms-ui, whose suite renders the
 * same cases through React's CmsPage and parses the same query strings.
 */
final class PageInputsParityTest extends TestCase
{
    private static function fixture(): object
    {
        return json_decode((string) file_get_contents(__DIR__.'/fixtures/inputs.json'), false, 512, JSON_THROW_ON_ERROR);
    }

    /** A document exactly as a host decodes one from storage. */
    private static function doc(array $nodes): array
    {
        $map = new \stdClass;
        foreach ($nodes as $node) {
            $map->{$node->id} = $node;
        }

        return PageJson::decodeDocument((string) json_encode([
            'id' => 'in', 'seq' => 0, 'meta' => ['title' => 'Inputs', 'slug' => '/', 'scrollMode' => 'smooth'],
            'theme' => ['name' => 'default'], 'breakpoints' => ['base', 'md', 'lg'], 'nodes' => $map,
        ], JSON_THROW_ON_ERROR));
    }

    /** Data decoded with objects kept as objects, as bin/render-html.php does. */
    private static function data(object $value): array
    {
        return (array) $value;
    }

    public static function cases(): iterable
    {
        foreach (self::fixture()->cases as $case) {
            yield $case->name => [$case];
        }
    }

    public static function queries(): iterable
    {
        foreach (self::fixture()->query->cases as $case) {
            yield $case->name => [$case];
        }
    }

    #[DataProvider('cases')]
    public function test_renders_the_shared_case(object $case): void
    {
        $html = PageRenderer::render(self::doc($case->nodes), self::data($case->data), inputs: (array) $case->inputs)['html'];
        self::assertSame($case->html, $html);
    }

    #[DataProvider('queries')]
    public function test_reads_and_writes_the_shared_query(object $case): void
    {
        $q = self::fixture()->query;
        $doc = self::doc($q->nodes);
        parse_str($case->search, $query);
        $values = PageInputs::fromQuery($doc, $query, self::data($q->data));
        // Keyed by name; which order the keys come in is not part of the contract.
        self::assertEquals(json_decode((string) json_encode($case->values), true), $values);
        self::assertSame($case->encoded, PageInputs::toQuery($doc, json_decode((string) json_encode($case->values), true), self::data($q->data)));
    }

    public function test_declares_each_named_input_once_ordered_by_node_id(): void
    {
        $q = self::fixture()->query;
        self::assertSame(json_encode($q->declared), json_encode(PageInputs::declared(self::doc($q->nodes))));
    }

    public function test_a_bound_option_list_is_a_data_request_with_its_expected_shape(): void
    {
        $q = self::fixture()->query;
        $requests = array_values(array_filter(DataRequests::of(self::doc($q->nodes)), static fn (array $r): bool => $r['node'] === 'r'));
        self::assertSame(json_encode($q->requests), json_encode($requests));
    }

    public function test_the_stylesheet_block_is_shared_and_only_emitted_when_used(): void
    {
        $fixture = self::fixture();
        self::assertSame($fixture->css, Inputs::CSS);
        self::assertSame(CssEmitter::BASE_CSS."\n\n".Inputs::CSS."\n", CssEmitter::emit(self::doc($fixture->cases[0]->nodes)));
    }

    public function test_input_is_a_known_node_type_and_a_lazy_island_in_the_catalogue(): void
    {
        self::assertTrue(IslandCatalogue::builtin()->has('input'));
        self::assertTrue(IslandCatalogue::builtin()->hydrates('input'));
        self::assertContains('input', IslandCatalogue::builtin()->nodeTypes());
    }

    public function test_the_head_style_element_is_the_shared_bytes(): void
    {
        $tag = self::fixture()->styleTag;
        self::assertSame($tag->html, PageRenderer::styleTag(['id' => $tag->key], $tag->css));
        self::assertSame('', PageRenderer::styleTag(['id' => 'x'], ''));
    }

    /** @return iterable<string,array{object}> */
    public static function hostileStyleTags(): iterable
    {
        foreach (self::fixture()->styleTagHostile as $tag) {
            yield json_encode($tag->css, JSON_THROW_ON_ERROR) => [$tag];
        }
    }

    /**
     * styleTag is public, so a host can hand it any CSS, not only the
     * emitter's. Whatever the CSS says, the element must be one <style> that an
     * HTML5 parser closes where we close it.
     */
    #[DataProvider('hostileStyleTags')]
    public function test_hostile_css_cannot_close_the_head_style_element(object $tag): void
    {
        $html = PageRenderer::styleTag(['id' => $tag->key], $tag->css);
        self::assertSame($tag->html, $html);
        self::assertStringNotContainsString('</style', strtolower(substr($html, 0, -strlen('</style>'))));
        self::assertStringNotContainsString('<!--', $html);

        $dom = \Dom\HTMLDocument::createFromString(
            '<!doctype html><html><head>'.$html.'<meta name="after"></head><body></body></html>',
            LIBXML_NOERROR,
        );
        $styles = $dom->querySelectorAll('style');
        self::assertCount(1, $styles);
        self::assertNull($dom->querySelector('script, img'));
        self::assertSame(PageRenderer::styleText($tag->css), $styles->item(0)->textContent);
        self::assertSame('after', $styles->item(0)->nextElementSibling?->getAttribute('name'));
    }

    public function test_the_hostile_fixture_covers_the_named_cases(): void
    {
        $all = implode("\n", array_map(static fn (object $t): string => $t->css, self::fixture()->styleTagHostile));
        self::assertStringContainsString('</style><script>', $all);
        self::assertStringContainsString('</STYLE', $all);
        self::assertStringContainsString('<!--', $all);
    }

    public function test_style_text_writes_the_css_escape_for_less_than(): void
    {
        self::assertSame(chr(92).'3C /', PageRenderer::styleText('</'));
        $css = ":where([data-cms] > img) {\n  width: 100%;\n}\n";
        self::assertSame($css, PageRenderer::styleText($css));
    }

    public function test_the_page_stylesheet_is_for_the_head_and_never_in_the_html(): void
    {
        $doc = self::doc(self::fixture()->cases[0]->nodes);
        $page = PageRenderer::render($doc);
        self::assertStringNotContainsString('<style', $page['html']);
        self::assertSame('<style data-cms-styles="in">'.$page['css'].'</style>', PageRenderer::styleTag($doc, $page['css']));
    }

    public function test_the_fixture_matches_the_ui_copy_when_the_sibling_is_checked_out(): void
    {
        $theirs = dirname(__DIR__, 2).'/fancy-cms-ui/tests/fixtures/inputs.json';
        if (! is_file($theirs)) {
            self::markTestSkipped('fancy-cms-ui is not checked out beside this repo.');
        }
        self::assertSame(file_get_contents($theirs), file_get_contents(__DIR__.'/fixtures/inputs.json'));
    }
}
