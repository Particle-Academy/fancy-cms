<?php

declare(strict_types=1);

namespace FancyCms\Tests\InfographicParityTest;

use FancyCms\Data\DataRequests;
use FancyCms\Document\PageJson;
use FancyCms\Islands\IslandCatalogue;
use FancyCms\Render\CssEmitter;
use FancyCms\Render\Infographics;
use FancyCms\Render\PageRenderer;

/**
 * The PHP half of the infographic parity spec. tests/fixtures/infographics.json
 * is byte-identical in @particle-academy/fancy-cms-ui, whose suite renders the
 * same cases through React's CmsPage and must produce the same html.
 */

function fixture(): object {
    return json_decode((string) file_get_contents(__DIR__.'/fixtures/infographics.json'), false, 512, JSON_THROW_ON_ERROR);
}

/** A document exactly as a host decodes one from storage. */
function doc(array $nodes): array {
    $map = new \stdClass;
    foreach ($nodes as $node) {
        $map->{$node->id} = $node;
    }

    return PageJson::decodeDocument((string) json_encode([
        'id' => 'ig', 'seq' => 0, 'meta' => ['title' => 'Infographics', 'slug' => '/', 'scrollMode' => 'smooth'],
        'theme' => ['name' => 'default'], 'breakpoints' => ['base', 'md', 'lg'], 'nodes' => $map,
    ], JSON_THROW_ON_ERROR));
}

function cases(): iterable {
    foreach (fixture()->cases as $case) {
        yield $case->name => [$case];
    }
}

test('renders the shared case', function (object $case) {
    $html = PageRenderer::render(doc($case->nodes), (array) $case->data)['html'];
    $this->assertSame($case->html, $html);
})->with(fn (): iterable => cases());

test('the stylesheet block is shared and only emitted when used', function () {
    $fixture = fixture();
    $this->assertSame($fixture->css, Infographics::CSS);
    $css = CssEmitter::emit(doc($fixture->cases[0]->nodes));
    $this->assertSame(CssEmitter::BASE_CSS."\n\n".Infographics::CSS."\n", $css);
    $plain = CssEmitter::emit(doc([(object) ['id' => 'p', 'type' => 'text', 'parent' => null, 'order' => 'a', 'props' => ['content' => 'x'], 'style' => ['base' => new \stdClass]]]));
    $this->assertStringNotContainsString('fancy-cms infographics', $plain);
});

test('data requests list every bound path with its expected shape', function () {
    $fixture = fixture();
    $actual = DataRequests::of(doc($fixture->requests->nodes));
    $this->assertSame(
        json_encode($fixture->requests->expected, JSON_THROW_ON_ERROR),
        json_encode($actual, JSON_THROW_ON_ERROR),
    );
});

test('the shared files match the ui copies when the sibling is checked out', function () {
    $sibling = dirname(__DIR__, 2).'/fancy-cms-ui';
    $pairs = [
        [__DIR__.'/fixtures/infographics.json', $sibling.'/tests/fixtures/infographics.json'],
        [dirname(__DIR__).'/resources/islands/catalogue.json', $sibling.'/src/islands/catalogue.json'],
    ];
    foreach ($pairs as [$mine, $theirs]) {
        if (is_file($theirs)) {
            $this->assertSame(file_get_contents($theirs), file_get_contents($mine), basename($mine).' must ship identical bytes in both packages.');
        }
    }
    $this->assertSame(
        json_decode((string) file_get_contents(dirname(__DIR__).'/resources/islands/catalogue.json'), true),
        json_decode((string) json_encode(IslandCatalogue::builtin()->toArray()), true),
    );
});
