<?php

declare(strict_types=1);

namespace FancyCms\Tests;

use FancyCms\Document\PageDoc;
use FancyCms\Render\CssEmitter;
use FancyCms\Render\PageRenderer;
use FancyCms\Tests\Support\PageFixtures;
use PHPUnit\Framework\TestCase;

final class NoInlineStylesTest extends TestCase
{
    public function test_every_native_node_type_renders_without_generated_inline_styles(): void
    {
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
            self::assertStringContainsString('data-cms="'.$type.'"', $rendered['html']);
        }
        self::assertStringContainsString('data-react-fancy-icon=""', $rendered['html']);
        self::assertSame(0, preg_match('/\sstyle\s*=/i', $rendered['html']), 'Native renderer styles must live in the base stylesheet.');
        self::assertStringContainsString('font-size: 24px;', $rendered['css']);
    }

    public function test_icon_wrapper_declarations_are_owned_by_the_base_stylesheet(): void
    {
        self::assertStringContainsString(":where([data-react-fancy-icon]) {\n  display: inline-flex;\n  align-items: center;\n  justify-content: center;\n  flex-shrink: 0;\n  width: 1em;\n  height: 1em;\n  vertical-align: middle;\n}", CssEmitter::BASE_CSS);
    }
}
