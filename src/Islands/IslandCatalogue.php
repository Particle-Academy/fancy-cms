<?php

declare(strict_types=1);

namespace FancyCms\Islands;

use FancyCms\Document\PageDoc;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use stdClass;

/**
 * The island catalogue: every component node type a host renders, each with a
 * JSON Schema (draft 2020-12) for its props, an example, and how it hydrates.
 * `Agent::describe($catalogue)` returns it, so an agent knows which types it
 * may place and what props each takes.
 *
 * The built-ins are the infographic kit, read from resources/islands/catalogue.json,
 * which fancy-cms-ui ships byte-identical. A host adds its own:
 *
 *     $islands = IslandCatalogue::builtin()
 *         ->register('kit:Tabs', ['description' => 'Tabbed panels', 'props' => $schema, 'example' => [...]]);
 *     // or, from a JS host that registered them with defineIsland/registerIslands:
 *     $islands = IslandCatalogue::builtin()->merge(file_get_contents('islands.json'));
 *
 *     Agent::describe($islands);                                  // the catalogue an agent reads
 *     new PageSession($doc, $islands->nodeTypes());                // the types a document may hold
 *     PageRenderer::render($doc, $data, islands: $islands);        // marks them as islands
 *
 * Immutable: register() and merge() return a new catalogue, so one built at boot
 * is safe to share across requests and workers.
 */
final class IslandCatalogue
{
    /** A lowercase namespace and a name (`kit:Chart`), or one lowercase word. */
    public const TYPE_PATTERN = '/\A[a-z][a-z0-9-]*(:[A-Za-z][A-Za-z0-9-]*)?\z/';

    public const HYDRATIONS = ['none', 'lazy', 'eager'];

    private static ?self $builtin = null;

    private static ?self $kit = null;

    /**
     * @param  array<string,mixed>  $document  `$schema` and `$defs`
     * @param  array<string,array<string,mixed>>  $types
     */
    private function __construct(private readonly array $document, private readonly array $types) {}

    /** The shipped catalogue: chart, kpi, table, leaderboard. */
    public static function builtin(): self
    {
        if (self::$builtin === null) {
            $path = dirname(__DIR__, 2).'/resources/islands/catalogue.json';
            $json = is_file($path) ? file_get_contents($path) : false;
            if ($json === false) {
                throw new RuntimeException('[fancy-cms] resources/islands/catalogue.json is missing from this install.');
            }
            $decoded = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
            $types = [];
            foreach ((array) $decoded->types as $type => $definition) {
                $types[(string) $type] = (array) $definition;
            }
            self::$builtin = new self(['$schema' => $decoded->{'$schema'}, '$defs' => $decoded->{'$defs'}], $types);
        }

        return self::$builtin;
    }

    /**
     * The built-ins plus every Fancy kit component as an island (`react-fancy:Tabs`,
     * `fancy-sheets:Spreadsheet` and the rest), read from
     * resources/islands/kit-catalogue.json, which @particle-academy/fancy-cms-ui
     * generates from the components' prop types and ships byte-identical as
     * `KIT_ISLAND_CATALOGUE` (its `./kit` entry). For a host that renders with
     * that entry's `kitIslands` registered: list types no host can render and an
     * agent will place them.
     */
    public static function kit(): self
    {
        if (self::$kit === null) {
            $path = dirname(__DIR__, 2).'/resources/islands/kit-catalogue.json';
            $json = is_file($path) ? file_get_contents($path) : false;
            if ($json === false) {
                throw new RuntimeException('[fancy-cms] resources/islands/kit-catalogue.json is missing from this install.');
            }
            self::$kit = self::builtin()->merge($json);
        }

        return self::$kit;
    }

    /** Whether a type renders its child nodes (`slot: children`): a Card, a Modal's body. */
    public function rendersChildren(string $type): bool
    {
        return ($this->types[$type]['slot'] ?? null) === 'children';
    }

    /**
     * Add one host island.
     *
     * @param  array{description:string,props:array<string,mixed>|object,example?:array<string,mixed>|object,hydrate?:string}  $definition
     */
    public function register(string $type, array $definition): self
    {
        if (preg_match(self::TYPE_PATTERN, $type) !== 1) {
            throw new InvalidArgumentException("[fancy-cms] \"{$type}\" is not a valid island type: use a lowercase namespace and a name, like \"kit:Chart\".");
        }
        if (in_array($type, PageDoc::NODE_TYPES, true) && ! isset(self::builtin()->types[$type])) {
            throw new InvalidArgumentException("[fancy-cms] \"{$type}\" is a native node type and cannot be an island.");
        }
        if (isset($this->types[$type])) {
            throw new InvalidArgumentException("[fancy-cms] island \"{$type}\" is already registered.");
        }
        $types = $this->types;
        $types[$type] = self::normalise($type, $definition);

        return new self($this->document, $types);
    }

