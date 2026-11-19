<?php

declare(strict_types=1);

namespace FancyCms\Tests\KitIslandsTest;

use FancyCms\Agent;
use FancyCms\Document\PageDoc;
use FancyCms\Document\PageJson;
use FancyCms\Islands\IslandCatalogue;
use FancyCms\Render\PageRenderer;

/**
 * The kit islands (every Fancy kit component a page may place) and the echart
 * node, PHP side. tests/fixtures/kit-islands.json and
 * resources/islands/kit-catalogue.json are byte-identical in
 * @particle-academy/fancy-cms-ui, which renders the same cases through React.
 */

function fixture(): object {
    return json_decode((string) file_get_contents(__DIR__.'/fixtures/kit-islands.json'), false, 512, JSON_THROW_ON_ERROR);
}

function doc(array $nodes): array {
    $map = new \stdClass;
    foreach ($nodes as $node) {
        $map->{$node->id} = $node;
    }

    return PageJson::decodeDocument((string) json_encode([
        'id' => 'kit', 'seq' => 0, 'meta' => ['title' => 'Kit', 'slug' => '/', 'scrollMode' => 'smooth'],
        'theme' => ['name' => 'default'], 'breakpoints' => ['base', 'md', 'lg'], 'nodes' => $map,
    ], JSON_THROW_ON_ERROR));
}

function cases(): iterable {
    foreach (fixture()->cases as $case) {
        yield $case->name => [$case];
    }
}

test('renders the shared case', function (object $case) {
    $html = PageRenderer::render(doc($case->nodes), json_decode((string) json_encode($case->data), true), islands: IslandCatalogue::kit())['html'];
    $this->assertSame($case->html, $html);
})->with(fn (): iterable => cases());

test('kit is the builtins plus every kit island', function () {
    $kit = IslandCatalogue::kit();
    $shipped = json_decode((string) file_get_contents(dirname(__DIR__).'/resources/islands/kit-catalogue.json'), true, 512, JSON_THROW_ON_ERROR);
    $this->assertSame([...IslandCatalogue::builtin()->types(), ...array_keys($shipped['types'])], $kit->types());
    $this->assertGreaterThanOrEqual(180, count($shipped['types']));
    $this->assertTrue($kit->hydrates('react-fancy:Tabs'));
    $this->assertSame('@particle-academy/react-fancy', $kit->definition('react-fancy:Tabs')['package']);
    $this->assertSame('children', $kit->definition('react-fancy:Card')['slot']);
    $this->assertNotEmpty($kit->definition('react-fancy:Tabs')['textFallback']);
});

test('merging the js hosts export of the same islands changes nothing', function () {
    $json = (string) file_get_contents(dirname(__DIR__).'/resources/islands/kit-catalogue.json');
    $merged = IslandCatalogue::builtin()->merge($json);
    $this->assertSame(IslandCatalogue::kit()->types(), $merged->types());
    $this->assertSame(IslandCatalogue::kit()->types(), IslandCatalogue::kit()->merge($json)->types());
});

test('a kit type is a node type a document may hold', function () {
    $types = IslandCatalogue::kit()->nodeTypes();
    $this->assertContains('react-fancy:Chart', $types);
    $this->assertContains('fancy-sheets:Spreadsheet', $types);
    $this->assertContains('echart', PageDoc::NODE_TYPES);
});

test('the echart node is a builtin and describe explains it', function () {
    $this->assertTrue(IslandCatalogue::builtin()->has('echart'));
    $this->assertSame('lazy', IslandCatalogue::builtin()->definition('echart')['hydrate']);
    $guide = json_encode(Agent::describe(IslandCatalogue::kit()));
    $this->assertStringContainsString('echart', (string) $guide);
    $this->assertStringContainsString('react-fancy:Tabs', (string) $guide);
});

test('a document whose echart option breaks a rule is invalid', function () {
    $bad = ['id' => 'e', 'type' => 'echart', 'parent' => null, 'order' => 'a0', 'props' => ['title' => 'T', 'summary' => 'S', 'option' => ['tooltip' => ['formatter' => 'function () {}']]], 'style' => ['base' => []]];
    $errors = PageDoc::validate(docArray([$bad]));
    $this->assertNotEmpty($errors);
    $this->assertStringContainsString('option', json_encode($errors));
    $good = $bad;
    $good['props']['option'] = ['tooltip' => ['formatter' => '{b}: {c}'], 'series' => [['type' => 'bar', 'data' => [1, 2]]]];
    $this->assertSame([], PageDoc::validate(docArray([$good])));
});

test('the ts copies are identical when the sibling is checked out', function () {
    $ui = dirname(__DIR__, 2).'/fancy-cms-ui';
    if (! is_dir($ui)) {
        $this->markTestSkipped('fancy-cms-ui is not checked out beside this package.');
    }
    $this->assertSame(file_get_contents($ui.'/tests/fixtures/kit-islands.json'), file_get_contents(__DIR__.'/fixtures/kit-islands.json'));
    $this->assertSame(file_get_contents($ui.'/src/kit/catalogue.json'), file_get_contents(dirname(__DIR__).'/resources/islands/kit-catalogue.json'));
    $this->assertSame(file_get_contents($ui.'/src/islands/catalogue.json'), file_get_contents(dirname(__DIR__).'/resources/islands/catalogue.json'));
});

/** @param list<array<string,mixed>> $nodes */
function docArray(array $nodes): array {
    $map = [];
    foreach ($nodes as $node) {
        $map[$node['id']] = $node;
    }

    return ['id' => 'kit', 'seq' => 0, 'meta' => ['title' => 'Kit', 'slug' => '/', 'scrollMode' => 'smooth'], 'theme' => ['name' => 'default'], 'breakpoints' => ['base', 'md', 'lg'], 'nodes' => $map];
}
