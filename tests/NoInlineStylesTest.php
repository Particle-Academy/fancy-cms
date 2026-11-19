<?php

declare(strict_types=1);

namespace FancyCms\Tests\NoInlineStylesTest;

use FancyCms\Document\PageDoc;
use FancyCms\Render\CssEmitter;
use FancyCms\Render\PageRenderer;
use FancyCms\Tests\Support\PageFixtures;

test('every native node type renders without generated inline styles', function () {
    $doc = PageFixtures::doc();
    $doc['nodes'] = [];
    foreach (PageDoc::NODE_TYPES as $type) {
        $node = PageFixtures::node($type, null, $type);
        $node['type'] = $type;
        $node['props'] = match ($type) {
            'icon' => ['name' => 'receipt-text', 'decorative' => true],
            'richtext' => ['html' => '<strong>Authored text without inline CSS</strong>'],
            'image' => ['alt' => 'Empty image placeholder'],
            'list', 'repeater' => ['items' => ['One', 'Two']],
            default => ['content' => 'Sample '.$type],
        };
        $node['style'] = ['base' => ['fontSize' => ['value' => 24, 'unit' => 'px'], 'color' => '#125678']];
        $doc['nodes'][$type] = $node;
    }
    PageDoc::assert($doc);
    $rendered = PageRenderer::render($doc);
    foreach (PageDoc::NODE_TYPES as $type) {
        $this->assertStringContainsString('data-cms="'.$type.'"', $rendered['html']);
    }
    $this->assertStringContainsString('data-react-fancy-icon=""', $rendered['html']);
    $this->assertSame(0, preg_match('/\sstyle\s*=/i', $rendered['html']), 'Native renderer styles must live in the base stylesheet.');
    $this->assertStringContainsString('font-size: 24px;', $rendered['css']);
});

test('icon wrapper declarations are owned by the base stylesheet', function () {
    $this->assertStringContainsString(":where([data-react-fancy-icon]) {\n  display: inline-flex;\n  align-items: center;\n  justify-content: center;\n  flex-shrink: 0;\n  width: 1em;\n  height: 1em;\n  vertical-align: middle;\n}", CssEmitter::BASE_CSS);
});
