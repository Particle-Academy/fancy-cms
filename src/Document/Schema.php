<?php

declare(strict_types=1);

namespace FancyCms\Document;

use stdClass;

/** @internal Shared hand-written checks; no execution, coercion or repair. */
final class Schema
{
    public array $errors = [];

    private int $values = 0;

    public function error(string $path, string $expected, mixed $got): void
    {
        if (count($this->errors) < 100) {
            $this->errors[] = ['path' => $path, 'expected' => $expected, 'got' => get_debug_type($got), 'hint' => 'Use the PageDoc/PageOp shape at this path.'];
        }
    }

    public function record(mixed $value, string $path): bool
    {
        // A PHP array at a schema-defined record position is a record, even
        // with numeric keys. PageJson checks the actual JSON object/list tag.
        if (is_array($value)) {
            return true;
        }
        $this->error($path, 'object record', $value);

        return false;
    }

    public function string(mixed $value, string $path, bool $nonempty = true): bool
    {
        if (is_string($value) && (! $nonempty || $value !== '') && strlen($value) <= 65536 && preg_match('//u', $value) === 1) {
            return true;
        }
        $this->error($path, $nonempty ? 'nonempty UTF-8 string (max 65536 bytes)' : 'UTF-8 string (max 65536 bytes)', $value);

        return false;
    }

    public function number(mixed $value, string $path): bool
    {
        if ((is_int($value) || is_float($value)) && is_finite((float) $value)) {
            return true;
        }
        $this->error($path, 'finite number', $value);

        return false;
    }

    public function enum(mixed $value, array $allowed, string $path): bool
    {
        if (in_array($value, $allowed, true)) {
            return true;
        }
        $this->error($path, implode('|', $allowed), $value);

        return false;
    }

    public function json(mixed $value, string $path = '$', int $depth = 0): void
    {
        if (++$this->values > 100000 || $depth > 64) {
            if ($this->values === 100001 || $depth > 64) {
                $this->error($path, 'JSON within 64 levels and 100000 values', $value);
            }

            return;
        }
        if (is_array($value) || (is_object($value) && get_class($value) === stdClass::class)) {
            foreach ($value as $key => $child) {
                $this->json($child, $path.'.'.$key, $depth + 1);
                if ($this->values > 100000 || count($this->errors) >= 100) {
                    break;
                }
            }
        } elseif (is_float($value) && ! is_finite($value)) {
            $this->error($path, 'finite JSON number', $value);
        } elseif (is_string($value) && preg_match('//u', $value) !== 1) {
            $this->error($path, 'UTF-8 string', $value);
        } elseif ($value !== null && ! is_scalar($value)) {
            $this->error($path, 'JSON value', $value);
        }
    }

    public function fields(array $record, array $required, array $allowed, string $path): void
    {
        foreach ($required as $key) {
            if (! array_key_exists($key, $record)) {
                $this->error($path.'.'.$key, 'required field', null);
            }
        }
        foreach ($record as $key => $value) {
            if (! in_array((string) $key, $allowed, true)) {
                $this->error($path.'.'.$key, 'known field', $value);
            }
        }
    }

