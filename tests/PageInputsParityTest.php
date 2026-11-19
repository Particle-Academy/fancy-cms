<?php

declare(strict_types=1);

namespace FancyCms\Tests\PageInputsParityTest;

use FancyCms\Data\DataRequests;
use FancyCms\Data\PageInputs;
use FancyCms\Document\PageJson;
use FancyCms\Islands\IslandCatalogue;
use FancyCms\Render\CssEmitter;
use FancyCms\Render\Inputs;
use FancyCms\Render\PageRenderer;

/**
 * The PHP half of the page-input parity spec. tests/fixtures/inputs.json is
 * byte-identical in @particle-academy/fancy-cms-ui, whose suite renders the
 * same cases through React's CmsPage and parses the same query strings.
 */

function fixture(): object {
    return json_decode((string) file_get_contents(__DIR__.'/fixtures/inputs.json'), false, 512, JSON_THROW_ON_ERROR);
}

/** A document exactly as a host decodes one from storage. */
function doc(array $nodes): array {
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
function data(object $value): array {
    return (array) $value;
}

function cases(): iterable {
    foreach (fixture()->cases as $case) {
        yield $case->name => [$case];
    }
}

function queries(): iterable {
    foreach (fixture()->query->cases as $case) {
        yield $case->name => [$case];
    }
}

test('renders the shared case', function (object $case) {
    $html = PageRenderer::render(doc($case->nodes), data($case->data), inputs: (array) $case->inputs)['html'];
    $this->assertSame($case->html, $html);
})->with(fn (): iterable => cases());

test('reads and writes the shared query', function (object $case) {
    $q = fixture()->query;
    $doc = doc($q->nodes);
    parse_str($case->search, $query);
    $values = PageInputs::fromQuery($doc, $query, data($q->data));
    // Keyed by name; which order the keys come in is not part of the contract.
    $this->assertEquals(json_decode((string) json_encode($case->values), true), $values);
    $this->assertSame($case->encoded, PageInputs::toQuery($doc, json_decode((string) json_encode($case->values), true), data($q->data)));
})->with(fn (): iterable => queries());

test('declares each named input once ordered by node id', function () {
    $q = fixture()->query;
    $this->assertSame(json_encode($q->declared), json_encode(PageInputs::declared(doc($q->nodes))));
});

test('a bound option list is a data request with its expected shape', function () {
    $q = fixture()->query;
    $requests = array_values(array_filter(DataRequests::of(doc($q->nodes)), static fn (array $r): bool => $r['node'] === 'r'));
    $this->assertSame(json_encode($q->requests), json_encode($requests));
});

test('the stylesheet block is shared and only emitted when used', function () {
    $fixture = fixture();
    $this->assertSame($fixture->css, Inputs::CSS);
    $this->assertSame(CssEmitter::BASE_CSS."\n\n".Inputs::CSS."\n", CssEmitter::emit(doc($fixture->cases[0]->nodes)));
});

test('input is a known node type and a lazy island in the catalogue', function () {
    $this->assertTrue(IslandCatalogue::builtin()->has('input'));
    $this->assertTrue(IslandCatalogue::builtin()->hydrates('input'));
    $this->assertContains('input', IslandCatalogue::builtin()->nodeTypes());
});

test('the head style element is the shared bytes', function () {
    $tag = fixture()->styleTag;
    $this->assertSame($tag->html, PageRenderer::styleTag(['id' => $tag->key], $tag->css));
    $this->assertSame('', PageRenderer::styleTag(['id' => 'x'], ''));
});

/** @return iterable<string,array{object}> */
function hostileStyleTags(): iterable {
    foreach (fixture()->styleTagHostile as $tag) {
        yield json_encode($tag->css, JSON_THROW_ON_ERROR) => [$tag];
    }
}

/**
 * styleTag is public, so a host can hand it any CSS, not only the
 * emitter's. Whatever the CSS says, the element must be one <style> that an
 * HTML5 parser closes where we close it.
 */
test('hostile css cannot close the head style element', function (object $tag) {
    $html = PageRenderer::styleTag(['id' => $tag->key], $tag->css);
    $this->assertSame($tag->html, $html);
    $this->assertStringNotContainsString('</style', strtolower(substr($html, 0, -strlen('</style>'))));
    $this->assertStringNotContainsString('<!--', $html);

    $dom = \Dom\HTMLDocument::createFromString(
        '<!doctype html><html><head>'.$html.'<meta name="after"></head><body></body></html>',
        LIBXML_NOERROR,
    );
    $styles = $dom->querySelectorAll('style');
    $this->assertCount(1, $styles);
    $this->assertNull($dom->querySelector('script, img'));
    $this->assertSame(PageRenderer::styleText($tag->css), $styles->item(0)->textContent);
    $this->assertSame('after', $styles->item(0)->nextElementSibling?->getAttribute('name'));
})->with(fn (): iterable => hostileStyleTags());

test('the hostile fixture covers the named cases', function () {
    $all = implode("\n", array_map(static fn (object $t): string => $t->css, fixture()->styleTagHostile));
    $this->assertStringContainsString('</style><script>', $all);
    $this->assertStringContainsString('</STYLE', $all);
    $this->assertStringContainsString('<!--', $all);
});

test('style text writes the css escape for less than', function () {
    $this->assertSame(chr(92).'3C /', PageRenderer::styleText('</'));
    $css = ":where([data-cms] > img) {\n  width: 100%;\n}\n";
    $this->assertSame($css, PageRenderer::styleText($css));
});

test('the page stylesheet is for the head and never in the html', function () {
    $doc = doc(fixture()->cases[0]->nodes);
    $page = PageRenderer::render($doc);
    $this->assertStringNotContainsString('<style', $page['html']);
    $this->assertSame('<style data-cms-styles="in">'.$page['css'].'</style>', PageRenderer::styleTag($doc, $page['css']));
});

test('the fixture matches the ui copy when the sibling is checked out', function () {
    $theirs = dirname(__DIR__, 2).'/fancy-cms-ui/tests/fixtures/inputs.json';
    if (! is_file($theirs)) {
        $this->markTestSkipped('fancy-cms-ui is not checked out beside this repo.');
    }
    $this->assertSame(file_get_contents($theirs), file_get_contents(__DIR__.'/fixtures/inputs.json'));
});
