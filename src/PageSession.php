<?php

declare(strict_types=1);

namespace FancyCms;

use FancyCms\Document\PageDoc;
use FancyCms\Document\PageJson;
use FancyCms\Exceptions\PageSessionException;
use FancyCms\Exceptions\SchemaException;
use InvalidArgumentException;
use JsonException;

/**
 * One page, complete attributed history, and optimistic revisions.
 * The host must lock/CAS persisted state across load -> apply -> save.
 * JSON strings isolate opaque stdClass values from caller-owned references.
 */
final class PageSession
{
    private const MAX_BATCH = 1000;

    private const MAX_LOG = 10000;

    private string $document;

    private string $initialDocument;

    private string $revision;

    private string $initialRevision;

    /** @var list<array{op:string,actor:string,revision:string,changes:array}> */
    private array $log = [];

    public function __construct(array $doc, private readonly ?array $knownTypes = null)
    {
        PageDoc::assert($doc, $knownTypes);
        $this->initialDocument = $this->document = PageJson::encodeDocument($doc);
        $this->initialRevision = $this->revision = self::newRevision();
        $this->encodeState([]);
    }

    /** @return array{doc:array,revision:string} */
    public function get(): array
    {
        return ['doc' => PageJson::decodeDocument($this->document), 'revision' => $this->revision];
    }

    /** Atomic within this instance; no state changes until every op validates. */
    public function apply(array $ops, string $actor, ?string $basedOn = null): array
    {
        if ($basedOn !== null && ! hash_equals($this->revision, $basedOn)) {
            $this->refuse('surface_changed_under_you', '$.basedOn', 'current revision');
        }
        if (! in_array($actor, ['agent', 'human'], true)) {
            $this->refuse('invalid_request', '$.actor', 'agent|human');
        }
        if (! array_is_list($ops) || count($ops) > self::MAX_BATCH) {
            $this->refuse('invalid_request', '$.ops', 'list of at most 1000 operations');
        }
        if (count($this->log) + count($ops) > self::MAX_LOG) {
            $this->refuse('history_limit', '$.ops', 'at most 10000 retained operations; history is never silently pruned');
        }
        $doc = PageJson::decodeDocument($this->document);
        $entries = [];
        foreach ($ops as $i => $op) {
            if (! is_array($op)) {
                $this->refuse('invalid_operation', '$.ops.'.$i, 'operation record', $i);
            }
            try {
                $wire = PageJson::encodeOp($op);
                $op = PageJson::decodeOp($wire);
                $next = PageDoc::apply($doc, $op, $this->knownTypes);
            } catch (SchemaException $e) {
                $errors = array_map(static fn (array $error): array => ['index' => $i, ...$error, 'path' => '$.ops.'.$i.substr($error['path'], 1)], $e->getErrors());
                throw new PageSessionException('invalid_operation', $errors, $this->revision);
            } catch (InvalidArgumentException|JsonException $e) {
                $this->refuse('invalid_operation', '$.ops.'.$i, 'JSON operation record', $i);
            }
            $entries[] = ['op' => $wire, 'actor' => $actor, 'revision' => self::newRevision(), 'changes' => self::changes($doc, $next, $actor)];
            $doc = $next;
        }
        // Encoding can fail (for example on exhausted seq) before the commit.
        $document = PageJson::encodeDocument($doc);
        if ($entries !== []) {
            $this->encodeState([...$this->log, ...$entries]);
            $this->document = $document;
            $this->log = [...$this->log, ...$entries];
            $this->revision = $entries[array_key_last($entries)]['revision'];
        }

        return $this->get();
    }

    /**
     * Complete chronological events, including transient edits and tombstones.
     * Unknown cursors are errors, never a falsely complete empty answer.
     */
    public function changesSince(string $revision): array
    {
        $offset = $revision === $this->initialRevision ? 0 : null;
        if ($offset === null) {
            foreach ($this->log as $i => $entry) {
                if ($entry['revision'] === $revision) {
                    $offset = $i + 1;
                    break;
                }
            }
        }
        if ($offset === null) {
            $this->refuse('unknown_revision', '$.since', 'revision retained by this session');
        }
        $changes = [];
        for ($i = $offset, $n = count($this->log); $i < $n; $i++) {
            foreach ($this->log[$i]['changes'] as $change) {
                $changes[] = $change;
            }
        }

        return ['revision' => $this->revision, 'complete' => true, 'changes' => $changes];
    }

    public function opLog(): array
    {
        return array_map(static fn (array $entry): array => ['op' => PageJson::decodeOp($entry['op']), 'actor' => $entry['actor'], 'revision' => $entry['revision']], $this->log);
    }

    /** Versioned storage envelope; nested document/op JSON strings retain wire tags. */
    public function toJson(): string
    {
        return $this->encodeState($this->log);
    }

