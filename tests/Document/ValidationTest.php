<?php

declare(strict_types=1);

namespace FancyCms\Tests\Document;

use FancyCms\Document\PageDoc;
use FancyCms\Document\PageJson;
use FancyCms\Document\PageOp;
use FancyCms\Exceptions\SchemaException;
use FancyCms\Tests\Support\PageFixtures as F;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValidationTest extends TestCase
{
    public function test_all_canonical_ops_validate_and_vocabulary_is_closed(): void
    {
        self::assertSame(array_keys(F::ops()), PageOp::TYPES);
        foreach (F::ops() as $op) {
            self::assertSame([], PageOp::validate($op));
        }
        self::assertSame([], PageDoc::validate(F::doc()));
    }

    public static function invalidOps(): iterable
    {
        yield 'unknown type' => [['t' => 'set_props'], '$.t'];
        yield 'missing id' => [['t' => 'remove_node'], '$.id'];
        yield 'bad parent' => [['t' => 'move_node', 'id' => 'a', 'parent' => 4, 'order' => 'V'], '$.parent'];
        yield 'bad patch' => [['t' => 'set_node_props', 'id' => 'a', 'patch' => 7], '$.patch'];
        yield 'bad breakpoint' => [['t' => 'set_style', 'id' => 'a', 'breakpoint' => 1, 'patch' => []], '$.breakpoint'];
        yield 'bad animation' => [['t' => 'set_animation', 'id' => 'a', 'anim' => ['id' => 'a', 'driver' => 'unknown', 'keyframes' => []]], '$.anim.driver'];
        yield 'bad action' => [['t' => 'set_action', 'id' => 'a', 'index' => 0, 'action' => ['on' => 'click', 'do' => ['kind' => 'unknown']]], '$.action.do.kind'];
        yield 'nonfinite index' => [['t' => 'set_action', 'id' => 'a', 'index' => INF, 'action' => null], '$.index'];
        yield 'nonjson patch' => [['t' => 'set_theme', 'patch' => ['x' => static fn () => 1]], '$.patch.x'];
        yield 'extra envelope key' => [['t' => 'remove_node', 'id' => 'a', 'extra' => 1], '$.extra'];
    }

    #[DataProvider('invalidOps')]
    public function test_bad_ops_report_the_exact_path(array $op, string $path): void
    {
        $errors = PageOp::validate($op);
        self::assertContains($path, array_column($errors, 'path'));
        try {
            PageOp::assert($op);
            self::fail('Expected schema refusal');
        } catch (SchemaException $e) {
            self::assertSame($errors, $e->getErrors());
            self::assertStringContainsString($errors[0]['path'], $e->getMessage());
        }
    }

    public function test_document_boundary_rejects_shape_unknown_types_orphans_and_cycles(): void
    {
        foreach (['unknown', 'parent', 'cycle', 'key', 'seq', 'style'] as $kind) {
            $doc = F::doc();
            match ($kind) {
                'unknown' => $doc['nodes']['a']['type'] = 'unregistered-addon',
                'parent' => $doc['nodes']['b']['parent'] = 'missing',
                'cycle' => $doc['nodes']['a']['parent'] = 'grandchild',
                'key' => $doc['nodes']['a']['id'] = 'wrong',
                'seq' => $doc['seq'] = -1,
                'style' => $doc['nodes']['a']['style'] = 'bad',
            };
            self::assertNotSame([], PageDoc::validate($doc), $kind);
        }
        $doc = F::doc();
        $doc['nodes']['a']['type'] = 'host-widget';
        self::assertSame([], PageDoc::validate($doc, [...PageDoc::NODE_TYPES, 'host-widget']));
    }

    public function test_safe_apply_validates_before_and_after_reducing(): void
    {
        $doc = F::doc();
        self::assertSame('Edited', PageDoc::apply($doc, F::ops()['set_meta'])['meta']['title']);
        $this->expectException(SchemaException::class);
        PageDoc::apply($doc, ['t' => 'insert_node', 'node' => F::node('orphan', 'missing')]);
    }

    public function test_deep_document_validation_is_iterative(): void
    {
        $doc = F::doc();
        $doc['nodes'] = [];
        for ($i = 0; $i < 2000; $i++) {
            $doc['nodes']['n'.$i] = F::node('n'.$i, $i === 0 ? null : 'n'.($i - 1));
        }
        self::assertSame([], PageDoc::validate($doc));
        $doc['nodes']['n0']['parent'] = 'n1999';
        self::assertNotSame([], PageDoc::validate($doc));
    }

    public function test_known_css_lengths_and_keywords_are_validated_before_storage(): void
    {
        foreach ([['padding' => ['value' => 1, 'unit' => 'px; color:red']], ['padding' => ['unit' => 'px']], ['align' => 'center; color:red'], ['direction' => 'row; color:red'], ['hidden' => 'false'], ['opacity' => 'red'], ['background' => []]] as $style) {
            self::assertNotSame([], PageOp::validate(['t' => 'set_style', 'id' => 'a', 'breakpoint' => 'base', 'patch' => $style]));
            $doc = F::doc();
            $doc['nodes']['a']['style']['base'] = $style;
            self::assertNotSame([], PageDoc::validate($doc));
        }
    }

    public function test_integral_json_numbers_are_valid_seq_values(): void
    {
        $doc = F::doc();
        $doc['seq'] = 1.0;
        self::assertSame([], PageDoc::validate($doc));
        $doc['seq'] = 1.5;
        self::assertNotSame([], PageDoc::validate($doc));
    }

    public function test_wire_record_errors_name_the_path_instead_of_guessing_array_tags(): void
    {
        foreach ([['doc', '{"nodes":[]}', '$.nodes'], ['op', '{"t":"set_theme","patch":[]}', '$.patch']] as [$kind, $json, $path]) {
            try {
                if ($kind === 'doc') {
                    PageJson::decodeDocument($json);
                } else {
                    PageJson::decodeOp($json);
                }
                self::fail('A JSON array is not a record.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($path, $e->getMessage());
            }
        }
    }

    public function test_json_encoding_never_invokes_an_object_callback(): void
    {
        $bomb = new class extends \stdClass implements \JsonSerializable
        {
            public bool $called = false;

            public function jsonSerialize(): mixed
            {
                $this->called = true;

                return 'unsafe callback';
            }
        };
        $doc = F::doc();
        $doc['theme']['bad'] = $bomb;
        self::assertNotSame([], PageDoc::validate($doc));
        try {
            PageJson::encodeDocument($doc);
            self::fail('Expected non-JSON object refusal');
        } catch (SchemaException $e) {
            self::assertContains('$.theme.bad', array_column($e->getErrors(), 'path'));
        } finally {
            self::assertFalse($bomb->called);
        }
    }
}
