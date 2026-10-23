<?php

declare(strict_types=1);

namespace FancyCms\Tests;

use FancyCms\Render\PageRenderer;
use FancyCms\Tests\Support\PageFixtures as F;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SemanticRendererTest extends TestCase
{
    private function html(string $type, array $props): string
    {
        $doc = F::doc();
        $node = F::node('a');
        $node['type'] = $type;
        $node['props'] = $props;
        $doc['nodes'] = ['a' => $node];

        return PageRenderer::render($doc)['html'];
    }

    public static function levels(): iterable
    {
        foreach ([[1, 'h1'], ['6', 'h6'], [3, 'h3'], ['4.5', 'h2'], ['bad', 'h2'], [null, 'h2'], [true, 'h2'], ['0x1', 'h1']] as [$level, $tag]) {
            yield [$level, $tag];
        }
    }

    #[DataProvider('levels')]
    public function test_heading_level_uses_the_js_element_rule(mixed $level, string $tag): void
    {
        self::assertSame('<'.$tag.' data-cms="a">Hello &lt;world&gt;</'.$tag.'>', $this->html('heading', ['level' => $level, 'content' => 'Hello <world>']));
    }

    public function test_text_is_a_paragraph_and_lists_use_safe_list_items(): void
    {
        self::assertSame('<p data-cms="a">0</p>', $this->html('text', ['content' => 0]));
        self::assertSame('<ol data-cms="a"><li>One</li><li>&lt;script&gt;</li><li></li></ol>', $this->html('list', ['ordered' => true, 'items' => ['One', '<script>', null]]));
        self::assertSame('<ul data-cms="a"><li>x</li></ul>', $this->html('list', ['items' => ['x']]));
    }

    public static function hrefs(): iterable
    {
        foreach (['javascript:alert(1)', " java\nscript:alert(1) ", 'data:text/html,<script>', 'vbscript:run', 'file:///secret'] as $href) {
            yield [$href];
        }
    }

    #[DataProvider('hrefs')]
    public function test_unsafe_href_schemes_never_become_anchors(string $href): void
    {
        self::assertSame('<span data-cms="a">Go</span>', $this->html('link', ['href' => $href, 'content' => 'Go']));
        self::assertSame('<div data-cms="a">Go</div>', $this->html('button', ['href' => $href, 'label' => 'Go']));
    }

    public function test_safe_links_and_landmarks_match_the_registry(): void
    {
        self::assertSame('<a data-cms="a" href="https://example.test/a?x=1&amp;y=2" rel="noopener noreferrer">Go</a>', $this->html('button', ['href' => ' https://example.test/a?x=1&y=2 ', 'label' => 'Go']));
        self::assertSame('<a data-cms="a" href="#next">Next</a>', $this->html('link', ['href' => '#next', 'content' => 'Next']));
        self::assertSame('<header data-cms="a"></header>', $this->html('stack', ['as' => 'header']));
        self::assertSame('<main data-cms="a"></main>', $this->html('section', ['as' => 'main']));
        self::assertSame('<div data-cms="a"></div>', $this->html('card', ['as' => 'script']));
        self::assertSame('<section data-cms="a"></section>', $this->html('section', []));
    }

    public function test_image_attributes_are_escaped_and_fit_is_allowlisted(): void
    {
        $html = $this->html('image', ['src' => '/photo.png?x=1&y=2', 'alt' => '<photo>', 'fit' => 'contain', 'loading' => 'eager']);
        self::assertStringContainsString('src="/photo.png?x=1&amp;y=2"', $html);
        self::assertStringContainsString('alt="&lt;photo&gt;"', $html);
        self::assertStringContainsString('object-fit:contain;', $html);
        self::assertStringContainsString('loading="eager"', $html);
        self::assertStringContainsString('object-fit:cover;', $this->html('image', ['fit' => 'cover; color:red']));
    }

    public function test_semantic_properties_and_content_resolve_only_supplied_data(): void
    {
        $doc = F::doc();
        $node = F::node('a');
        $node['type'] = 'heading';
        $node['props'] = ['level' => ['$bind' => 'article.level'], 'content' => ['$bind' => 'article.title']];
        $doc['nodes'] = ['a' => $node];
        self::assertSame('<h1 data-cms="a">&lt;Head&gt;</h1>', PageRenderer::render($doc, ['article' => ['level' => 1, 'title' => '<Head>']])['html']);
    }
}