    public function node(mixed $node, string $path, array $knownTypes): void
    {
        if (! $this->record($node, $path)) {
            return;
        }
        foreach (['id', 'type', 'order'] as $field) {
            $this->string($node[$field] ?? null, $path.'.'.$field);
        }
        if (is_string($node['type'] ?? null) && ! in_array($node['type'], $knownTypes, true)) {
            $this->error($path.'.type', 'registered node type', $node['type']);
        }
        if (! array_key_exists('parent', $node)) {
            $this->error($path.'.parent', 'string|null', null);
        } elseif ($node['parent'] !== null) {
            $this->string($node['parent'], $path.'.parent');
        }
        $this->record($node['props'] ?? null, $path.'.props');
        if (Decorative::propsError($node) !== null) {
            $this->error($path.'.props.decorative', 'literal boolean on frame/shape (or icon accessibility flag)', $node['props']['decorative'] ?? null);
        }
        if (($node['type'] ?? null) === 'icon' && ! Icons::validProps($node['props'] ?? null)) {
            $this->error($path.'.props', 'allowlisted icon name, decorative boolean and label when meaningful; no other props', $node['props'] ?? null);
        }
        if (array_key_exists('layout', $node)) {
            $this->enum($node['layout'], ['free', 'stack', 'grid'], $path.'.layout');
        }
        foreach (['style', 'constraints'] as $field) {
            if ($field === 'constraints' && ! array_key_exists($field, $node)) {
                continue;
            }
            $map = $node[$field] ?? null;
            if (! $this->record($map, $path.'.'.$field)) {
                continue;
            }
            if (! array_key_exists('base', $map)) {
                $this->error($path.'.'.$field.'.base', 'required record', null);
            }
            foreach ($map as $bp => $patch) {
                $at = $path.'.'.$field.'.'.$bp;
                if ($field === 'style') {
                    $this->style($patch, $at);
                } else {
                    $this->constraints($patch, $at);
                }
            }
        }
        foreach (['animations', 'actions'] as $field) {
            if (array_key_exists($field, $node)) {
                if (! is_array($node[$field]) || ! array_is_list($node[$field])) {
                    $this->error($path.'.'.$field, 'list', $node[$field]);
                } else {
                    foreach ($node[$field] as $i => $item) {
                        if ($field === 'animations') {
                            $this->animation($item, $path.'.'.$field.'.'.$i);
                        } else {
                            $this->action($item, $path.'.'.$field.'.'.$i);
                        }
                    }
                }
            }
        }
        if (array_key_exists('className', $node)) {
            $this->string($node['className'], $path.'.className', false);
        }
        if (array_key_exists('island', $node) && ! is_bool($node['island'])) {
            $this->error($path.'.island', 'boolean', $node['island']);
        }
        if (array_key_exists('repeat', $node) && $this->record($node['repeat'], $path.'.repeat')) {
            $this->binding($node['repeat']['each'] ?? null, $path.'.repeat.each');
            $this->string($node['repeat']['as'] ?? null, $path.'.repeat.as');
        }
    }

    public function style(mixed $style, string $path): void
    {
        if (! $this->record($style, $path)) {
            return;
        }
        $lengths = ['padding', 'paddingX', 'paddingY', 'margin', 'marginX', 'marginY', 'radius', 'maxWidth', 'minHeight', 'fontSize', 'letterSpacing', 'gap', 'columnMin'];
        $strings = ['aspectRatio', 'background', 'backgroundPosition', 'color', 'fontFamily', 'border', 'boxShadow', 'transform', 'filter'];
        $numbers = ['zIndex', 'opacity', 'fontWeight', 'lineHeight', 'columns'];
        $enums = ['overflow' => ['visible', 'hidden', 'auto'], 'backgroundSize' => ['cover', 'contain', 'auto'], 'textTransform' => ['none', 'uppercase', 'lowercase', 'capitalize'], 'textAlign' => ['left', 'center', 'right', 'justify'], 'direction' => ['row', 'column'], 'align' => ['start', 'center', 'end', 'stretch'], 'justify' => ['start', 'center', 'end', 'between', 'around']];
        foreach ($style as $key => $value) {
            // Null remains data in merge patches; both emitters omit it.
            if ($value === null) {
                continue;
            }
            $at = $path.'.'.$key;
            if (in_array($key, $lengths, true)) {
                if ($key !== 'marginX' || $value !== 'auto') {
                    $this->length($value, $at);
                }
            } elseif (in_array($key, $strings, true)) {
                $this->string($value, $at, false);
            } elseif (in_array($key, $numbers, true)) {
                $this->number($value, $at);
            } elseif (isset($enums[$key])) {
                $this->enum($value, $enums[$key], $at);
            } elseif (in_array($key, ['sticky', 'hidden', 'wrap'], true) && ! is_bool($value)) {
                $this->error($at, 'boolean|null', $value);
            }
        }
    }

    public function constraints(mixed $constraints, string $path): void
    {
        if (! $this->record($constraints, $path)) {
            return;
        }
        foreach ($constraints as $key => $value) {
            if ($value === null) {
                continue;
            }
            $at = $path.'.'.$key;
            if (in_array($key, ['width', 'height'], true)) {
                if ($value !== 'fill' && $value !== 'hug') {
                    $this->length($value, $at);
                }
            } elseif (in_array($key, ['left', 'right', 'top', 'bottom'], true)) {
                $this->length($value, $at);
            } elseif (in_array($key, ['centerX', 'centerY'], true) && ! is_bool($value)) {
                $this->error($at, 'boolean|null', $value);
            }
        }
    }

