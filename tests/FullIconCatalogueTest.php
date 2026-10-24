<?php

declare(strict_types=1);

namespace FancyCms\Tests;

use FancyCms\Agent;
use FancyCms\Document\Icons;
use FancyCms\Document\PageDoc;
use FancyCms\Render\PageRenderer;
use FancyCms\Tests\Support\PageFixtures as F;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class FullIconCatalogueTest extends TestCase
{
    public function test_every_published_lucide_name_and_alias_is_closed_valid_and_renderable(): void
    {
        $names = Icons::names();
        self::assertCount(1803, $names);
        $sorted = $names;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $names);
        self::assertSame(Icons::glyph('house'), Icons::glyph('home'));
        foreach ($names as $name) {
            $node = array_replace(F::node('icon'), ['type' => 'icon', 'props' => ['name' => $name, 'decorative' => true]]);
            $doc = array_replace(F::doc(), ['nodes' => ['icon' => $node]]);
            self::assertSame([], PageDoc::validate($doc), $name);
            self::assertStringContainsString('<svg', PageRenderer::render($doc)['html'], $name);
        }
        self::assertNull(Icons::glyph('../house'));
        self::assertNull(Icons::glyph('constructor'));
    }

    public function test_search_is_bounded_paginatable_and_does_not_expand_describe(): void
    {
        $first = Agent::icons('FILE text', 0);
        self::assertSame('lucide-react@0.511.0', $first['source']);
        self::assertSame(['file-text'], $first['names']);
        self::assertNull($first['nextCursor']);
        $seen = [];
        $cursor = 0;
        do {
            $page = Agent::icons('', $cursor);
            self::assertLessThanOrEqual(20, count($page['names']));
            array_push($seen, ...$page['names']);
            $cursor = $page['nextCursor'];
        } while ($cursor !== null);
        self::assertSame(Icons::names(), $seen);
        self::assertSame([], Agent::icons('no-such-icon-name', 0)['names']);
        self::assertSame([], Agent::icons('', 100000)['names']);
        self::assertIsString(Agent::describe()['elements']['icon']['name']);
        self::assertStringNotContainsString('a-arrow-down', json_encode(Agent::describe(), JSON_THROW_ON_ERROR));
    }

    public function test_search_refuses_markup_non_ascii_excessive_queries_and_negative_cursors(): void
    {
        foreach ([['<svg>', 0], ['é', 0], ["file\n", 0], [str_repeat('a', 129), 0], ['file', -1]] as [$query, $cursor]) {
            try {
                Agent::icons($query, $cursor);
                self::fail('Invalid search was accepted.');
            } catch (InvalidArgumentException $e) {
                self::assertSame('Invalid icon search.', $e->getMessage());
            }
        }
    }

    #[RunInSeparateProcess]
    public function test_loading_the_class_does_not_read_the_index_or_glyphs(): void
    {
        $reflection = new \ReflectionClass(Icons::class);
        self::assertNull($reflection->getStaticPropertyValue('index'));
        self::assertSame([], $reflection->getStaticPropertyValue('glyphs'));
        Icons::glyph('check');
        self::assertIsArray($reflection->getStaticPropertyValue('index'));
        self::assertSame(['check'], array_keys($reflection->getStaticPropertyValue('glyphs')));
    }
}
