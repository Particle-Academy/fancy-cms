<?php

declare(strict_types=1);

namespace FancyCms\Tests;

use FancyCms\Data\DataRequests;
use FancyCms\Document\PageJson;
use FancyCms\Islands\IslandCatalogue;
use FancyCms\Render\CssEmitter;
use FancyCms\Render\Infographics;
use FancyCms\Render\PageRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The PHP half of the infographic parity spec. tests/fixtures/infographics.json
 * is byte-identical in @particle-academy/fancy-cms-ui, whose suite renders the
 * same cases through React's CmsPage and must produce the same html.
 */
final class InfographicParityTest extends TestCase
{
    private static function fixture(): object
    {
        return json_decode((string) file_get_contents(__DIR__.'/fixtures/infographics.json'), false, 512, JSON_THROW_ON_ERROR);
    }

    /** A document exactly as a host decodes one from storage. */
    public static function doc(array $nodes): array
    {
        $map = new \stdClass;
        foreach ($nodes as $node) {
            $map->{$node->id} = $node;
        }

        return PageJson::decodeDocument((string) json_encode([
            'id' => 'ig', 'seq' => 0, 'meta' => ['title' => 'Infographics', 'slug' => '/', 'scrollMode' => 'smooth'],
            'theme' => ['name' => 'default'], 'breakpoints' => ['base', 'md', 'lg'], 'nodes' => $map,
        ], JSON_THROW_ON_ERROR));
    }

    public static function cases(): iterable
    {
        foreach (self::fixture()->cases as $case) {
            yield $case->name => [$case];
        }
    }

    #[DataProvider('cases')]
    public function test_renders_the_shared_case(object $case): void
    {
        $html = PageRenderer::render(self::doc($case->nodes), (array) $case->data)['html'];
        self::assertSame($case->html, $html);
    }

    public function test_the_stylesheet_block_is_shared_and_only_emitted_when_used(): void
    {
        $fixture = self::fixture();
        self::assertSame($fixture->css, Infographics::CSS);
        $css = CssEmitter::emit(self::doc($fixture->cases[0]->nodes));
        self::assertSame(CssEmitter::BASE_CSS."\n\n".Infographics::CSS."\n", $css);
        $plain = CssEmitter::emit(self::doc([(object) ['id' => 'p', 'type' => 'text', 'parent' => null, 'order' => 'a', 'props' => ['content' => 'x'], 'style' => ['base' => new \stdClass]]]));
        self::assertStringNotContainsString('fancy-cms infographics', $plain);
    }

    public function test_data_requests_list_every_bound_path_with_its_expected_shape(): void
    {
        $fixture = self::fixture();
        $actual = DataRequests::of(self::doc($fixture->requests->nodes));
        self::assertSame(
            json_encode($fixture->requests->expected, JSON_THROW_ON_ERROR),
            json_encode($actual, JSON_THROW_ON_ERROR),
        );
    }

    public function test_the_shared_files_match_the_ui_copies_when_the_sibling_is_checked_out(): void
    {
        $sibling = dirname(__DIR__, 2).'/fancy-cms-ui';
        $pairs = [
            [__DIR__.'/fixtures/infographics.json', $sibling.'/tests/fixtures/infographics.json'],
            [dirname(__DIR__).'/resources/islands/catalogue.json', $sibling.'/src/islands/catalogue.json'],
        ];
        foreach ($pairs as [$mine, $theirs]) {
            if (is_file($theirs)) {
                self::assertSame(file_get_contents($theirs), file_get_contents($mine), basename($mine).' must ship identical bytes in both packages.');
            }
        }
        self::assertSame(
            json_decode((string) file_get_contents(dirname(__DIR__).'/resources/islands/catalogue.json'), true),
            json_decode((string) json_encode(IslandCatalogue::builtin()->toArray()), true),
        );
    }
}