    private function length(mixed $length, string $path): void
    {
        if ($length instanceof stdClass) {
            $length = (array) $length;
        }
        if (! $this->record($length, $path)) {
            return;
        }
        $this->number($length['value'] ?? null, $path.'.value');
        $this->enum($length['unit'] ?? null, ['px', '%', 'vw', 'vh', 'rem', 'fr'], $path.'.unit');
    }

    public function animation(mixed $anim, string $path): void
    {
        if (! $this->record($anim, $path)) {
            return;
        }
        $this->string($anim['id'] ?? null, $path.'.id');
        $this->enum($anim['driver'] ?? null, ['enter', 'scroll', 'loop', 'hover', 'click', 'drag'], $path.'.driver');
        $frames = $anim['keyframes'] ?? null;
        if (! is_array($frames) || ! array_is_list($frames)) {
            $this->error($path.'.keyframes', 'list', $frames);
        } else {
            foreach ($frames as $i => $frame) {
                if ($this->record($frame, $path.'.keyframes.'.$i)) {
                    $this->number($frame['at'] ?? null, $path.'.keyframes.'.$i.'.at');
                    if ($this->record($frame['props'] ?? null, $path.'.keyframes.'.$i.'.props')) {
                        foreach ($frame['props'] as $key => $value) {
                            if (! is_string($value)) {
                                $this->number($value, $path.'.keyframes.'.$i.'.props.'.$key);
                            }
                        }
                    }
                }
            }
        }
        foreach (['duration', 'delay', 'stagger'] as $key) {
            if (array_key_exists($key, $anim)) {
                $this->number($anim[$key], $path.'.'.$key);
            }
        }
        if (isset($anim['range']) && $this->record($anim['range'], $path.'.range')) {
            foreach (['start', 'end'] as $key) {
                $this->number($anim['range'][$key] ?? null, $path.'.range.'.$key);
            }
        }
        foreach (['ease'] as $key) {
            if (array_key_exists($key, $anim)) {
                $this->string($anim[$key], $path.'.'.$key, false);
            }
        }
        if (array_key_exists('once', $anim) && ! is_bool($anim['once'])) {
            $this->error($path.'.once', 'boolean', $anim['once']);
        }
        if (array_key_exists('emit', $anim)) {
            $this->enum($anim['emit'], ['css', 'js'], $path.'.emit');
        }
    }

    public function action(mixed $action, string $path): void
    {
        if (! $this->record($action, $path)) {
            return;
        }
        $this->enum($action['on'] ?? null, ['click', 'submit', 'change', 'load', 'timer', 'scroll-into', 'hover'], $path.'.on');
        $handler = $action['do'] ?? null;
        if (! $this->record($handler, $path.'.do')) {
            return;
        }
        $kind = $handler['kind'] ?? null;
        if (! $this->enum($kind, ['navigate', 'submitForm', 'mutate', 'callEndpoint', 'runFlow', 'openModal', 'openDrawer', 'playTimeline', 'toggleNode'], $path.'.do.kind')) {
            return;
        }
        $target = match ($kind) {
            'navigate' => 'href', 'submitForm', 'callEndpoint' => 'endpoint', 'mutate' => 'target', 'runFlow' => 'flow', 'playTimeline' => 'section', default => 'node',
        };
        if ($kind === 'navigate' && ! is_string($handler[$target] ?? null)) {
            $this->binding($handler[$target] ?? null, $path.'.do.'.$target);
        } else {
            $this->string($handler[$target] ?? null, $path.'.do.'.$target, false);
        }
        if ($kind === 'mutate' && ! array_key_exists('value', $handler)) {
            $this->error($path.'.do.value', 'required JSON value', null);
        }
        if (array_key_exists('method', $handler)) {
            if ($kind === 'submitForm') {
                $this->enum($handler['method'], ['post', 'put'], $path.'.do.method');
            } else {
                $this->string($handler['method'], $path.'.do.method');
            }
        }
    }

    private function binding(mixed $binding, string $path): void
    {
        if ($binding instanceof stdClass) {
            $binding = (array) $binding;
        }
        if (! $this->record($binding, $path)) {
            return;
        }
        $this->string($binding['$bind'] ?? null, $path.'.$bind');
        if (isset($binding['$source'])) {
            if (! is_array($binding['$source']) || ! array_is_list($binding['$source'])) {
                $this->error($path.'.$source', 'list', $binding['$source']);
            } else {
                foreach ($binding['$source'] as $i => $part) {
                    if (! is_string($part)) {
                        $this->number($part, $path.'.$source.'.$i);
                    }
                }
            }
        }
    }
}
