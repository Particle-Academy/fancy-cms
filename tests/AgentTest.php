<?php

declare(strict_types=1);

namespace FancyCms\Tests;

use FancyCms\Agent;
use FancyCms\Document\PageDoc;
use FancyCms\Document\PageOp;
use PHPUnit\Framework\TestCase;

final class AgentTest extends TestCase
{
    public function test_shipped_guide_is_the_canonical_bytes(): void
    {
        $guide = Agent::guide();
        self::assertSame('df2628c033d7ac4c5b802545274a9ddd26537115afc2d2fdf1e774dd8a3349b4', hash('sha256', $guide));
        self::assertStringContainsString('surface_changed_under_you', $guide);
        self::assertStringContainsString('their edit is the new intent', $guide);
        $mirror = getenv('FANCY_CMS_GUIDE_MIRROR') ?: dirname(__DIR__, 2).'/fancy-cms-ui/agent-guide/professional-pages.md';
        if (getenv('FANCY_CMS_GUIDE_MIRROR') !== false || is_file($mirror)) {
            self::assertFileExists($mirror);
            self::assertSame(file_get_contents($mirror), $guide, 'PHP and UI agent guides must ship identical bytes.');
        }
    }

    public function test_catalogue_covers_wire_ops_and_safe_elements_with_a_bounded_prompt_cost(): void
    {
        $catalogue = Agent::describe();
        self::assertSame(PageOp::TYPES, array_keys($catalogue['ops']));
        self::assertEqualsCanonicalizing(array_values(array_diff(PageDoc::NODE_TYPES, ['richtext'])), array_keys($catalogue['elements']));
        self::assertSame([1, 2, 3, 4, 5, 6], $catalogue['elements']['heading']['level']);
        self::assertArrayHasKey('href', $catalogue['elements']['button']);
        self::assertArrayHasKey('variant', $catalogue['elements']['button']);
        self::assertArrayHasKey('ordered', $catalogue['elements']['list']);
        self::assertArrayHasKey('fit', $catalogue['elements']['image']);
        self::assertArrayHasKey('anchor', $catalogue['elements']['section']);
        self::assertArrayHasKey('as', $catalogue['elements']['stack']);
        self::assertSame(['px', '%', 'vw', 'vh', 'rem', 'fr'], $catalogue['shapes']['Length']['unit']);
        self::assertSame(['row', 'column'], $catalogue['style']['direction']);
        self::assertSame(['base' => 0, 'md' => 768, 'lg' => 1024], $catalogue['breakpoints']);
        self::assertLessThanOrEqual(8000, strlen(json_encode($catalogue, JSON_THROW_ON_ERROR)), 'Catalogue byte cost is a release metric.');
    }
}
