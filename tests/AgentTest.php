<?php

declare(strict_types=1);

namespace FancyCms\Tests\AgentTest;

use FancyCms\Agent;
use FancyCms\Document\PageDoc;
use FancyCms\Document\PageOp;

test('shipped guide is the canonical bytes', function () {
    $guide = Agent::guide();
    $this->assertSame('c801f4ef5d38ebb3f7741b3acbb573df28654e0f8ff6d952c0e7e4d5328ffe19', hash('sha256', $guide));
    $this->assertStringContainsString('image.props.asset', $guide);
    $this->assertStringContainsString('surface_changed_under_you', $guide);
    $this->assertStringContainsString('their edit is the new intent', $guide);
    $mirror = getenv('FANCY_CMS_GUIDE_MIRROR') ?: dirname(__DIR__, 2).'/fancy-cms-ui/agent-guide/professional-pages.md';
    if (getenv('FANCY_CMS_GUIDE_MIRROR') !== false || is_file($mirror)) {
        $this->assertFileExists($mirror);
        $this->assertSame(file_get_contents($mirror), $guide, 'PHP and UI agent guides must ship identical bytes.');
    }
});

test('catalogue covers wire ops and safe elements with a bounded prompt cost', function () {
    $catalogue = Agent::describe();
    $this->assertSame(PageOp::TYPES, array_keys($catalogue['ops']));
    $this->assertEqualsCanonicalizing(array_values(array_diff(PageDoc::NODE_TYPES, ['richtext'])), array_keys($catalogue['elements']));
    $this->assertSame([1, 2, 3, 4, 5, 6], $catalogue['elements']['heading']['level']);
    $this->assertArrayHasKey('href', $catalogue['elements']['button']);
    $this->assertArrayHasKey('variant', $catalogue['elements']['button']);
    $this->assertArrayHasKey('ordered', $catalogue['elements']['list']);
    $this->assertArrayHasKey('fit', $catalogue['elements']['image']);
    $this->assertArrayHasKey('anchor', $catalogue['elements']['section']);
    $this->assertArrayHasKey('as', $catalogue['elements']['stack']);
    $this->assertSame(['px', '%', 'vw', 'vh', 'rem', 'fr'], $catalogue['shapes']['Length']['unit']);
    $this->assertSame(['row', 'column'], $catalogue['style']['direction']);
    $this->assertSame(['base' => 0, 'md' => 768, 'lg' => 1024], $catalogue['breakpoints']);
    $islands = $catalogue['islands'];
    unset($catalogue['islands']);
    $this->assertLessThanOrEqual(8000, strlen(json_encode($catalogue, JSON_THROW_ON_ERROR)), 'Catalogue byte cost is a release metric.');
    // The built-in island schemas are budgeted separately: a host's own
    // islands add to this, and only the host can bound those.
    // 5000 held the four infographic types (4640 bytes). Page inputs and the
    // $params/$scope binding keys took it to 5913 after trimming every
    // description (2026-11-12); a sixth type should earn its bytes the same way.
    // The sixth is the echart node (CC-fancy-0035, approved 2026-11-13): trimmed
    // to 841 bytes, its full rules live in the guidance, 6768 in all.
    $this->assertLessThanOrEqual(6800, strlen(json_encode($islands, JSON_THROW_ON_ERROR)), 'Built-in island catalogue byte cost is a release metric.');
});
