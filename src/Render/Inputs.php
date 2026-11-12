<?php

declare(strict_types=1);

namespace FancyCms\Render;

use FancyCms\Data\PageInputs;

/**
 * The `input` node's markup: native, labelled form controls, byte-identical to
 * fancy-cms-ui's server render (react/inputs.tsx NativeControls). In a browser
 * the React renderer swaps in react-fancy's controls on the same element ids.
 *
 * Values are normalised by PageInputs, so the controls always show what the
 * data was fetched for.
 */
final class Inputs
{
    /** Byte-identical to fancy-cms-ui's INPUT_CSS; emitted only on pages that place an input. */
    public const CSS = <<<'CSS'
/* fancy-cms inputs */

:where([data-cms-input]) {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

:where([data-cms-input] fieldset) {
  border: 0;
  display: flex;
  flex-wrap: wrap;
  gap: 8px 16px;
  margin: 0;
  min-width: 0;
  padding: 0;
}

:where([data-cms-input] legend) {
  font-weight: 600;
  margin-bottom: 6px;
  padding: 0;
  width: 100%;
}

:where([data-cms-input] > label) {
  font-weight: 600;
}

:where([data-cms-input] select, [data-cms-input] input[type="date"]) {
  background: transparent;
  border: 1px solid color-mix(in srgb, currentColor 40%, transparent);
  border-radius: 6px;
  color: inherit;
  font: inherit;
  min-height: 44px;
  padding: 8px 10px;
}

:where([data-cms-input] fieldset label) {
  align-items: center;
  display: inline-flex;
  gap: 6px;
  min-height: 44px;
}
CSS;

    /** The element an input renders as. `$text` resolves and stringifies a raw prop. */
    public static function element(array $props, callable $text): array
    {
        return ['div', ['data-cms-input' => PageInputs::kind($text($props['kind'] ?? null))]];
    }

    /**
     * The inner HTML of an input node.
     *
     * @param  array<string,mixed>  $props  resolved props
     * @param  mixed  $raw  the value the page was asked for (normalised here)
     * @param  bool  $declared  whether this node owns its name (PageInputs::declared)
     */
    public static function inner(array $props, mixed $raw, bool $declared): string
    {
        $kind = PageInputs::kind($props['kind'] ?? null);
        $name = is_string($props['name'] ?? null) ? $props['name'] : '';
        $options = PageInputs::options($kind, $props['options'] ?? null);
        $value = PageInputs::normalize($kind, $options, $raw, $props['default'] ?? null);
        $valid = $declared && preg_match(PageInputs::NAME_PATTERN, $name) === 1 && ($kind === 'daterange' || ($options !== null && $options !== []));
        if (! $valid) {
            $empty = JsValue::text($props['emptyText'] ?? null);

            return '<p data-cms-empty="">'.self::escape($empty !== '' ? $empty : PageInputs::EMPTY_TEXT).'</p>';
        }
        $label = JsValue::text($props['label'] ?? null);
        $label = self::escape($label !== '' ? $label : $name);
        $id = 'cms-input-'.$name;
        $options ??= [];

        if ($kind === 'multiselect') {
            $html = '<fieldset><legend>'.$label.'</legend>';
            foreach ($options as $o) {
                $html .= '<label><input type="checkbox" name="'.$name.'[]"'.(in_array($o['value'], $value, true) ? ' checked=""' : '').' value="'.self::escape($o['value']).'"/>'.self::escape($o['label']).'</label>';
            }

            return $html.'</fieldset>';
        }
        if ($kind === 'daterange') {
            $from = JsValue::text($props['fromLabel'] ?? null);
            $to = JsValue::text($props['toLabel'] ?? null);

            return '<fieldset><legend>'.$label.'</legend>'
                .'<label for="'.$id.'-from">'.self::escape($from !== '' ? $from : 'From').'</label>'
                .'<input type="date" id="'.$id.'-from" name="'.$name.'[from]" value="'.self::escape($value['from']).'"/>'
                .'<label for="'.$id.'-to">'.self::escape($to !== '' ? $to : 'To').'</label>'
                .'<input type="date" id="'.$id.'-to" name="'.$name.'[to]" value="'.self::escape($value['to']).'"/>'
                .'</fieldset>';
        }
        $html = '<label for="'.$id.'">'.$label.'</label><select id="'.$id.'" name="'.$name.'">';
        foreach ($options as $o) {
            $html .= '<option value="'.self::escape($o['value']).'"'.($o['value'] === $value ? ' selected=""' : '').'>'.self::escape($o['label']).'</option>';
        }

        return $html.'</select>';
    }

    private static function escape(string $value): string
    {
        return str_replace('&#039;', '&#x27;', htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }
}
