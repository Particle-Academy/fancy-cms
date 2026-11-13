<?php

declare(strict_types=1);

namespace FancyCms\Render;

use FancyCms\Document\Ordering;
use stdClass;

/**
 * The infographic node kit (chart, kpi, table, leaderboard): the PHP twin of
 * fancy-cms-ui's render/infographics.ts and react/infographics.tsx.
 *
 * The page carries the shape; the host supplies the numbers through the data
 * context, resolved by the same `$bind` mechanism as every other prop. Every
 * method here takes props that are already resolved. Missing or malformed data
 * renders the empty state and never throws. Output is byte-identical to React's
 * static markup (tests/fixtures/infographics.json is shared by both suites).
 *
 * @internal
 */
final class Infographics
{
    public const TYPES = ['chart', 'kpi', 'table', 'leaderboard'];

    public const EMPTY_TEXT = 'No data available';

    private const KINDS = ['column', 'bar', 'line', 'pie', 'funnel'];

    private const SIZES = ['sm', 'md', 'lg'];

    private const TOTALS = ['sum', 'avg', 'min', 'max', 'count'];

    /**
     * Emitted after the base stylesheet, only by documents that place one of
     * these nodes. Light, dark (`.dark` / `[data-theme="dark"]`) and print,
     * coloured with theme tokens. Mirrors INFOGRAPHIC_CSS in fancy-cms-ui.
     */
    public const CSS = <<<'CSS'
/* fancy-cms infographics */

:where([data-cms-kpi], [data-cms-table], [data-cms-leaderboard], figure[data-cms-chart]) {
  --cms-ig-down: var(--cms-color-negative, #b91c1c);
  --cms-ig-muted: color-mix(in srgb, currentColor 70%, transparent);
  --cms-ig-rule: color-mix(in srgb, currentColor 20%, transparent);
  --cms-ig-up: var(--cms-color-positive, #15803d);
}

:where(figure[data-cms-chart]) {
  --cms-chart-1: var(--cms-color-primary, #7c3aed);
  --cms-chart-2: var(--cms-chart-teal, #0f766e);
  --cms-chart-3: var(--cms-chart-orange, #c2410c);
  --cms-chart-4: var(--cms-chart-blue, #1d4ed8);
  --cms-chart-5: var(--cms-chart-pink, #be185d);
  --cms-chart-6: var(--cms-chart-lime, #4d7c0f);
  margin: 0;
}

:where(.dark, [data-theme="dark"]) :where([data-cms-kpi], [data-cms-table], [data-cms-leaderboard], figure[data-cms-chart]) {
  --cms-ig-down: var(--cms-color-negative-dark, #f87171);
  --cms-ig-up: var(--cms-color-positive-dark, #4ade80);
}

:where(.dark, [data-theme="dark"]) :where(figure[data-cms-chart]) {
  --cms-chart-1: var(--cms-color-primary-dark, #a78bfa);
  --cms-chart-2: var(--cms-chart-teal-dark, #2dd4bf);
  --cms-chart-3: var(--cms-chart-orange-dark, #fb923c);
  --cms-chart-4: var(--cms-chart-blue-dark, #60a5fa);
  --cms-chart-5: var(--cms-chart-pink-dark, #f472b6);
  --cms-chart-6: var(--cms-chart-lime-dark, #a3e635);
}

:where([data-cms-sr]) {
  border: 0;
  clip-path: inset(50%);
  height: 1px;
  margin: -1px;
  overflow: hidden;
  padding: 0;
  position: absolute;
  white-space: nowrap;
  width: 1px;
}

:where([data-cms-empty]) {
  border: 1px dashed var(--cms-ig-rule);
  border-radius: 8px;
  color: var(--cms-ig-muted);
  font-size: 0.875em;
  margin: 0;
  padding: 16px;
  text-align: center;
}

:where([data-cms-kpi]) {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

:where([data-cms-kpi] > p) {
  margin: 0;
}

:where([data-cms-kpi-label]) {
  color: var(--cms-ig-muted);
  font-size: 0.875em;
}

:where([data-cms-kpi-value]) {
  font-size: 2em;
  font-variant-numeric: tabular-nums;
  font-weight: 700;
  line-height: 1.1;
  overflow-wrap: break-word;
}

:where([data-cms-kpi-delta]) {
  align-items: center;
  display: inline-flex;
  font-size: 0.875em;
  font-weight: 600;
  gap: 6px;
}

:where([data-cms-kpi-delta])::before {
  border-left: 5px solid transparent;
  border-right: 5px solid transparent;
  content: "";
}

:where([data-cms-kpi-trend="up"])::before {
  border-bottom: 7px solid currentColor;
}

:where([data-cms-kpi-trend="down"])::before {
  border-top: 7px solid currentColor;
}

:where([data-cms-kpi-trend="flat"])::before {
  border: 0;
  border-top: 2px solid currentColor;
  width: 8px;
}

:where([data-cms-kpi-tone="positive"]) {
  color: var(--cms-ig-up);
}

:where([data-cms-kpi-tone="negative"]) {
  color: var(--cms-ig-down);
}

:where([data-cms-table]) {
  max-width: 100%;
  overflow-x: auto;
}

:where([data-cms-table]:focus-visible) {
  outline: 2px solid var(--cms-color-primary, #7c3aed);
  outline-offset: 2px;
}

:where([data-cms-table] table, figure[data-cms-chart] table) {
  border-collapse: collapse;
  font-variant-numeric: tabular-nums;
  width: 100%;
}

:where(figure[data-cms-chart] table[data-cms-sr]) {
  width: 1px;
}

:where([data-cms-table] caption) {
  caption-side: top;
  color: var(--cms-ig-muted);
  font-size: 0.875em;
  padding-bottom: 8px;
  text-align: left;
}

:where([data-cms-table] th, [data-cms-table] td, figure[data-cms-chart] th, figure[data-cms-chart] td) {
  border-bottom: 1px solid var(--cms-ig-rule);
  padding: 8px 12px;
  text-align: left;
}

:where([data-cms-table] thead th, figure[data-cms-chart] thead th) {
  font-weight: 600;
}

:where([data-cms-table] tfoot td) {
  border-bottom: 0;
  border-top: 2px solid var(--cms-ig-rule);
  font-weight: 600;
}

:where(figure[data-cms-chart] td, [data-cms-align="end"]) {
  text-align: right;
}

:where([data-cms-align="center"]) {
  text-align: center;
}

:where([data-cms-leaderboard] ol) {
  list-style: none;
  margin: 0;
  padding: 0;
}

:where([data-cms-leaderboard] li) {
  align-items: baseline;
  border-bottom: 1px solid var(--cms-ig-rule);
  display: flex;
  gap: 12px;
  padding: 8px 0;
}

:where([data-cms-lb="rank"]) {
  color: var(--cms-ig-muted);
  font-variant-numeric: tabular-nums;
  min-width: 2ch;
  text-align: right;
}

:where([data-cms-rank="1"] [data-cms-lb="rank"]) {
  font-weight: 700;
}

:where([data-cms-lb="label"]) {
  flex: 1;
  overflow-wrap: break-word;
}

:where([data-cms-lb="value"]) {
  font-variant-numeric: tabular-nums;
  font-weight: 600;
}

:where([data-cms-kpi-value], [data-cms-lb="label"])[data-cms-wrap="anywhere"] {
  overflow-wrap: anywhere;
}

:where(figure[data-cms-chart] figcaption) {
  font-weight: 600;
  margin-bottom: 8px;
}

:where([data-cms-chart-canvas]) {
  height: 320px;
  width: 100%;
}

:where([data-cms-chart-size="sm"] [data-cms-chart-canvas]) {
  height: 220px;
}

:where([data-cms-chart-size="lg"] [data-cms-chart-canvas]) {
  height: 420px;
}

@media print {
  :where([data-cms-chart-canvas]) {
    display: none;
  }

  :where([data-cms-chart-data][data-cms-sr]) {
    clip-path: none;
    height: auto;
    margin: 0;
    overflow: visible;
    position: static;
    white-space: normal;
    width: 100%;
  }

  :where([data-cms-table]) {
    overflow: visible;
  }

  :where([data-cms-kpi], [data-cms-table], [data-cms-leaderboard], figure[data-cms-chart]) {
    break-inside: avoid;
  }
}
CSS;

    public static function isType(mixed $type): bool
    {
        return is_string($type) && in_array($type, self::TYPES, true);
    }

    /**
     * The element a node renders as. `$text` resolves and stringifies a raw prop.
     *
     * @return array{0:string,1:array<string,string>}
     */
    public static function element(string $type, array $props, callable $text): array
    {
        $caption = $type === 'table' ? (string) $text($props['caption'] ?? null) : '';

        return match ($type) {
            'chart' => ['figure', ['data-cms-chart' => self::oneOf($text($props['kind'] ?? null), self::KINDS, 'column'), 'data-cms-chart-size' => self::oneOf($text($props['size'] ?? null), self::SIZES, 'md')]],
            'kpi' => ['div', ['data-cms-kpi' => '']],
            'table' => ['div', ['data-cms-table' => '', 'role' => 'region', 'tabindex' => '0', 'aria-label' => $caption !== '' ? $caption : 'Table']],
            default => ['div', ['data-cms-leaderboard' => '']],
        };
    }

    /** The inner HTML of a node, from its resolved props. */
    public static function inner(string $type, array $props): string
    {
        return match ($type) {
            'chart' => self::chart($props),
            'kpi' => self::kpi($props),
            'table' => self::table($props),
            default => self::leaderboard($props),
        };
    }

    // ── chart ─────────────────────────────────────────────────────────────────

    private static function chart(array $props): string
    {
        $kind = self::oneOf($props['kind'] ?? null, self::KINDS, 'column');
        $title = self::text($props['title'] ?? null);
        $html = $title !== '' ? '<figcaption>'.self::escape($title).'</figcaption>' : '';
        $categories = self::categories($props['categories'] ?? null);
        $series = self::series($props['series'] ?? null);
        if ($categories === null || $series === null) {
            return $html.self::empty($props);
        }
        if ($kind === 'pie' || $kind === 'funnel') {
            $series = array_slice($series, 0, 1);
        }
        $spec = NumberFormat::spec($props['format'] ?? null, $props['currency'] ?? null, $props['decimals'] ?? null);
        $label = self::text($props['categoryLabel'] ?? null);
        $html .= '<table data-cms-chart-data=""><thead><tr><th scope="col">'.self::escape($label !== '' ? $label : 'Category').'</th>';
        foreach ($series as $s) {
            $html .= '<th scope="col">'.self::escape($s['name']).'</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($categories as $i => $category) {
            $html .= '<tr><th scope="row">'.self::escape($category).'</th>';
            foreach ($series as $s) {
                $v = $s['values'][$i] ?? null;
                $html .= '<td>'.($v === null ? '' : self::escape(NumberFormat::format($v, $spec))).'</td>';
            }
            $html .= '</tr>';
        }

        return $html.'</tbody></table>';
    }

    /** @return list<string>|null */
    private static function categories(mixed $raw): ?array
    {
        if (! self::isList($raw) || $raw === []) {
            return null;
        }
        $out = [];
        foreach ($raw as $c) {
            if (is_string($c)) {
                $out[] = $c;
            } elseif ((is_int($c) || is_float($c)) && is_finite((float) $c)) {
                $out[] = JsValue::string($c);
            } else {
                return null;
            }
        }

        return $out;
    }

    /** @return list<array{name:string,values:list<?float>}>|null */
    private static function series(mixed $raw): ?array
    {
        if (! self::isList($raw) || $raw === []) {
            return null;
        }
        $numbers = array_map(NumberFormat::toNumber(...), $raw);
        if (! in_array(null, $numbers, true)) {
            return [['name' => 'Value', 'values' => $numbers]];
        }
        $out = [];
        foreach ($raw as $i => $entry) {
            if (! self::isRecord($entry)) {
                return null;
            }
            $data = self::own($entry, 'data');
            if (! self::isList($data)) {
                return null;
            }
            $name = self::own($entry, 'name');
            $out[] = ['name' => is_string($name) && $name !== '' ? $name : 'Series '.($i + 1), 'values' => array_map(NumberFormat::toNumber(...), $data)];
        }

        return $out;
    }

    // ── kpi ───────────────────────────────────────────────────────────────────

    private static function kpi(array $props): string
    {
        $label = self::text($props['label'] ?? null);
        $html = $label !== '' ? '<p data-cms-kpi-label="">'.self::escape($label).'</p>' : '';
        $n = NumberFormat::toNumber($props['value'] ?? null);
        if ($n === null) {
            return $html.self::empty($props);
        }
        $value = NumberFormat::format($n, NumberFormat::spec($props['format'] ?? null, $props['currency'] ?? null, $props['decimals'] ?? null));
        $html .= '<p data-cms-kpi-value=""'.self::wrap($value).'>'.self::escape($value).'</p>';
        $d = NumberFormat::toNumber($props['delta'] ?? null);
        if ($d === null) {
            return $html;
        }
        $spec = NumberFormat::spec($props['deltaFormat'] ?? null, $props['currency'] ?? null, $props['deltaDecimals'] ?? null, 'percent');
        $trend = self::oneOf($props['trend'] ?? null, ['up', 'down', 'flat'], $d > 0 ? 'up' : ($d < 0 ? 'down' : 'flat'));
        $good = self::oneOf($props['goodDirection'] ?? null, ['up', 'down'], 'up');
        $tone = $trend === 'flat' ? 'neutral' : ($trend === $good ? 'positive' : 'negative');
        $word = ['up' => 'Up', 'down' => 'Down', 'flat' => 'No change'][$trend];

        return $html.'<p data-cms-kpi-delta="" data-cms-kpi-trend="'.$trend.'" data-cms-kpi-tone="'.$tone.'"><span data-cms-sr="">'.$word.'</span>'.self::escape(' '.NumberFormat::signed($d, $spec)).'</p>';
    }

    // ── table ─────────────────────────────────────────────────────────────────

    private static function table(array $props): string
    {
        $columns = self::columns($props['columns'] ?? null);
        $rows = self::isList($props['rows'] ?? null) ? array_values(array_filter($props['rows'], self::isRecord(...))) : [];
        if ($columns === [] || $rows === []) {
            return self::empty($props);
        }
        $sort = null;
        $s = $props['sort'] ?? null;
        $key = self::isRecord($s) ? self::own($s, 'key') : null;
        if (is_string($key) && in_array($key, array_column($columns, 'key'), true)) {
            $sort = ['key' => $key, 'dir' => self::own($s, 'dir') === 'desc' ? 'desc' : 'asc'];
            $decorated = [];
            foreach ($rows as $index => $row) {
                $decorated[] = ['row' => $row, 'index' => $index, 'v' => self::sortValue(self::own($row, $key))];
            }
            usort($decorated, static function (array $a, array $b) use ($sort): int {
                if ($a['v'][0] !== $b['v'][0]) {
                    return $a['v'][0] <=> $b['v'][0];
                }
                $c = match ($a['v'][0]) {
                    0 => $a['v'][1] <=> $b['v'][1],
                    1 => Ordering::compare($a['v'][1], $b['v'][1]) <=> 0,
                    default => 0,
                };
                if ($sort['dir'] === 'desc') {
                    $c = -$c;
                }

                return $c !== 0 ? $c : $a['index'] <=> $b['index'];
            });
            $rows = array_column($decorated, 'row');
        }

        $align = static fn (array $col): string => $col['align'] === 'start' ? '' : ' data-cms-align="'.$col['align'].'"';
        $caption = self::text($props['caption'] ?? null);
        $html = '<table>'.($caption !== '' ? '<caption>'.self::escape($caption).'</caption>' : '').'<thead><tr>';
        foreach ($columns as $col) {
            $ariaSort = $sort !== null && $sort['key'] === $col['key'] ? ' aria-sort="'.($sort['dir'] === 'desc' ? 'descending' : 'ascending').'"' : '';
            $html .= '<th scope="col"'.$ariaSort.$align($col).'>'.self::escape($col['label']).'</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($columns as $col) {
                $v = self::own($row, $col['key']);
                $n = $col['spec'] !== null ? NumberFormat::toNumber($v) : null;
                $html .= '<td'.$align($col).'>'.self::escape($n !== null ? NumberFormat::format($n, $col['spec']) : self::cellText($v)).'</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody>';
        if (($props['totals'] ?? null) === true) {
            $label = self::text($props['totalsLabel'] ?? null);
            $html .= '<tfoot><tr>';
            foreach ($columns as $i => $col) {
                $html .= '<td'.$align($col).'>'.self::escape(self::total($col, $rows, $i, $label !== '' ? $label : 'Total')).'</td>';
            }
            $html .= '</tr></tfoot>';
        }

        return $html.'</table>';
    }

    private static function total(array $col, array $rows, int $i, string $label): string
    {
        if ($col['total'] === null) {
            return $i === 0 ? $label : '';
        }
        $number = NumberFormat::spec(null, null, null);
        if ($col['total'] === 'count') {
            return NumberFormat::format((float) count($rows), $number);
        }
        $nums = [];
        foreach ($rows as $row) {
            $n = NumberFormat::toNumber(self::own($row, $col['key']));
            if ($n !== null) {
                $nums[] = $n;
            }
        }
        if ($nums === []) {
            return '';
        }
        if ($col['total'] === 'min') {
            $value = min($nums);
        } elseif ($col['total'] === 'max') {
            $value = max($nums);
        } else {
            $sum = 0.0;
            foreach ($nums as $n) {
                $sum += $n;
            }
            $value = $col['total'] === 'avg' ? $sum / count($nums) : $sum;
        }

        return NumberFormat::format($value, $col['spec'] ?? $number);
    }

    /** @return list<array{key:string,label:string,spec:?array,align:string,total:?string}> */
    private static function columns(mixed $raw): array
    {
        if (! self::isList($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $c) {
            if (! self::isRecord($c)) {
                continue;
            }
            $key = self::own($c, 'key');
            if (! is_string($key) || $key === '') {
                continue;
            }
            $label = self::own($c, 'label');
            $format = self::own($c, 'format');
            $spec = is_string($format) && in_array($format, ['number', 'currency', 'percent'], true) ? NumberFormat::spec($format, self::own($c, 'currency'), self::own($c, 'decimals')) : null;
            $total = self::own($c, 'total');
            $out[] = [
                'key' => $key,
                'label' => is_string($label) && $label !== '' ? $label : $key,
                'spec' => $spec,
                'align' => self::oneOf(self::own($c, 'align'), ['start', 'center', 'end'], $spec !== null ? 'end' : 'start'),
                'total' => is_string($total) && in_array($total, self::TOTALS, true) ? $total : null,
            ];
        }

        return $out;
    }

    /** @return array{0:int,1:float|string} rank (numbers, strings, anything else) and the value */
    private static function sortValue(mixed $v): array
    {
        $n = NumberFormat::toNumber($v);
        if ($n !== null) {
            return [0, $n];
        }

        return is_string($v) ? [1, $v] : [2, ''];
    }

    // ── leaderboard ───────────────────────────────────────────────────────────

    private static function leaderboard(array $props): string
    {
        $labelKey = self::text($props['labelKey'] ?? null);
        $labelKey = $labelKey !== '' ? $labelKey : 'name';
        $valueKey = self::text($props['valueKey'] ?? null);
        $valueKey = $valueKey !== '' ? $valueKey : 'value';
        $asc = ($props['order'] ?? null) === 'asc';
        $limit = $props['limit'] ?? null;
        $limit = (is_int($limit) || (is_float($limit) && floor($limit) === $limit)) && $limit >= 1 && $limit <= 100 ? (int) $limit : 10;
        $spec = NumberFormat::spec($props['format'] ?? null, $props['currency'] ?? null, $props['decimals'] ?? null);
        $ranked = [];
        foreach (self::isList($props['rows'] ?? null) ? $props['rows'] : [] as $index => $row) {
            if (! self::isRecord($row)) {
                continue;
            }
            $n = NumberFormat::toNumber(self::own($row, $valueKey));
            if ($n !== null) {
                $ranked[] = ['label' => self::cellText(self::own($row, $labelKey)), 'n' => $n, 'index' => $index];
            }
        }
        if ($ranked === []) {
            return self::empty($props);
        }
        usort($ranked, static function (array $a, array $b) use ($asc): int {
            $c = $a['n'] <=> $b['n'];

            return ($asc ? $c : -$c) ?: $a['index'] <=> $b['index'];
        });
        $html = '<ol>';
        $ranks = [];
        foreach ($ranked as $i => $r) {
            $ranks[$i] = $i > 0 && $r['n'] === $ranked[$i - 1]['n'] ? $ranks[$i - 1] : $i + 1;
            if ($i >= $limit) {
                continue;
            }
            $html .= '<li data-cms-rank="'.$ranks[$i].'"><span data-cms-lb="rank">'.$ranks[$i].'</span><span data-cms-lb="label"'.self::wrap($r['label']).'>'.self::escape($r['label']).'</span><span data-cms-lb="value">'.self::escape(NumberFormat::format($r['n'], $spec)).'</span></li>';
        }

        return $html.'</ol>';
    }

    // ── shared ────────────────────────────────────────────────────────────────

    private static function empty(array $props): string
    {
        $text = self::text($props['emptyText'] ?? null);

        return '<p data-cms-empty="">'.self::escape($text !== '' ? $text : self::EMPTY_TEXT).'</p>';
    }

    /** JavaScript's String(v) for a resolved prop, '' for null. */
    private static function text(mixed $v): string
    {
        return JsValue::text($v);
    }

    /** A data cell: strings, finite numbers and booleans; objects and lists render nothing. */
    private static function cellText(mixed $v): string
    {
        if (is_string($v)) {
            return $v;
        }
        if (is_int($v) || is_float($v)) {
            return is_finite((float) $v) ? JsValue::string($v) : '';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }

        return '';
    }

    private static function oneOf(mixed $v, array $allowed, string $fallback): string
    {
        return is_string($v) && in_array($v, $allowed, true) ? $v : $fallback;
    }

    private static function isList(mixed $v): bool
    {
        return is_array($v) && array_is_list($v);
    }

    private static function isRecord(mixed $v): bool
    {
        return $v instanceof stdClass || (is_array($v) && ! array_is_list($v));
    }

    private static function own(mixed $record, string $key): mixed
    {
        $array = $record instanceof stdClass ? (array) $record : $record;

        return is_array($array) && array_key_exists($key, $array) ? $array[$key] : null;
    }

    /** ` data-cms-wrap="anywhere"` when the text holds a long token (TextWrap), else nothing. */
    private static function wrap(string $text): string
    {
        return TextWrap::hasLongToken($text) ? ' data-cms-wrap="anywhere"' : '';
    }

    private static function escape(string $value): string
    {
        return str_replace('&#039;', '&#x27;', htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }
}
