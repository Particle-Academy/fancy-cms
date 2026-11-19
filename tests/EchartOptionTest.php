<?php

declare(strict_types=1);

namespace FancyCms\Tests\EchartOptionTest;

use FancyCms\Render\EchartOption;

/**
 * The echart node's option validator. tests/fixtures/echart-options.json is
 * byte-identical in @particle-academy/fancy-cms-ui, whose validateEchartOption
 * must return the same paths.
 */

function cases(): iterable {
    $fixture = json_decode((string) file_get_contents(__DIR__.'/fixtures/echart-options.json'), false, 512, JSON_THROW_ON_ERROR);
    foreach ($fixture->cases as $case) {
        yield $case->name => [$case->option, $case->errors];
    }
}

/** @param list<string> $errors */
test('the shared case', function (mixed $option, array $errors) {
    $this->assertSame($errors, EchartOption::errors($option));
})->with(fn (): iterable => cases());

test('decoded as arrays it answers the same', function () {
    $option = json_decode('{"series":[{"type":"bar","label":{"formatter":"x => x"}}]}', true, 512, JSON_THROW_ON_ERROR);
    $this->assertSame(['$.series[0].label.formatter'], EchartOption::errors($option));
    $this->assertSame(['$'], EchartOption::errors(json_decode('[1,2]', true)));
});

test('a binding is refused once the option is resolved', function () {
    $option = json_decode('{"series":[{"type":"bar","data":{"$bind":"a"}}]}', false, 512, JSON_THROW_ON_ERROR);
    $this->assertSame([], EchartOption::errors($option));
    $this->assertSame(['$.series[0].data'], EchartOption::errors($option, bindings: false));
});

test('the ts copy of the fixture is identical when the sibling is checked out', function () {
    $theirs = dirname(__DIR__, 2).'/fancy-cms-ui/tests/fixtures/echart-options.json';
    if (! is_file($theirs)) {
        $this->markTestSkipped('fancy-cms-ui is not checked out beside this package.');
    }
    $this->assertSame(file_get_contents($theirs), file_get_contents(__DIR__.'/fixtures/echart-options.json'));
});
