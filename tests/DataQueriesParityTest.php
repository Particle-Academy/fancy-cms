<?php

declare(strict_types=1);

namespace FancyCms\Tests;

use FancyCms\Data\DataRequests;
use FancyCms\Document\PageJson;
use FancyCms\Render\PageRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The bindings Charlotte writes: `{$bind, $source?}` plus `$params`, `$scope`
 * and `<stream>#<alias>`, with `{"$input"}` references fed by page inputs.
 * tests/fixtures/data-queries.json is byte-identical in fancy-cms-ui, whose
 * dataQueries() must return the same queries.
 */
final class DataQueriesParityTest extends TestCase
{
    private static function fixture(): object
    {
        return json_decode((string) file_get_contents(__DIR__.'/fixtures/data-queries.json'), false, 512, JSON_THROW_ON_ERROR);
    }

    private static function doc(): array
    {
        $map = new \stdClass;
        foreach (self::fixture()->nodes as $node) {
            $map->{$node->id} = $node;
        }

        return PageJson::decodeDocument((string) json_encode([
            'id' => 'q', 'seq' => 0, 'meta' => ['title' => 'Q', 'slug' => '/', 'scrollMode' => 'smooth'],
            'theme' => ['name' => 'default'], 'breakpoints' => ['base'], 'nodes' => $map,
        ], JSON_THROW_ON_ERROR));
    }

    /** JSON-equal: decoded to plain arrays on both sides, so an empty object and an empty array compare alike. */
    private static function plain(mixed $v): mixed
    {
        return json_decode((string) json_encode($v, JSON_THROW_ON_ERROR), true);
    }

    public static function cases(): iterable
    {
        foreach (self::fixture()->cases as $case) {
            yield $case->name => [$case];
        }
    }

    #[DataProvider('cases')]
    public function test_returns_the_shared_queries(object $case): void
    {
        $queries = DataRequests::queries(self::doc(), json_decode((string) json_encode($case->inputs), true), (array) self::fixture()->data);
        self::assertSame(self::plain($case->queries), self::plain($queries));
    }

    public function test_a_data_request_carries_the_bindings_params_and_scope_as_written(): void
    {
        $request = array_values(array_filter(DataRequests::of(self::doc()), static fn (array $r): bool => $r['node'] === 'k1'))[0];
        self::assertSame(json_encode(self::fixture()->request), json_encode($request));
    }

    public function test_an_aliased_path_renders_from_the_result_under_its_key(): void
    {
        $doc = PageJson::decodeDocument((string) json_encode([
            'id' => 'a', 'seq' => 0, 'meta' => ['title' => 'A', 'slug' => '/', 'scrollMode' => 'smooth'], 'theme' => ['name' => 'default'], 'breakpoints' => ['base'],
            'nodes' => ['k' => ['id' => 'k', 'type' => 'kpi', 'parent' => null, 'order' => 'a0', 'props' => ['value' => ['$bind' => 'metrics#won.totals.value', '$params' => ['stage' => 'won']]], 'style' => ['base' => new \stdClass]]],
        ]));
        $html = PageRenderer::render($doc, ['metrics#won' => ['totals' => ['value' => 42]]])['html'];
        self::assertStringContainsString('<p data-cms-kpi-value="">42</p>', $html);
    }

    /** Where the schema checks a binding (a repeat's each), $params and $scope must be objects. Props are open JSON. */
    public function test_params_and_scope_on_a_checked_binding_must_be_objects(): void
    {
        $doc = fn (mixed $params): array => ['id' => 'p', 'seq' => 0, 'meta' => ['title' => 'P', 'slug' => '/', 'scrollMode' => 'smooth'], 'theme' => ['name' => 'default'], 'breakpoints' => ['base'],
            'nodes' => ['k' => ['id' => 'k', 'type' => 'stack', 'parent' => null, 'order' => 'a', 'props' => [], 'style' => ['base' => []],
                'repeat' => ['each' => ['$bind' => 'm.rows', '$params' => $params, '$scope' => ['period' => ['$input' => 'period']]], 'as' => 'row']]]];
        self::assertSame([], \FancyCms\Document\PageDoc::validate($doc(['groupBy' => 'owner'])));
        self::assertSame('$.nodes.k.repeat.each.$params', \FancyCms\Document\PageDoc::validate($doc(['owner']))[0]['path'] ?? null);
    }

    public function test_the_fixture_matches_the_ui_copy_when_the_sibling_is_checked_out(): void
    {
        $theirs = dirname(__DIR__, 2).'/fancy-cms-ui/tests/fixtures/data-queries.json';
        if (! is_file($theirs)) {
            self::markTestSkipped('fancy-cms-ui is not checked out beside this repo.');
        }
        self::assertSame(file_get_contents($theirs), file_get_contents(__DIR__.'/fixtures/data-queries.json'));
    }
}
