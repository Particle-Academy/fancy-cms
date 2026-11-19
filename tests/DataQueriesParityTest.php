<?php

declare(strict_types=1);

namespace FancyCms\Tests\DataQueriesParityTest;

use FancyCms\Data\DataRequests;
use FancyCms\Document\PageJson;
use FancyCms\Render\PageRenderer;

/**
 * The bindings Charlotte writes: `{$bind, $source?}` plus `$params`, `$scope`
 * and `<stream>#<alias>`, with `{"$input"}` references fed by page inputs.
 * tests/fixtures/data-queries.json is byte-identical in fancy-cms-ui, whose
 * dataQueries() must return the same queries.
 */

function fixture(): object {
    return json_decode((string) file_get_contents(__DIR__.'/fixtures/data-queries.json'), false, 512, JSON_THROW_ON_ERROR);
}

function doc(): array {
    $map = new \stdClass;
    foreach (fixture()->nodes as $node) {
        $map->{$node->id} = $node;
    }

    return PageJson::decodeDocument((string) json_encode([
        'id' => 'q', 'seq' => 0, 'meta' => ['title' => 'Q', 'slug' => '/', 'scrollMode' => 'smooth'],
        'theme' => ['name' => 'default'], 'breakpoints' => ['base'], 'nodes' => $map,
    ], JSON_THROW_ON_ERROR));
}

/** JSON-equal: decoded to plain arrays on both sides, so an empty object and an empty array compare alike. */
function plain(mixed $v): mixed {
    return json_decode((string) json_encode($v, JSON_THROW_ON_ERROR), true);
}

function cases(): iterable {
    foreach (fixture()->cases as $case) {
        yield $case->name => [$case];
    }
}

test('returns the shared queries', function (object $case) {
    $queries = DataRequests::queries(doc(), json_decode((string) json_encode($case->inputs), true), (array) fixture()->data);
    $this->assertSame(plain($case->queries), plain($queries));
})->with(fn (): iterable => cases());

test('a data request carries the bindings params and scope as written', function () {
    $request = array_values(array_filter(DataRequests::of(doc()), static fn (array $r): bool => $r['node'] === 'k1'))[0];
    $this->assertSame(json_encode(fixture()->request), json_encode($request));
});

test('an aliased path renders from the result under its key', function () {
    $doc = PageJson::decodeDocument((string) json_encode([
        'id' => 'a', 'seq' => 0, 'meta' => ['title' => 'A', 'slug' => '/', 'scrollMode' => 'smooth'], 'theme' => ['name' => 'default'], 'breakpoints' => ['base'],
        'nodes' => ['k' => ['id' => 'k', 'type' => 'kpi', 'parent' => null, 'order' => 'a0', 'props' => ['value' => ['$bind' => 'metrics#won.totals.value', '$params' => ['stage' => 'won']]], 'style' => ['base' => new \stdClass]]],
    ]));
    $html = PageRenderer::render($doc, ['metrics#won' => ['totals' => ['value' => 42]]])['html'];
    $this->assertStringContainsString('<p data-cms-kpi-value="">42</p>', $html);
});

/** Where the schema checks a binding (a repeat's each), $params and $scope must be objects. Props are open JSON. */
test('params and scope on a checked binding must be objects', function () {
    $doc = fn (mixed $params): array => ['id' => 'p', 'seq' => 0, 'meta' => ['title' => 'P', 'slug' => '/', 'scrollMode' => 'smooth'], 'theme' => ['name' => 'default'], 'breakpoints' => ['base'],
        'nodes' => ['k' => ['id' => 'k', 'type' => 'stack', 'parent' => null, 'order' => 'a', 'props' => [], 'style' => ['base' => []],
            'repeat' => ['each' => ['$bind' => 'm.rows', '$params' => $params, '$scope' => ['period' => ['$input' => 'period']]], 'as' => 'row']]]];
    $this->assertSame([], \FancyCms\Document\PageDoc::validate($doc(['groupBy' => 'owner'])));
    $this->assertSame('$.nodes.k.repeat.each.$params', \FancyCms\Document\PageDoc::validate($doc(['owner']))[0]['path'] ?? null);
});

test('the fixture matches the ui copy when the sibling is checked out', function () {
    $theirs = dirname(__DIR__, 2).'/fancy-cms-ui/tests/fixtures/data-queries.json';
    if (! is_file($theirs)) {
        $this->markTestSkipped('fancy-cms-ui is not checked out beside this repo.');
    }
    $this->assertSame(file_get_contents($theirs), file_get_contents(__DIR__.'/fixtures/data-queries.json'));
});
