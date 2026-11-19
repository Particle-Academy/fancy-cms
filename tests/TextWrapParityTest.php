<?php

declare(strict_types=1);

namespace FancyCms\Tests\TextWrapParityTest;

use FancyCms\Agent;
use FancyCms\Document\PageDoc;
use FancyCms\Document\PageJson;
use FancyCms\Render\CssEmitter;
use FancyCms\Render\PageRenderer;
use FancyCms\Render\TextWrap;
use FancyCms\Tests\Support\PageFixtures as F;

/**
 * Lab finding S11: a text node holding an unbroken long string scrolled the
 * page sideways at 375px (hostile-facts measured 380px), and a document had
 * no way to fix it.
 *
 * 0.9.0 gave every text node `overflow-wrap: anywhere`, which lowered every
 * text node's min-content width, so grid tracks and flex items shrank below
 * ordinary words at tablet widths and broke them mid-word ("Discriminatio/n",
 * "$/9", "41/%": lab runs 542 to 554). Now text nodes get `break-word`, which
 * keeps min-content, and only a node whose rendered text holds a long token is
 * marked `data-cms-wrap="anywhere"`. tests/fixtures/text-wrap.json is
 * byte-identical in @particle-academy/fancy-cms-ui, whose suite also checks, in
 * a DOM, that each rule's selector matches exactly the elements each case says
 * it does, and whose parity/text-wrap-browser.mjs measures it in a browser.
 */

function fixture(): object {
    return json_decode((string) file_get_contents(__DIR__.'/fixtures/text-wrap.json'), false, 512, JSON_THROW_ON_ERROR);
}

/** A document exactly as a host decodes one from storage. */
function doc(array $nodes): array {
    $map = new \stdClass;
    foreach ($nodes as $node) {
        $map->{$node->id} = $node;
    }

    return PageJson::decodeDocument((string) json_encode([
        'id' => 'wrap', 'seq' => 0, 'meta' => ['title' => 'Wrap', 'slug' => '/', 'scrollMode' => 'smooth'],
        'theme' => ['name' => 'default'], 'breakpoints' => ['base', 'md', 'lg'], 'nodes' => $map,
    ], JSON_THROW_ON_ERROR));
}

function cases(): iterable {
    foreach (fixture()->cases as $case) {
        yield $case->name => [$case];
    }
}

function styles(): iterable {
    foreach (fixture()->styles as $case) {
        yield $case->name => [$case];
    }
}

test('the base stylesheet carries the shared rules the long token rule straight after', function () {
    $this->assertStringContainsString("\n\n".fixture()->rule."\n\n".fixture()->longRule."\n\n", CssEmitter::BASE_CSS);
});

test('ordinary text never lowers its min content', function () {
    $this->assertStringContainsString('overflow-wrap: break-word;', fixture()->rule);
    $this->assertStringNotContainsString("[data-cms] {\n  overflow-wrap: anywhere;", CssEmitter::BASE_CSS);
});

test('the threshold is the shared one', function () {
    $n = fixture()->longToken;
    $this->assertSame($n, TextWrap::LONG_TOKEN);
    $this->assertFalse(TextWrap::hasLongToken(str_repeat('a', $n - 1)));
    $this->assertTrue(TextWrap::hasLongToken(str_repeat('a', $n)));
});

test('a bound value is measured after it resolves', function () {
    $node = (object) ['id' => 'b', 'type' => 'text', 'parent' => null, 'order' => 'a0', 'props' => (object) ['content' => (object) ['$bind' => 'site.url']], 'style' => (object) ['base' => new \stdClass]];
    $this->assertSame(
        '<p data-cms="b" data-cms-wrap="anywhere">https://example.com/a/very/long/path</p>',
        PageRenderer::render(doc([$node]), ['site' => ['url' => 'https://example.com/a/very/long/path']])['html'],
    );
});

test('the rule comes after the margin reset', function () {
    $css = CssEmitter::BASE_CSS;
    $this->assertGreaterThan(strpos($css, ':where(h1, h2, h3, h4, h5, h6, p, ul, ol)[data-cms] {'), strpos($css, fixture()->rule));
});

test('renders the shared markup', function (object $case) {
    $this->assertSame($case->html, PageRenderer::render(doc($case->nodes))['html']);
})->with(fn (): iterable => cases());

test('overflow wrap emits the shared declarations', function (object $case) {
    $doc = F::doc();
    $doc['nodes'] = ['a' => F::node('a')];
    $doc['nodes']['a']['style']['base'] = (array) $case->style;
    $css = CssEmitter::emit($doc);
    $this->assertStringStartsWith(CssEmitter::BASE_CSS, $css);
    $this->assertSame($case->css, $css === CssEmitter::BASE_CSS."\n" ? '' : substr($css, strlen(CssEmitter::BASE_CSS) + 2));
})->with(fn (): iterable => styles());

test('the schema accepts the keywords and refuses anything else', function () {
    foreach (['normal', 'break-word', 'anywhere'] as $value) {
        $doc = F::doc();
        $doc['nodes']['a']['style']['base'] = ['overflowWrap' => $value];
        $this->assertSame([], PageDoc::validate($doc), $value);
    }
    foreach (['break-all', 'normal; color: red', 1] as $value) {
        $doc = F::doc();
        $doc['nodes']['a']['style']['base'] = ['overflowWrap' => $value];
        $errors = PageDoc::validate($doc);
        $this->assertNotSame([], $errors, (string) $value);
        $this->assertSame('$.nodes.a.style.base.overflowWrap', $errors[0]['path']);
    }
});

test('an agent is told the keywords', function () {
    $this->assertSame(['normal', 'break-word', 'anywhere'], Agent::describe()['style']['overflowWrap']);
});

test('the fixture is byte identical to fancy cms ui when beside it', function () {
    $theirs = __DIR__.'/../../fancy-cms-ui/tests/fixtures/text-wrap.json';
    if (! is_file($theirs)) {
        $this->markTestSkipped('fancy-cms-ui is not checked out beside this repo.');
    }
    $this->assertSame(file_get_contents($theirs), file_get_contents(__DIR__.'/fixtures/text-wrap.json'));
});