    /**
     * Add every type from a serialised catalogue (`islandCatalogue(islands)` from
     * fancy-cms-ui, or another catalogue's toArray()). A type identical to one
     * already registered is skipped, so the built-ins a JS host exports with its
     * own islands merge cleanly; a type that differs is a collision.
     */
    public function merge(string|array $catalogue): self
    {
        try {
            $data = is_string($catalogue) ? json_decode($catalogue, false, 512, JSON_THROW_ON_ERROR) : json_decode((string) json_encode($catalogue, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('[fancy-cms] the island catalogue is not valid JSON.', 0, $e);
        }
        if (! $data instanceof stdClass || ! ($data->types ?? null) instanceof stdClass) {
            throw new InvalidArgumentException('[fancy-cms] an island catalogue needs a "types" object.');
        }
        $out = $this;
        foreach ((array) $data->types as $type => $definition) {
            $type = (string) $type;
            $definition = $definition instanceof stdClass ? (array) $definition : null;
            if ($definition === null) {
                throw new InvalidArgumentException("[fancy-cms] island \"{$type}\" must be an object.");
            }
            if (isset($out->types[$type])) {
                if (json_encode($out->types[$type]) === json_encode(self::normalise($type, $definition))) {
                    continue;
                }
            }
            $out = $out->register($type, $definition);
        }

        return $out;
    }

    /** @return list<string> */
    public function types(): array
    {
        return array_keys($this->types);
    }

    public function has(string $type): bool
    {
        return isset($this->types[$type]);
    }

    /** Whether a registered type renders as an island (its hydrate is not `none`). */
    public function hydrates(string $type): bool
    {
        return isset($this->types[$type]) && $this->types[$type]['hydrate'] !== 'none';
    }

    /** @return array<string,mixed>|null */
    public function definition(string $type): ?array
    {
        return $this->types[$type] ?? null;
    }

    /**
     * The node types a document may hold: the native ones plus every registered
     * island. Pass it to PageDoc::validate / PageSession as `$knownTypes`.
     *
     * @return list<string>
     */
    public function nodeTypes(): array
    {
        return array_values(array_unique([...PageDoc::NODE_TYPES, ...array_keys($this->types)]));
    }

    /**
     * The catalogue as JSON-ready data. Schemas read from JSON keep their
     * objects as stdClass, so an empty `{}` never re-encodes as `[]`; `types`
     * is a plain array keyed by type name, never empty (the built-ins are
     * always present) and never list-shaped (every name starts with a letter).
     */
    public function toArray(): array
    {
        return $this->document + ['types' => $this->types];
    }

    private static function isTextFallback(mixed $steps): bool
    {
        if (! is_array($steps) || ! array_is_list($steps)) {
            return false;
        }
        foreach ($steps as $step) {
            $s = $step instanceof stdClass ? get_object_vars($step) : $step;
            if (! is_array($s) || $s === [] || array_is_list($s)) {
                return false;
            }
            $keys = array_keys($s);
            if (count($keys) === 1 && in_array($keys[0], ['text', 'code'], true)) {
                if (! is_string($s[$keys[0]])) {
                    return false;
                }

                continue;
            }
            if (! in_array('list', $keys, true) || array_diff($keys, ['list', 'fields']) !== [] || ! is_string($s['list'])) {
                return false;
            }
            if (array_key_exists('fields', $s) && (! is_array($s['fields']) || ! array_is_list($s['fields']) || array_filter($s['fields'], 'is_string') !== $s['fields'])) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string,mixed> the definition in a fixed key order */
    private static function normalise(string $type, array $d): array
    {
        $description = $d['description'] ?? null;
        if (! is_string($description) || trim($description) === '') {
            throw new InvalidArgumentException("[fancy-cms] island \"{$type}\" needs a description.");
        }
        $hydrate = $d['hydrate'] ?? 'eager';
        if (! is_string($hydrate) || ! in_array($hydrate, self::HYDRATIONS, true)) {
            throw new InvalidArgumentException("[fancy-cms] island \"{$type}\" hydrate must be none, lazy or eager.");
        }
        $props = $d['props'] ?? null;
        $propsType = $props instanceof stdClass ? ($props->type ?? null) : (is_array($props) && ! array_is_list($props) ? ($props['type'] ?? null) : null);
        if ($propsType !== 'object') {
            throw new InvalidArgumentException("[fancy-cms] island \"{$type}\" props must be a JSON object schema ({\"type\": \"object\", ...}).");
        }
        $out = ['description' => $description, 'hydrate' => $hydrate, 'props' => $props];
        if (array_key_exists('example', $d)) {
            $example = $d['example'];
            if (! $example instanceof stdClass && ! (is_array($example) && ($example === [] || ! array_is_list($example)))) {
                throw new InvalidArgumentException("[fancy-cms] island \"{$type}\" example must be a props object.");
            }
            $out['example'] = $example;
        }
        if (array_key_exists('package', $d)) {
            if (! is_string($d['package']) || $d['package'] === '') {
                throw new InvalidArgumentException("[fancy-cms] island \"{$type}\" package must be a package name.");
            }
            $out['package'] = $d['package'];
        }
        if (array_key_exists('slot', $d)) {
            if ($d['slot'] !== 'children') {
                throw new InvalidArgumentException("[fancy-cms] island \"{$type}\" slot must be \"children\".");
            }
            $out['slot'] = 'children';
        }
        if (array_key_exists('textFallback', $d)) {
            if (! self::isTextFallback($d['textFallback'])) {
                throw new InvalidArgumentException("[fancy-cms] island \"{$type}\" textFallback must be a list of {text}, {code} or {list, fields?} steps.");
            }
            $out['textFallback'] = $d['textFallback'];
        }

        return $out;
    }
}
