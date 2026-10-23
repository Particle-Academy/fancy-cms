<?php

declare(strict_types=1);

/** First-party differential-test driver. Only stdin JSON, never commands/paths. */
require __DIR__.'/../vendor/autoload.php';

use FancyCms\Document\Ordering;
use FancyCms\Document\PageJson;
use FancyCms\Document\Reducer;
use FancyCms\Render\JsValue;

$input = json_decode(stream_get_contents(STDIN), false, 128, JSON_THROW_ON_ERROR);
$out = ['cases' => [], 'keys' => [], 'numbers' => []];
foreach ($input->cases as $case) {
    $initial = PageJson::decodeDocument(json_encode($case->doc, JSON_THROW_ON_ERROR));
    $ops = array_map(static fn ($op) => PageJson::decodeOp(json_encode($op, JSON_THROW_ON_ERROR)), $case->ops);
    $doc = $initial;
    $states = [];
    foreach ($ops as $op) {
        $copy = PageJson::encodeDocument($doc);
        $opCopy = PageJson::encodeOp($op);
        $inverse = Reducer::invert($doc, $op);
        $prior = $doc;
        $invalid = [];
        $doc = Reducer::reduce($doc, $op, ['onInvalid' => static function ($_, string $reason) use (&$invalid): void {
            $invalid[] = $reason;
        }]);
        if (PageJson::encodeDocument($prior) !== $copy || PageJson::encodeOp($op) !== $opCopy) {
            throw new RuntimeException('PHP mutated input');
        }
        $inverseJson = array_map(static fn ($op) => json_decode(PageJson::encodeOp($op), false, 128, JSON_THROW_ON_ERROR), $inverse);
        $wireInverse = array_map(static fn ($op) => PageJson::decodeOp(PageJson::encodeOp($op)), $inverse);
        $states[] = [
            'doc' => json_decode(PageJson::encodeDocument($doc), false, 128, JSON_THROW_ON_ERROR),
            'inverse' => $inverseJson,
            'undo' => json_decode(PageJson::encodeDocument(Reducer::reduceAll($doc, $wireInverse)), false, 128, JSON_THROW_ON_ERROR),
            'roots' => Reducer::rootIds($doc), 'invalid' => $invalid,
        ];
    }
    $out['cases'][] = ['name' => $case->name, 'states' => $states, 'all' => json_decode(PageJson::encodeDocument(Reducer::reduceAll($initial, $ops)), false, 128, JSON_THROW_ON_ERROR)];
}
foreach ($input->keys as [$a, $b]) {
    try {
        $out['keys'][] = ['value' => Ordering::keyBetween($a, $b)];
    } catch (InvalidArgumentException) {
        $out['keys'][] = ['error' => true];
    }
}
$out['numbers'] = array_map(static fn ($value) => JsValue::string($value), $input->numbers ?? []);
echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES, 128);
