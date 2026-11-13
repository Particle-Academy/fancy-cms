<?php

declare(strict_types=1);

namespace FancyCms\Tests;

use FancyCms\Agent;
use FancyCms\Document\PageDoc;
use FancyCms\Islands\IslandCatalogue;
use FancyCms\PageSession;
use FancyCms\Render\PageRenderer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IslandCatalogueTest extends TestCase
{
    private const SCHEMA = ['type' => 'object', 'required' => ['tabs'], 'properties' => ['tabs' => ['type' => 'array', 'items' => ['type' => 'object']]]];

    private static function tabs(): IslandCatalogue
    {
        return IslandCatalogue::builtin()->register('kit:Tabs', [
            'description' => 'Tabbed panels',
            'props' => self::SCHEMA,
            'example' => ['tabs' => [['value' => 'a', 'label' => 'A']]],
        ]);
    }

    public function test_the_builtins_are_the_infographic_nodes_and_page_inputs(): void
    {
        $catalogue = IslandCatalogue::builtin();
        self::assertSame(['chart', 'echart', 'kpi', 'table', 'leaderboard', 'input'], $catalogue->types());
        self::assertTrue($catalogue->hydrates('chart'));
        self::assertFalse($catalogue->hydrates('kpi'));
        self::assertFalse($catalogue->hydrates('text'));
        self::assertSame('lazy', $catalogue->definition('chart')['hydrate']);
    }

    public function test_a_host_registers_an_island_with_a_json_schema_and_an_example(): void
    {
        $base = IslandCatalogue::builtin();
        $catalogue = self::tabs();
        self::assertSame(['chart', 'echart', 'kpi', 'table', 'leaderboard', 'input'], $base->types(), 'register() returns a new catalogue');
        self::assertSame(['chart', 'echart', 'kpi', 'table', 'leaderboard', 'input', 'kit:Tabs'], $catalogue->types());
        self::assertTrue($catalogue->hydrates('kit:Tabs'));
        $json = json_decode((string) json_encode($catalogue->toArray(), JSON_THROW_ON_ERROR), true);
        self::assertSame(['description' => 'Tabbed panels', 'hydrate' => 'eager', 'props' => self::SCHEMA, 'example' => ['tabs' => [['value' => 'a', 'label' => 'A']]]], $json['types']['kit:Tabs']);
        self::assertArrayHasKey('Binding', $json['$defs']);
    }

    public function test_the_catalogue_round_trips_through_the_json_a_js_host_exports(): void
    {
        $exported = (string) json_encode(self::tabs()->toArray(), JSON_THROW_ON_ERROR);
        $merged = IslandCatalogue::builtin()->merge($exported);
        self::assertSame(json_decode($exported, true), json_decode((string) json_encode($merged->toArray()), true));
        // Merging the built-ins again is idempotent, not a collision.
        self::assertSame(['chart', 'echart', 'kpi', 'table', 'leaderboard', 'input', 'kit:Tabs'], $merged->merge($exported)->types());
    }

    public static function badNames(): iterable
    {
        foreach (['Kit:X', 'kit:', 'has space', '__proto__', 'a:b:c', ''] as $name) {
            yield $name === '' ? 'empty' : $name => [$name];
        }
    }

    #[DataProvider('badNames')]
    public function test_refuses_a_malformed_type_name(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        IslandCatalogue::builtin()->register($name, ['description' => 'x', 'props' => self::SCHEMA]);
    }

    public function test_refuses_collisions_and_incomplete_definitions(): void
    {
        $refused = 0;
        foreach ([
            ['chart', ['description' => 'x', 'props' => self::SCHEMA]],
            ['text', ['description' => 'x', 'props' => self::SCHEMA]],
            ['kit:A', ['props' => self::SCHEMA]],
            ['kit:A', ['description' => 'x', 'props' => ['type' => 'array']]],
            ['kit:A', ['description' => 'x', 'props' => self::SCHEMA, 'hydrate' => 'sometimes']],
            ['kit:A', ['description' => 'x', 'props' => self::SCHEMA, 'example' => 'not an object']],
        ] as [$type, $definition]) {
            try {
                IslandCatalogue::builtin()->register($type, $definition);
            } catch (InvalidArgumentException) {
                $refused++;
            }
        }
        self::assertSame(6, $refused);
        $this->expectException(InvalidArgumentException::class);
        self::tabs()->register('kit:Tabs', ['description' => 'again', 'props' => self::SCHEMA]);
    }

    public function test_registered_types_are_known_to_document_validation(): void
    {
        $node = ['id' => 'n', 'type' => 'kit:Tabs', 'parent' => null, 'order' => 'a', 'props' => ['tabs' => []], 'style' => ['base' => []]];
        $doc = ['id' => 'p', 'seq' => 0, 'meta' => ['title' => 'P', 'slug' => '/', 'scrollMode' => 'smooth'], 'theme' => ['name' => 'default'], 'breakpoints' => ['base'], 'nodes' => ['n' => $node]];
        self::assertNotSame([], PageDoc::validate($doc));
        self::assertSame([], PageDoc::validate($doc, self::tabs()->nodeTypes()));
        $session = new PageSession($doc, self::tabs()->nodeTypes());
        $session->apply([['t' => 'insert_node', 'node' => ['id' => 'k', 'type' => 'kpi', 'parent' => null, 'order' => 'b', 'props' => ['value' => ['$bind' => 'm.v']], 'style' => ['base' => []]]]], 'agent');
        self::assertSame('kpi', $session->get()['doc']['nodes']['k']['type']);
        foreach (['chart', 'kpi', 'table', 'leaderboard'] as $type) {
            self::assertContains($type, PageDoc::NODE_TYPES);
        }
    }

    public function test_the_renderer_marks_registered_host_islands(): void
    {
        $doc = InfographicParityTest::doc([(object) ['id' => 'n', 'type' => 'kit:Tabs', 'parent' => null, 'order' => 'a', 'props' => ['tabs' => []], 'style' => ['base' => new \stdClass]]]);
        self::assertSame('<div data-cms="n"></div>', PageRenderer::render($doc)['html']);
        self::assertSame('<div data-cms="n" data-cms-island="kit:Tabs"></div>', PageRenderer::render($doc, islands: self::tabs())['html']);
    }

    public function test_cms_describe_returns_the_catalogue(): void
    {
        $catalogue = Agent::describe(self::tabs());
        self::assertArrayHasKey('kit:Tabs', $catalogue['islands']['types']);
        self::assertSame(self::SCHEMA, json_decode((string) json_encode($catalogue['islands']['types']['kit:Tabs']['props']), true));
        foreach (['chart', 'kpi', 'table', 'leaderboard'] as $type) {
            self::assertArrayHasKey('example', $catalogue['islands']['types'][$type]);
            self::assertSame('see islands.types.'.$type, $catalogue['elements'][$type]['schema']);
        }
        self::assertArrayHasKey('kit:Tabs', $catalogue['elements']);
        self::assertSame(array_keys(Agent::describe()['islands']['types']), ['chart', 'echart', 'kpi', 'table', 'leaderboard', 'input']);
    }
}
