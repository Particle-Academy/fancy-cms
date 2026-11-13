<?php

declare(strict_types=1);

namespace FancyCms\Tests;

use FancyCms\Render\EchartOption;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The echart node's option validator. tests/fixtures/echart-options.json is
 * byte-identical in @particle-academy/fancy-cms-ui, whose validateEchartOption
 * must return the same paths.
 */
final class EchartOptionTest extends TestCase
{
    public static function cases(): iterable
    {
        $fixture = json_decode((string) file_get_contents(__DIR__.'/fixtures/echart-options.json'), false, 512, JSON_THROW_ON_ERROR);
        foreach ($fixture->cases as $case) {
            yield $case->name => [$case->option, $case->errors];
        }
    }

    /** @param list<string> $errors */
    #[DataProvider('cases')]
    public function test_the_shared_case(mixed $option, array $errors): void
    {
        self::assertSame($errors, EchartOption::errors($option));
    }

    public function test_decoded_as_arrays_it_answers_the_same(): void
    {
        $option = json_decode('{"series":[{"type":"bar","label":{"formatter":"x => x"}}]}', true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['$.series[0].label.formatter'], EchartOption::errors($option));
        self::assertSame(['$'], EchartOption::errors(json_decode('[1,2]', true)));
    }

    public function test_a_binding_is_refused_once_the_option_is_resolved(): void
    {
        $option = json_decode('{"series":[{"type":"bar","data":{"$bind":"a"}}]}', false, 512, JSON_THROW_ON_ERROR);
        self::assertSame([], EchartOption::errors($option));
        self::assertSame(['$.series[0].data'], EchartOption::errors($option, bindings: false));
    }

    public function test_the_ts_copy_of_the_fixture_is_identical_when_the_sibling_is_checked_out(): void
    {
        $theirs = dirname(__DIR__, 2).'/fancy-cms-ui/tests/fixtures/echart-options.json';
        if (! is_file($theirs)) {
            self::markTestSkipped('fancy-cms-ui is not checked out beside this package.');
        }
        self::assertSame(file_get_contents($theirs), file_get_contents(__DIR__.'/fixtures/echart-options.json'));
    }
}
