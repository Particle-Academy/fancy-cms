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
        self::assertSame('c801f4ef5d38ebb3f7741b3acbb573df28654e0f8ff6d952c0e7e4d5328ffe19', hash('sha256', $guide));
        self::assertStringContainsString('image.props.asset', $guide);
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
        $islands = $catalogue['islands'];
        unset($catalogue['islands']);
        self::assertLessThanOrEqual(8000, strlen(json_encode($catalogue, JSON_THROW_ON_ERROR)), 'Catalogue byte cost is a release metric.');
        // The built-in island schemas are budgeted separately: a host's own
        // islands add to this, and only the host can bound those.
        // 5000 held the four infographic types (4640 bytes). Page inputs and the
        // $params/$scope binding keys took it to 5913 after trimming every
        // description (2026-11-12); a sixth type should earn its bytes the same way.
        // The sixth is the echart node (CC-fancy-0035, approved 2026-11-13): trimmed
        // to 841 bytes, its full rules live in the guidance, 6768 in all.
        self::assertLessThanOrEqual(6800, strlen(json_encode($islands, JSON_THROW_ON_ERROR)), 'Built-in island catalogue byte cost is a release metric.');
    }
}
