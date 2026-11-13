<?php

declare(strict_types=1);

namespace FancyCms\Tests;

use FancyCms\Agent;
use FancyCms\Document\PageDoc;
use FancyCms\Document\PageJson;
use FancyCms\Islands\IslandCatalogue;
use FancyCms\Render\PageRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The kit islands (every Fancy kit component a page may place) and the echart
 * node, PHP side. tests/fixtures/kit-islands.json and
 * resources/islands/kit-catalogue.json are byte-identical in
 * @particle-academy/fancy-cms-ui, which renders the same cases through React.
 */
final class KitIslandsTest extends TestCase
{
    private static function fixture(): object
    {
        return json_decode((string) file_get_contents(__DIR__.'/fixtures/kit-islands.json'), false, 512, JSON_THROW_ON_ERROR);
    }

    private static function doc(array $nodes): array
    {
        $map = new \stdClass;
        foreach ($nodes as $node) {
            $map->{$node->id} = $node;
        }

        return PageJson::decodeDocument((string) json_encode([
            'id' => 'kit', 'seq' => 0, 'meta' => ['title' => 'Kit', 'slug' => '/', 'scrollMode' => 'smooth'],
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
        $html = PageRenderer::render(self::doc($case->nodes), json_decode((string) json_encode($case->data), true), islands: IslandCatalogue::kit())['html'];
        self::assertSame($case->html, $html);
    }

    public function test_kit_is_the_builtins_plus_every_kit_island(): void
    {
        $kit = IslandCatalogue::kit();
        $shipped = json_decode((string) file_get_contents(dirname(__DIR__).'/resources/islands/kit-catalogue.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([...IslandCatalogue::builtin()->types(), ...array_keys($shipped['types'])], $kit->types());
        self::assertGreaterThanOrEqual(180, count($shipped['types']));
        self::assertTrue($kit->hydrates('react-fancy:Tabs'));
        self::assertSame('@particle-academy/react-fancy', $kit->definition('react-fancy:Tabs')['package']);
        self::assertSame('children', $kit->definition('react-fancy:Card')['slot']);
        self::assertNotEmpty($kit->definition('react-fancy:Tabs')['textFallback']);
    }

    public function test_merging_the_js_hosts_export_of_the_same_islands_changes_nothing(): void
    {
        $json = (string) file_get_contents(dirname(__DIR__).'/resources/islands/kit-catalogue.json');
        $merged = IslandCatalogue::builtin()->merge($json);
        self::assertSame(IslandCatalogue::kit()->types(), $merged->types());
        self::assertSame(IslandCatalogue::kit()->types(), IslandCatalogue::kit()->merge($json)->types());
    }

    public function test_a_kit_type_is_a_node_type_a_document_may_hold(): void
    {
        $types = IslandCatalogue::kit()->nodeTypes();
        self::assertContains('react-fancy:Chart', $types);
        self::assertContains('fancy-sheets:Spreadsheet', $types);
        self::assertContains('echart', PageDoc::NODE_TYPES);
    }

    public function test_the_echart_node_is_a_builtin_and_describe_explains_it(): void
    {
        self::assertTrue(IslandCatalogue::builtin()->has('echart'));
        self::assertSame('lazy', IslandCatalogue::builtin()->definition('echart')['hydrate']);
        $guide = json_encode(Agent::describe(IslandCatalogue::kit()));
        self::assertStringContainsString('echart', (string) $guide);
        self::assertStringContainsString('react-fancy:Tabs', (string) $guide);
    }

    public function test_a_document_whose_echart_option_breaks_a_rule_is_invalid(): void
    {
        $bad = ['id' => 'e', 'type' => 'echart', 'parent' => null, 'order' => 'a0', 'props' => ['title' => 'T', 'summary' => 'S', 'option' => ['tooltip' => ['formatter' => 'function () {}']]], 'style' => ['base' => []]];
        $errors = PageDoc::validate(self::docArray([$bad]));
        self::assertNotEmpty($errors);
        self::assertStringContainsString('option', json_encode($errors));
        $good = $bad;
        $good['props']['option'] = ['tooltip' => ['formatter' => '{b}: {c}'], 'series' => [['type' => 'bar', 'data' => [1, 2]]]];
        self::assertSame([], PageDoc::validate(self::docArray([$good])));
    }

    public function test_the_ts_copies_are_identical_when_the_sibling_is_checked_out(): void
    {
        $ui = dirname(__DIR__, 2).'/fancy-cms-ui';
        if (! is_dir($ui)) {
            self::markTestSkipped('fancy-cms-ui is not checked out beside this package.');
        }
        self::assertSame(file_get_contents($ui.'/tests/fixtures/kit-islands.json'), file_get_contents(__DIR__.'/fixtures/kit-islands.json'));
        self::assertSame(file_get_contents($ui.'/src/kit/catalogue.json'), file_get_contents(dirname(__DIR__).'/resources/islands/kit-catalogue.json'));
        self::assertSame(file_get_contents($ui.'/src/islands/catalogue.json'), file_get_contents(dirname(__DIR__).'/resources/islands/catalogue.json'));
    }

    /** @param list<array<string,mixed>> $nodes */
    private static function docArray(array $nodes): array
    {
        $map = [];
        foreach ($nodes as $node) {
            $map[$node['id']] = $node;
        }

        return ['id' => 'kit', 'seq' => 0, 'meta' => ['title' => 'Kit', 'slug' => '/', 'scrollMode' => 'smooth'], 'theme' => ['name' => 'default'], 'breakpoints' => ['base', 'md', 'lg'], 'nodes' => $map];
    }
}
