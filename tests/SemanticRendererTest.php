<?php

declare(strict_types=1);

namespace FancyCms\Tests\SemanticRendererTest;

use FancyCms\Render\PageRenderer;
use FancyCms\Tests\Support\PageFixtures as F;

function html(string $type, array $props): string {
    $doc = F::doc();
    $node = F::node('a');
    $node['type'] = $type;
    $node['props'] = $props;
    $doc['nodes'] = ['a' => $node];

    return PageRenderer::render($doc)['html'];
}

function levels(): iterable {
    foreach ([[1, 'h1'], ['6', 'h6'], [3, 'h3'], ['4.5', 'h2'], ['bad', 'h2'], [null, 'h2'], [true, 'h2'], ['0x1', 'h1']] as [$level, $tag]) {
        yield [$level, $tag];
    }
}

test('heading level uses the js element rule', function (mixed $level, string $tag) {
    $this->assertSame('<'.$tag.' data-cms="a">Hello &lt;world&gt;</'.$tag.'>', html('heading', ['level' => $level, 'content' => 'Hello <world>']));
})->with(fn (): iterable => levels());

test('text is a paragraph and lists use safe list items', function () {
    $this->assertSame('<p data-cms="a">0</p>', html('text', ['content' => 0]));
    $this->assertSame('<ol data-cms="a"><li>One</li><li>&lt;script&gt;</li><li></li></ol>', html('list', ['ordered' => true, 'items' => ['One', '<script>', null]]));
    $this->assertSame('<ul data-cms="a"><li>x</li></ul>', html('list', ['items' => ['x']]));
});

function hrefs(): iterable {
    foreach (['javascript:alert(1)', " java\nscript:alert(1) ", 'data:text/html,<script>', 'vbscript:run', 'file:///secret'] as $href) {
        yield [$href];
    }
}

test('unsafe href schemes never become anchors', function (string $href) {
    $this->assertSame('<span data-cms="a">Go</span>', html('link', ['href' => $href, 'content' => 'Go']));
    $this->assertSame('<div data-cms="a" data-cms-button="primary">Go</div>', html('button', ['href' => $href, 'label' => 'Go']));
})->with(fn (): iterable => hrefs());

test('safe links and landmarks match the registry', function () {
    $this->assertSame('<a data-cms="a" href="https://example.test/a?x=1&amp;y=2" rel="noopener noreferrer" data-cms-button="primary">Go</a>', html('button', ['href' => ' https://example.test/a?x=1&y=2 ', 'label' => 'Go']));
    $this->assertSame('<a data-cms="a" href="#next">Next</a>', html('link', ['href' => '#next', 'content' => 'Next']));
    $this->assertSame('<header data-cms="a"></header>', html('stack', ['as' => 'header']));
    $this->assertSame('<main data-cms="a"></main>', html('section', ['as' => 'main']));
    $this->assertSame('<div data-cms="a"></div>', html('card', ['as' => 'script']));
    $this->assertSame('<section data-cms="a"></section>', html('section', []));
});

test('image attributes are escaped and fit is allowlisted', function () {
    $html = html('image', ['src' => '/photo.png?x=1&y=2', 'alt' => '<photo>', 'fit' => 'contain', 'loading' => 'eager']);
    $this->assertStringContainsString('src="/photo.png?x=1&amp;y=2"', $html);
    $this->assertStringContainsString('alt="&lt;photo&gt;"', $html);
    $this->assertStringContainsString('data-cms-fit="contain"', $html);
    $this->assertStringContainsString('loading="eager"', $html);
    $this->assertStringNotContainsString('data-cms-fit=', html('image', ['fit' => 'cover; color:red']));
    $this->assertStringNotContainsString('style=', $html);
});

test('semantic properties and content resolve only supplied data', function () {
    $doc = F::doc();
    $node = F::node('a');
    $node['type'] = 'heading';
    $node['props'] = ['level' => ['$bind' => 'article.level'], 'content' => ['$bind' => 'article.title']];
    $doc['nodes'] = ['a' => $node];
    $this->assertSame('<h1 data-cms="a">&lt;Head&gt;</h1>', PageRenderer::render($doc, ['article' => ['level' => 1, 'title' => '<Head>']])['html']);
});

test('in page anchor uses the ui slug allowlist and binding context', function () {
    $this->assertSame('<section data-cms="a" id="pricing"></section>', html('section', ['anchor' => 'pricing']));
    foreach (['Bad', '1bad', 'bad space', 'x" onclick="run', str_repeat('a', 65), "good\n"] as $bad) {
        $this->assertStringNotContainsString(' id=', html('section', ['anchor' => $bad]));
    }
    $doc = F::doc();
    $doc['nodes']['a']['props']['anchor'] = ['$bind' => 'anchor'];
    $this->assertStringContainsString('data-cms="a" id="contact-us"', PageRenderer::render($doc, ['anchor' => 'contact-us'])['html']);
});
