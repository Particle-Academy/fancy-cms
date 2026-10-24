<?php

declare(strict_types=1);

namespace FancyCms\Tests;

use FancyCms\Agent;
use FancyCms\Document\PageDoc;
use FancyCms\Document\PageOp;
use FancyCms\Document\Reducer;
use FancyCms\Render\PageRenderer;
use FancyCms\Tests\Support\PageFixtures as F;
use PHPUnit\Framework\TestCase;

final class IconTest extends TestCase
{
    private const NAMES = ['arrow-right', 'calculator', 'check', 'file-text', 'quote', 'receipt-text', 'users'];

    private static function icon(array $props = []): array
    {
        return array_replace(F::node('icon'), ['type' => 'icon', 'props' => $props + ['name' => 'receipt-text', 'decorative' => true, 'label' => '']]);
    }

    private static function doc(array $props = []): array
    {
        return array_replace(F::doc(), ['nodes' => ['icon' => self::icon($props)]]);
    }

    public function test_all_allowlisted_icons_validate_and_render_inline_without_runtime_lookup(): void
    {
        self::assertSame('Lucide name; search cms_icons(query,cursor)', Agent::describe()['elements']['icon']['name'] ?? null);
        foreach (self::NAMES as $name) {
            $doc = self::doc(['name' => $name]);
            self::assertSame([], PageDoc::validate($doc), $name);
            self::assertSame([], PageOp::validate(['t' => 'insert_node', 'node' => $doc['nodes']['icon']]));
            $html = PageRenderer::render($doc)['html'];
            self::assertStringContainsString('data-react-fancy-icon=""', $html);
            self::assertStringContainsString('<svg', $html);
            self::assertStringContainsString('stroke="currentColor"', $html);
            self::assertStringContainsString('aria-hidden="true"', $html);
            self::assertStringNotContainsString('role="img"', $html);
        }
    }

    public function test_meaningful_icons_have_an_escaped_label_and_decorative_icons_hide_it(): void
    {
        $label = 'Receipt <script> & "tax"';
        $html = PageRenderer::render(self::doc(['decorative' => false, 'label' => $label]))['html'];
        self::assertStringContainsString('role="img"', $html);
        self::assertStringContainsString('aria-label="Receipt &lt;script&gt; &amp; &quot;tax&quot;"', $html);
        self::assertStringNotContainsString('<script>', $html);
        $decorative = PageRenderer::render(self::doc(['label' => $label]))['html'];
        self::assertStringNotContainsString('aria-label', $decorative);
    }

    public function test_invalid_icon_props_fail_document_validation_and_are_logged_reducer_noops(): void
    {
        $bad = [
            ['name' => '__proto__'], ['name' => 'constructor'], ['name' => 'toString'], ['name' => 'not-an-icon'],
            ['name' => '<svg onload="bad()">'], ['name' => ['$bind' => 'icon']],
            ['decorative' => 'true'], ['decorative' => null], ['decorative' => false, 'label' => '  '],
            ['label' => 3], ['label' => ['$bind' => 'label']], ['label' => str_repeat('é', 513)],
            ['svg' => '<svg/>'], ['path' => 'M0 0'], ['onClick' => 'bad()'],
        ];
        foreach ($bad as $props) {
            self::assertNotSame([], PageDoc::validate(self::doc($props)), json_encode($props));
            $before = F::doc();
            $invalid = [];
            $out = Reducer::reduce($before, ['t' => 'insert_node', 'node' => self::icon($props)], ['onInvalid' => static function ($_, $reason) use (&$invalid): void {
                $invalid[] = $reason;
            }]);
            self::assertSame($before, $out);
            self::assertSame(['invalid icon props'], $invalid);
            $before = self::doc();
            $invalid = [];
            $out = Reducer::reduce($before, ['t' => 'set_node_props', 'id' => 'icon', 'patch' => $props], ['onInvalid' => static function ($_, $reason) use (&$invalid): void {
                $invalid[] = $reason;
            }]);
            self::assertSame($before, $out);
            self::assertSame(['invalid icon props'], $invalid);
            $html = PageRenderer::render(self::doc($props))['html'];
            self::assertStringNotContainsString('<svg', $html);
            self::assertStringNotContainsString('role="img"', $html);
        }
    }

    public function test_icons_are_leaves_and_valid_changes_undo_through_the_same_spine(): void
    {
        $doc = self::doc();
        $op = ['t' => 'set_node_props', 'id' => 'icon', 'patch' => ['name' => 'calculator', 'decorative' => false, 'label' => 'Tax estimate']];
        $inverse = Reducer::invert($doc, $op);
        $changed = Reducer::reduce($doc, $op);
        self::assertSame('calculator', $changed['nodes']['icon']['props']['name']);
        $undone = Reducer::reduceAll($changed, $inverse);
        self::assertSame($doc['nodes'], $undone['nodes']);
        foreach ([['t' => 'insert_node', 'node' => F::node('child', 'icon')], ['t' => 'move_node', 'id' => 'child', 'parent' => 'icon', 'order' => 'V']] as $op) {
            $before = $doc;
            $before['nodes']['child'] = F::node('child');
            if ($op['t'] === 'insert_node') {
                unset($before['nodes']['child']);
            }
            self::assertSame($before, Reducer::reduce($before, $op));
        }
        $doc['nodes']['child'] = F::node('child', 'icon');
        self::assertNotSame([], PageDoc::validate($doc));
    }

    public function test_wire_replay_undo_restores_an_absent_optional_label(): void
    {
        $doc = self::doc();
        unset($doc['nodes']['icon']['props']['label']);
        $op = ['t' => 'set_node_props', 'id' => 'icon', 'patch' => ['decorative' => false, 'label' => 'Receipt']];
        $inverse = json_decode(json_encode(Reducer::invert($doc, $op)), true);
        self::assertSame($doc['nodes'], Reducer::reduceAll(Reducer::reduce($doc, $op), $inverse)['nodes']);
    }

    public function test_required_icon_props_cannot_be_omitted(): void
    {
        foreach (['name', 'decorative'] as $key) {
            $node = self::icon();
            unset($node['props'][$key]);
            self::assertNotSame([], PageOp::validate(['t' => 'insert_node', 'node' => $node]));
            self::assertSame(F::doc(), Reducer::reduce(F::doc(), ['t' => 'insert_node', 'node' => $node]));
        }
    }
}
