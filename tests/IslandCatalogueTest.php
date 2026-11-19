<?php

declare(strict_types=1);

namespace FancyCms\Tests\IslandCatalogueTest;

use FancyCms\Agent;
use FancyCms\Document\PageDoc;
use FancyCms\Islands\IslandCatalogue;
use FancyCms\PageSession;
use FancyCms\Render\PageRenderer;
use InvalidArgumentException;

const SCHEMA = ['type' => 'object', 'required' => ['tabs'], 'properties' => ['tabs' => ['type' => 'array', 'items' => ['type' => 'object']]]];

function tabs(): IslandCatalogue {
    return IslandCatalogue::builtin()->register('kit:Tabs', [
        'description' => 'Tabbed panels',
        'props' => SCHEMA,
        'example' => ['tabs' => [['value' => 'a', 'label' => 'A']]],
    ]);
}

test('the builtins are the infographic nodes and page inputs', function () {
    $catalogue = IslandCatalogue::builtin();
    $this->assertSame(['chart', 'echart', 'kpi', 'table', 'leaderboard', 'input'], $catalogue->types());
    $this->assertTrue($catalogue->hydrates('chart'));
    $this->assertFalse($catalogue->hydrates('kpi'));
    $this->assertFalse($catalogue->hydrates('text'));
    $this->assertSame('lazy', $catalogue->definition('chart')['hydrate']);
});

test('a host registers an island with a json schema and an example', function () {
    $base = IslandCatalogue::builtin();
    $catalogue = tabs();
    $this->assertSame(['chart', 'echart', 'kpi', 'table', 'leaderboard', 'input'], $base->types(), 'register() returns a new catalogue');
    $this->assertSame(['chart', 'echart', 'kpi', 'table', 'leaderboard', 'input', 'kit:Tabs'], $catalogue->types());
    $this->assertTrue($catalogue->hydrates('kit:Tabs'));
    $json = json_decode((string) json_encode($catalogue->toArray(), JSON_THROW_ON_ERROR), true);
    $this->assertSame(['description' => 'Tabbed panels', 'hydrate' => 'eager', 'props' => SCHEMA, 'example' => ['tabs' => [['value' => 'a', 'label' => 'A']]]], $json['types']['kit:Tabs']);
    $this->assertArrayHasKey('Binding', $json['$defs']);
});

test('the catalogue round trips through the json a js host exports', function () {
    $exported = (string) json_encode(tabs()->toArray(), JSON_THROW_ON_ERROR);
    $merged = IslandCatalogue::builtin()->merge($exported);
    $this->assertSame(json_decode($exported, true), json_decode((string) json_encode($merged->toArray()), true));
    // Merging the built-ins again is idempotent, not a collision.
    $this->assertSame(['chart', 'echart', 'kpi', 'table', 'leaderboard', 'input', 'kit:Tabs'], $merged->merge($exported)->types());
});

function badNames(): iterable {
    foreach (['Kit:X', 'kit:', 'has space', '__proto__', 'a:b:c', ''] as $name) {
        yield $name === '' ? 'empty' : $name => [$name];
    }
}

test('refuses a malformed type name', function (string $name) {
    $this->expectException(InvalidArgumentException::class);
    IslandCatalogue::builtin()->register($name, ['description' => 'x', 'props' => SCHEMA]);
})->with(fn (): iterable => badNames());

test('refuses collisions and incomplete definitions', function () {
    $refused = 0;
    foreach ([
        ['chart', ['description' => 'x', 'props' => SCHEMA]],
        ['text', ['description' => 'x', 'props' => SCHEMA]],
        ['kit:A', ['props' => SCHEMA]],
        ['kit:A', ['description' => 'x', 'props' => ['type' => 'array']]],
        ['kit:A', ['description' => 'x', 'props' => SCHEMA, 'hydrate' => 'sometimes']],
        ['kit:A', ['description' => 'x', 'props' => SCHEMA, 'example' => 'not an object']],
    ] as [$type, $definition]) {
        try {
            IslandCatalogue::builtin()->register($type, $definition);
        } catch (InvalidArgumentException) {
            $refused++;
        }
    }
    $this->assertSame(6, $refused);
    $this->expectException(InvalidArgumentException::class);
    tabs()->register('kit:Tabs', ['description' => 'again', 'props' => SCHEMA]);
});

test('registered types are known to document validation', function () {
    $node = ['id' => 'n', 'type' => 'kit:Tabs', 'parent' => null, 'order' => 'a', 'props' => ['tabs' => []], 'style' => ['base' => []]];
    $doc = ['id' => 'p', 'seq' => 0, 'meta' => ['title' => 'P', 'slug' => '/', 'scrollMode' => 'smooth'], 'theme' => ['name' => 'default'], 'breakpoints' => ['base'], 'nodes' => ['n' => $node]];
    $this->assertNotSame([], PageDoc::validate($doc));
    $this->assertSame([], PageDoc::validate($doc, tabs()->nodeTypes()));
    $session = new PageSession($doc, tabs()->nodeTypes());
    $session->apply([['t' => 'insert_node', 'node' => ['id' => 'k', 'type' => 'kpi', 'parent' => null, 'order' => 'b', 'props' => ['value' => ['$bind' => 'm.v']], 'style' => ['base' => []]]]], 'agent');
    $this->assertSame('kpi', $session->get()['doc']['nodes']['k']['type']);
    foreach (['chart', 'kpi', 'table', 'leaderboard'] as $type) {
        $this->assertContains($type, PageDoc::NODE_TYPES);
    }
});

test('the renderer marks registered host islands', function () {
    $doc = \FancyCms\Tests\Support\PageFixtures::decodedDoc([(object) ['id' => 'n', 'type' => 'kit:Tabs', 'parent' => null, 'order' => 'a', 'props' => ['tabs' => []], 'style' => ['base' => new \stdClass]]]);
    $this->assertSame('<div data-cms="n"></div>', PageRenderer::render($doc)['html']);
    $this->assertSame('<div data-cms="n" data-cms-island="kit:Tabs"></div>', PageRenderer::render($doc, islands: tabs())['html']);
});

test('cms describe returns the catalogue', function () {
    $catalogue = Agent::describe(tabs());
    $this->assertArrayHasKey('kit:Tabs', $catalogue['islands']['types']);
    $this->assertSame(SCHEMA, json_decode((string) json_encode($catalogue['islands']['types']['kit:Tabs']['props']), true));
    foreach (['chart', 'kpi', 'table', 'leaderboard'] as $type) {
        $this->assertArrayHasKey('example', $catalogue['islands']['types'][$type]);
        $this->assertSame('see islands.types.'.$type, $catalogue['elements'][$type]['schema']);
    }
    $this->assertArrayHasKey('kit:Tabs', $catalogue['elements']);
    $this->assertSame(array_keys(Agent::describe()['islands']['types']), ['chart', 'echart', 'kpi', 'table', 'leaderboard', 'input']);
});
