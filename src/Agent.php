<?php

declare(strict_types=1);

namespace FancyCms;

use FancyCms\Document\Icons;
use RuntimeException;

/** The package-owned prompt and compact, JSON-friendly agent catalogue. */
final class Agent
{
    /** A bounded tool response; never append the full catalogue to a prompt. */
    public static function icons(string $query = '', int $cursor = 0): array
    {
        return Icons::search($query, $cursor);
    }

    public static function guide(): string
    {
        $path = dirname(__DIR__).'/agent-guide/professional-pages.md';
        $text = is_file($path) ? file_get_contents($path) : false;
        if ($text === false) {
            throw new RuntimeException('[fancy-cms] agent-guide/professional-pages.md is missing from this install.');
        }

        return $text;
    }

    /** Shorthand shapes, not JSON Schema. Serialize without pretty printing. */
    public static function describe(): array
    {
        $landmarks = ['section', 'header', 'nav', 'main', 'footer', 'article', 'aside', 'div'];
        $container = ['as' => $landmarks, 'anchor' => '^[a-z][a-z0-9-]{0,63}$'];
        $style = [];
        foreach (['padding', 'paddingX', 'paddingY', 'margin', 'marginY', 'radius', 'maxWidth', 'minHeight', 'fontSize', 'letterSpacing', 'gap', 'columnMin'] as $name) {
            $style[$name] = 'Length';
        }
        $style['marginX'] = 'Length|auto';
        foreach (['aspectRatio', 'background', 'backgroundPosition', 'color', 'fontFamily', 'border', 'boxShadow', 'transform', 'filter'] as $name) {
            $style[$name] = 'CSS string (declaration/rule escapes refused)';
        }
        foreach (['zIndex', 'opacity', 'fontWeight', 'lineHeight', 'columns'] as $name) {
            $style[$name] = 'finite number';
        }
        foreach (['sticky', 'hidden', 'wrap'] as $name) {
            $style[$name] = 'boolean';
        }
        $style += [
            'overflow' => ['visible', 'hidden', 'auto'], 'backgroundSize' => ['cover', 'contain', 'auto'],
            'textTransform' => ['none', 'uppercase', 'lowercase', 'capitalize'], 'textAlign' => ['left', 'center', 'right', 'justify'],
            'direction' => ['row', 'column'], 'align' => ['start', 'center', 'end', 'stretch'], 'justify' => ['start', 'center', 'end', 'between', 'around'],
        ];

        return [
            'notation' => '? means optional; | means alternatives; named shapes below; props may use Binding except icon; icon props are closed literals; patches are shallow and null stays data',
            'ops' => [
                'insert_node' => ['node' => 'Node'], 'remove_node' => ['id' => 'node id'],
                'move_node' => ['id' => 'node id', 'parent' => 'node id|null', 'order' => 'fractional key'],
                'set_node_props' => ['id' => 'node id', 'patch' => 'props record'],
                'set_layout' => ['id' => 'node id', 'layout?' => ['free', 'stack', 'grid']],
                'set_style' => ['id' => 'node id', 'breakpoint' => 'breakpoint', 'patch' => 'Style'],
                'set_constraints' => ['id' => 'node id', 'breakpoint' => 'breakpoint', 'patch' => 'Constraints'],
                'set_animation' => ['id' => 'node id', 'anim' => 'Animation'],
                'remove_animation' => ['id' => 'node id', 'animId' => 'animation id'],
                'set_action' => ['id' => 'node id', 'index' => 'number', 'action' => 'Action|null'],
                'set_meta' => ['patch' => 'Meta'], 'set_theme' => ['patch' => 'JSON token record'],
                'reorder_roots' => ['order' => 'all current root ids, exactly once'],
            ],
            'elements' => [
                'section' => $container, 'stack' => $container, 'grid' => $container,
                'frame' => $container + ['decorative' => 'literal boolean'], 'shape' => $container + ['decorative' => 'literal boolean'], 'card' => $container,
                'text' => ['content' => 'string'], 'heading' => ['content' => 'string', 'level' => [1, 2, 3, 4, 5, 6]],
                'link' => ['content' => 'string', 'href' => 'safe relative/http/https/mailto/tel URL'],
                'list' => ['items' => 'string[]', 'ordered' => 'boolean'],
                'divider' => [], 'callout' => ['content' => 'string', 'variant' => ['info', 'success', 'warning', 'danger']],
                'code' => ['content' => 'string'],
                'button' => ['label' => 'string', 'href' => 'safe relative/http/https/mailto/tel URL', 'variant' => ['primary', 'outline', 'ghost']],
                'image' => ['asset?' => 'literal host-supplied asset handle; use the host catalogue, never invent URLs, dimensions or variants', 'src' => 'legacy URL only when asset is absent', 'alt' => 'string', 'fit' => ['cover', 'contain', 'fill', 'none', 'scale-down'], 'loading' => ['lazy', 'eager']],
                'icon' => ['name' => 'Lucide name; search cms_icons(query,cursor)', 'decorative' => 'boolean (required); true hides from assistive technology', 'label?' => 'literal string, required and nonblank when decorative=false; max 1024 UTF-8 bytes'],
                'repeater' => ['note' => 'repeat.each/as live on Node; dynamic behavior requires browser runtime'],
            ],
            'commonProps' => ['anchor' => '^[a-z][a-z0-9-]{0,63}$ (rendered as id)'],
            'decorativeRule' => 'frame/shape decorative:true emits aria-hidden; descendants may ONLY be frame, shape or decorative icon without actions. Meaningful content is refused on insert, move, prop or action edits. Other elements cannot use decorative except icon.',
            'shapes' => [
                'Node' => ['id' => 'unique string', 'type' => 'element key', 'parent' => 'existing node id|null', 'order' => 'fractional key', 'props' => 'record', 'style' => '{base:Style, md?:Style, lg?:Style}', 'layout?' => ['free', 'stack', 'grid'], 'constraints?' => 'per-breakpoint Constraints', 'animations?' => 'Animation[]', 'actions?' => 'Action[]', 'className?' => 'string', 'island?' => 'boolean', 'repeat?' => ['each' => 'Binding', 'as' => 'string']],
                'Length' => ['value' => 'finite number', 'unit' => ['px', '%', 'vw', 'vh', 'rem', 'fr']],
                'Constraints' => ['width' => 'Length|fill|hug', 'height' => 'Length|fill|hug', 'left?' => 'Length|null', 'right?' => 'Length|null', 'top?' => 'Length|null', 'bottom?' => 'Length|null', 'centerX?' => 'boolean', 'centerY?' => 'boolean'],
                'Binding' => ['$bind' => 'dot.path in host-supplied data', '$source?' => 'live query key[]'],
                'Meta' => ['title?' => 'string', 'slug?' => 'string', 'scrollMode?' => ['smooth', 'snap'], 'seo?' => 'string record'],
                'Animation' => ['id' => 'string', 'driver' => ['enter', 'scroll', 'loop', 'hover', 'click', 'drag'], 'keyframes' => '{at:number,props:record}[]', 'range?' => '{start:number,end:number}', 'ease?' => 'string', 'duration?' => 'number', 'delay?' => 'number', 'stagger?' => 'number', 'once?' => 'boolean', 'emit?' => ['css', 'js']],
                'Action' => ['on' => ['click', 'submit', 'change', 'load', 'timer', 'scroll-into', 'hover'], 'do' => 'Handler'],
                'Handler' => ['kind' => ['navigate', 'submitForm', 'mutate', 'callEndpoint', 'runFlow', 'openModal', 'openDrawer', 'playTimeline', 'toggleNode'], 'href?' => 'Bound<string>', 'endpoint?' => 'string', 'method?' => 'string', 'target?' => 'string', 'value?' => 'Bound<JSON>', 'flow?' => 'string', 'node?' => 'node id', 'section?' => 'node id'],
            ],
            'style' => $style,
            'breakpoints' => ['base' => 0, 'md' => 768, 'lg' => 1024],
            'notes' => [
                'Each op includes t: its ops key. Omit layout to clear it. Style/constraints patches address one breakpoint; null is not deletion.',
                'Length is always {value,unit}, not a bare number. Theme tokens flatten to var(--cms-group-name). Unknown CSS breakpoints are not emitted.',
                'richtext is excluded: host-authored HTML only. Hosts must exclude that type from untrusted tools or sanitize it before browser rendering.',
                'Motion, actions and repeater behavior require the browser runtime. PHP renders native static elements; richtext HTML is not emitted by PHP.',
                'Changes use node:<URL-encoded id> and page:<URL-encoded doc.id> handles. Pin writes to the observed revision; after surface_changed_under_you read changes and preserve human edits.',
            ],
        ];
    }
}