    /** Host storage is trusted, but malformed history is still refused on replay. */
    public static function fromJson(string $json, ?array $knownTypes = null): self
    {
        try {
            if (strlen($json) > 33554432) {
                throw new InvalidArgumentException('State exceeds 32 MiB.');
            }
            $decoded = json_decode($json, false, 32, JSON_THROW_ON_ERROR);
            if (! $decoded instanceof \stdClass) {
                throw new InvalidArgumentException('Expected a state object.');
            }
            $state = (array) $decoded;
            if (array_diff(array_keys($state), ['version', 'initialDocument', 'initialRevision', 'log']) !== []
                || ($state['version'] ?? null) !== 1 || ! is_string($state['initialDocument'] ?? null)
                || ! self::isRevision($state['initialRevision'] ?? null) || ! is_array($state['log'] ?? null)
                || ! array_is_list($state['log']) || count($state['log']) > self::MAX_LOG) {
                throw new InvalidArgumentException('Invalid state envelope.');
            }
            $session = new self(PageJson::decodeDocument($state['initialDocument']), $knownTypes);
            $session->initialRevision = $session->revision = $state['initialRevision'];
            $seen = [$session->revision => true];
            $doc = PageJson::decodeDocument($session->document);
            foreach ($state['log'] as $entry) {
                if (! $entry instanceof \stdClass) {
                    throw new InvalidArgumentException('Expected a log row object.');
                }
                $entry = (array) $entry;
                if (array_diff(array_keys($entry), ['op', 'actor', 'revision']) !== []
                    || ! self::isRevision($entry['revision'] ?? null) || isset($seen[$entry['revision']])
                    || ! in_array($entry['actor'] ?? null, ['agent', 'human'], true) || ! is_string($entry['op'] ?? null)) {
                    throw new InvalidArgumentException('Invalid log row.');
                }
                $seen[$entry['revision']] = true;
                $next = PageDoc::apply($doc, PageJson::decodeOp($entry['op']), $knownTypes);
                $session->log[] = [...$entry, 'changes' => self::changes($doc, $next, $entry['actor'])];
                $doc = $next;
                $session->revision = $entry['revision'];
            }
            $session->document = PageJson::encodeDocument($doc);

            return $session;
        } catch (SchemaException|PageSessionException|InvalidArgumentException|JsonException $e) {
            throw new PageSessionException('invalid_state', [['index' => null, 'path' => '$.state', 'expected' => 'valid version 1 session with replayable unique revisions', 'got' => 'invalid state', 'hint' => 'Restore only complete state saved by PageSession::toJson().']], null);
        }
    }

    private static function newRevision(): string
    {
        return 'r'.bin2hex(random_bytes(16));
    }

    private function encodeState(array $entries): string
    {
        $log = array_map(static fn (array $entry): array => array_diff_key($entry, ['changes' => true]), $entries);
        $json = json_encode(['version' => 1, 'initialDocument' => $this->initialDocument, 'initialRevision' => $this->initialRevision, 'log' => $log], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > 33554432) {
            $this->refuse('state_limit', '$.state', 'session state within 32 MiB');
        }

        return $json;
    }

    private static function isRevision(mixed $revision): bool
    {
        return is_string($revision) && preg_match('/\Ar[0-9a-f]{32}\z/', $revision) === 1;
    }

    private function refuse(string $code, string $path, string $expected, ?int $index = null): never
    {
        throw new PageSessionException($code, [['index' => $index, 'path' => $path, 'expected' => $expected, 'got' => 'refused', 'hint' => 'Read the current page and attributed changes before retrying.']], $this->revision);
    }

    private static function changes(array $before, array $after, string $actor): array
    {
        $rows = [];
        foreach ($before['nodes'] as $id => $node) {
            $next = $after['nodes'][$id] ?? null;
            if ($next === null) {
                $change = 'deleted';
            } elseif ($node === $next) {
                continue;
            } else {
                $change = $node['parent'] !== $next['parent'] || $node['order'] !== $next['order'] ? 'moved' : 'updated';
            }
            $rows[] = ['handle' => 'node:'.rawurlencode((string) $id), 'change' => $change, 'actor' => $actor];
        }
        foreach ($after['nodes'] as $id => $node) {
            if (! array_key_exists($id, $before['nodes'])) {
                $rows[] = ['handle' => 'node:'.rawurlencode((string) $id), 'change' => 'created', 'actor' => $actor];
            }
        }
        if ($before['meta'] !== $after['meta'] || $before['theme'] !== $after['theme']) {
            $rows[] = ['handle' => 'page:'.rawurlencode($after['id']), 'change' => 'updated', 'actor' => $actor];
        }

        return $rows;
    }
}
