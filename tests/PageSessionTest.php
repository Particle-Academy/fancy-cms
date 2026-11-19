<?php

declare(strict_types=1);

namespace FancyCms\Tests\PageSessionTest;

use FancyCms\Document\PageJson;
use FancyCms\Exceptions\PageSessionException;
use FancyCms\Exceptions\SchemaException;
use FancyCms\PageSession;
use FancyCms\Tests\Support\PageFixtures as F;

function patch(string $id, mixed $content): array {
    return ['t' => 'set_node_props', 'id' => $id, 'patch' => ['content' => $content]];
}

test('atomic batch returns a revision and an attributed op log', function () {
    $session = new PageSession(F::doc());
    $initial = $session->get();
    $ops = [patch('a', 'First'), patch('b', 'Second')];
    $result = $session->apply($ops, 'agent', $initial['revision']);
    $this->assertSame(2, $result['doc']['seq']);
    $this->assertSame('First', $result['doc']['nodes']['a']['props']['content']);
    $this->assertNotSame($initial['revision'], $result['revision']);
    $this->assertSame($result, $session->get());
    $this->assertSame($ops, array_column($session->opLog(), 'op'));
    $this->assertSame(['agent', 'agent'], array_column($session->opLog(), 'actor'));
    $this->assertCount(2, array_unique(array_column($session->opLog(), 'revision')));
});

test('human edit between agent read and write is refused and visible', function () {
    $session = new PageSession(F::doc());
    $read = $session->get();
    $session->apply([patch('a', 'Human wording')], 'human', $read['revision']);
    $before = $session->toJson();
    try {
        $session->apply([patch('a', 'Agent overwrite')], 'agent', $read['revision']);
        $this->fail('A stale write must fail.');
    } catch (PageSessionException $e) {
        $this->assertSame('surface_changed_under_you', $e->getErrorCode());
        $this->assertSame($session->get()['revision'], $e->getRevision());
        $this->assertSame('$.basedOn', $e->getErrors()[0]['path']);
    }
    $this->assertSame($before, $session->toJson(), 'Refusal must not change document, history or revision.');
    $this->assertSame('Human wording', $session->get()['doc']['nodes']['a']['props']['content']);
    $this->assertSame([
        'revision' => $session->get()['revision'], 'complete' => true,
        'changes' => [['handle' => 'node:a', 'change' => 'updated', 'actor' => 'human']],
    ], $session->changesSince($read['revision']));
});

test('bad second op rolls back the entire batch with index and path', function () {
    $session = new PageSession(F::doc());
    $snapshot = $session->get();
    $before = $session->toJson();
    try {
        $session->apply([patch('a', 'Must roll back'), ['t' => 'set_style', 'id' => 'b', 'breakpoint' => 'base', 'patch' => ['fontSize' => ['value' => 20, 'unit' => 'evil']]]], 'agent');
        $this->fail('Invalid second op must fail.');
    } catch (PageSessionException $e) {
        $this->assertSame('invalid_operation', $e->getErrorCode());
        $this->assertSame(1, $e->getErrors()[0]['index']);
        $this->assertSame('$.ops.1.patch.fontSize.unit', $e->getErrors()[0]['path']);
    }
    $this->assertSame($snapshot, $session->get(), 'The live document must roll back as well as the saved log.');
    $this->assertSame([], $session->opLog());
    $this->assertSame($before, $session->toJson());
});

test('semantically inapplicable later op rolls back without logging', function () {
    $session = new PageSession(F::doc());
    $snapshot = $session->get();
    $before = $session->toJson();
    try {
        $session->apply([patch('a', 'Rollback'), patch('missing', 'No node')], 'human');
        $this->fail('Missing target must fail.');
    } catch (PageSessionException $e) {
        $this->assertSame(1, $e->getErrors()[0]['index']);
    }
    $this->assertSame($snapshot, $session->get());
    $this->assertSame($before, $session->toJson());
});

test('unknown revision is an error instead of false silence', function () {
    $session = new PageSession(F::doc());
    try {
        $session->changesSince('never-observed');
        $this->fail('Unknown history is not a complete empty feed.');
    } catch (PageSessionException $e) {
        $this->assertSame('unknown_revision', $e->getErrorCode());
    }
    $revision = $session->get()['revision'];
    $this->assertSame(['revision' => $revision, 'complete' => true, 'changes' => []], $session->changesSince($revision));
    $this->assertSame($revision, $session->apply([], 'agent', $revision)['revision']);
});

test('subtree deletion has tombstones for every removed handle', function () {
    $session = new PageSession(F::doc());
    $revision = $session->get()['revision'];
    $session->apply([['t' => 'remove_node', 'id' => 'a']], 'human');
    $this->assertSame([
        ['handle' => 'node:a', 'change' => 'deleted', 'actor' => 'human'],
        ['handle' => 'node:child', 'change' => 'deleted', 'actor' => 'human'],
        ['handle' => 'node:grandchild', 'change' => 'deleted', 'actor' => 'human'],
    ], $session->changesSince($revision)['changes']);
});

test('created moved and page changes use unambiguous stable handles', function () {
    $session = new PageSession(F::doc());
    $revision = $session->get()['revision'];
    $session->apply([
        ['t' => 'insert_node', 'node' => F::node('page:page')],
        ['t' => 'move_node', 'id' => 'b', 'parent' => 'a', 'order' => 'X'],
        ['t' => 'set_meta', 'patch' => ['title' => 'Human page']],
        ['t' => 'set_theme', 'patch' => ['color' => '#fff']],
    ], 'human');
    $this->assertSame([
        ['handle' => 'node:page%3Apage', 'change' => 'created', 'actor' => 'human'],
        ['handle' => 'node:b', 'change' => 'moved', 'actor' => 'human'],
        ['handle' => 'page:page', 'change' => 'updated', 'actor' => 'human'],
        ['handle' => 'page:page', 'change' => 'updated', 'actor' => 'human'],
    ], $session->changesSince($revision)['changes']);
});

test('reorder reports moved roots and repeated actors are not coalesced', function () {
    $session = new PageSession(F::doc());
    $revision = $session->get()['revision'];
    $session->apply([['t' => 'reorder_roots', 'order' => ['b', 'a']]], 'human');
    $moves = $session->changesSince($revision)['changes'];
    $this->assertEqualsCanonicalizing(['node:a', 'node:b'], array_column($moves, 'handle'));
    $this->assertSame(['moved', 'moved'], array_column($moves, 'change'));
    $since = $session->get()['revision'];
    $session->apply([patch('a', 'Person')], 'human');
    $session->apply([patch('a', 'Agent')], 'agent');
    $this->assertSame(['human', 'agent'], array_column($session->changesSince($since)['changes'], 'actor'));
});

test('session round trip preserves cursors attribution and json object tags', function () {
    $doc = F::doc();
    $doc['nodes']['0'] = F::node('0');
    $doc['nodes']['0']['props']['opaque'] = (object) ['empty' => new \stdClass, 'list' => []];
    $session = new PageSession($doc);
    $revision = $session->get()['revision'];
    $session->apply([patch('a', 'Person')], 'human');
    $mid = $session->get()['revision'];
    $session->apply([patch('b', 'Agent')], 'agent');
    $restored = PageSession::fromJson($session->toJson());
    $this->assertSame($session->toJson(), $restored->toJson());
    $this->assertSame(PageJson::encodeDocument($session->get()['doc']), PageJson::encodeDocument($restored->get()['doc']));
    $this->assertSame($session->changesSince($revision), $restored->changesSince($revision));
    $this->assertSame(['agent'], array_column($restored->changesSince($mid)['changes'], 'actor'));
    $restored->apply([patch('a', 'After restore')], 'agent', $restored->get()['revision']);
    $this->assertCount(3, $restored->opLog());
});

test('opaque json objects cannot mutate session through inputs or results', function () {
    $object = (object) ['value' => 'original'];
    $doc = F::doc();
    $doc['nodes']['a']['props']['object'] = $object;
    $session = new PageSession($doc);
    $before = $session->toJson();
    $object->value = 'external';
    $snapshot = $session->get();
    $snapshot['doc']['nodes']['a']['props']['object']->value = 'snapshot';
    $this->assertSame($before, $session->toJson());
    $payload = (object) ['value' => 'committed'];
    $session->apply([patch('a', $payload)], 'human');
    $before = $session->toJson();
    $payload->value = 'external op';
    $log = $session->opLog();
    $log[0]['op']['patch']['content']->value = 'log';
    $this->assertSame($before, $session->toJson());
});

test('bad envelopes are refused without writes', function (array $ops, string $actor) {
    $session = new PageSession(F::doc());
    $before = $session->toJson();
    try {
        $session->apply($ops, $actor);
        $this->fail('Bad envelope must fail.');
    } catch (PageSessionException $e) {
        $this->assertNotEmpty($e->getErrors());
    }
    $this->assertSame($before, $session->toJson());
})->with(fn (): iterable => invalidRequests());

function invalidRequests(): array {
    return ['actor' => [[], 'operator'], 'map' => [['one' => ['t' => 'set_theme', 'patch' => []]], 'agent'], 'scalar' => [[42], 'agent'], 'too many' => [array_fill(0, 1001, ['t' => 'set_theme', 'patch' => []]), 'human']];
}

test('independent sessions never accept each others revision', function () {
    $a = new PageSession(F::doc());
    $b = new PageSession(F::doc());
    $this->assertNotSame($a->get()['revision'], $b->get()['revision']);
    $this->expectException(PageSessionException::class);
    $b->apply([patch('a', 'Cross page')], 'agent', $a->get()['revision']);
});

test('addon registry is host supplied on create and restore', function () {
    $doc = F::doc();
    $doc['nodes']['a']['type'] = 'store-card';
    $session = new PageSession($doc, ['text', 'store-card']);
    $session->apply([patch('a', 'Product')], 'agent');
    $this->assertSame($session->toJson(), PageSession::fromJson($session->toJson(), ['text', 'store-card'])->toJson());
    $this->expectException(PageSessionException::class);
    PageSession::fromJson($session->toJson());
});

test('invalid initial document is refused', function () {
    $doc = F::doc();
    $doc['nodes']['a']['parent'] = 'missing';
    $this->expectException(SchemaException::class);
    new PageSession($doc);
});

test('corrupt state and duplicate revision history are refused', function () {
    $session = new PageSession(F::doc());
    $session->apply([patch('a', 'Edited')], 'human');
    $state = json_decode($session->toJson(), true, 128, JSON_THROW_ON_ERROR);
    $state['log'][0]['revision'] = $state['initialRevision'];
    try {
        PageSession::fromJson(json_encode($state, JSON_THROW_ON_ERROR));
        $this->fail('Duplicate revision must fail.');
    } catch (PageSessionException $e) {
        $this->assertSame('invalid_state', $e->getErrorCode());
    }
    $this->expectException(PageSessionException::class);
    PageSession::fromJson('{bad json');
});

test('object in place of log list is not a complete empty history', function () {
    $state = json_decode((new PageSession(F::doc()))->toJson(), false, 32, JSON_THROW_ON_ERROR);
    $state->log = new \stdClass;
    $this->expectException(PageSessionException::class);
    PageSession::fromJson(json_encode($state, JSON_THROW_ON_ERROR));
});

test('restore revalidates operations and does not trust recorded effects', function () {
    $session = new PageSession(F::doc());
    $session->apply([patch('a', 'Edited')], 'human');
    $state = json_decode($session->toJson(), true, 32, JSON_THROW_ON_ERROR);
    $state['log'][0]['op'] = PageJson::encodeOp(patch('missing', 'Bogus target'));
    $this->expectException(PageSessionException::class);
    PageSession::fromJson(json_encode($state, JSON_THROW_ON_ERROR));
});

test('invalid result document also rolls back the batch', function () {
    $session = new PageSession(F::doc());
    $snapshot = $session->get();
    $before = $session->toJson();
    try {
        $session->apply([patch('a', 'Rollback'), ['t' => 'set_meta', 'patch' => ['title' => null]]], 'agent');
        $this->fail('Post-op document validation must be atomic too.');
    } catch (PageSessionException $e) {
        $this->assertSame(1, $e->getErrors()[0]['index']);
        $this->assertStringContainsString('.meta.title', $e->getErrors()[0]['path']);
    }
    $this->assertSame($snapshot, $session->get());
    $this->assertSame($before, $session->toJson());
});

test('reloading the persisted human state refuses the agents old cursor', function () {
    $original = new PageSession(F::doc());
    $agentRead = $original->get();
    $humanRequest = PageSession::fromJson($original->toJson());
    $humanRequest->apply([patch('a', 'Persisted human edit')], 'human');
    $agentRequest = PageSession::fromJson($humanRequest->toJson());
    try {
        $agentRequest->apply([patch('a', 'Stale agent')], 'agent', $agentRead['revision']);
        $this->fail('Reloaded state must retain optimistic concurrency.');
    } catch (PageSessionException $e) {
        $this->assertSame('surface_changed_under_you', $e->getErrorCode());
    }
    $this->assertSame(['human'], array_column($agentRequest->changesSince($agentRead['revision'])['changes'], 'actor'));
});

test('history limit refuses without pruning or changing the page', function () {
    $doc = F::doc();
    $doc['nodes'] = [];
    $session = new PageSession($doc);
    $initial = $session->get()['revision'];
    $batch = array_fill(0, 1000, ['t' => 'set_meta', 'patch' => ['title' => 'History']]);
    for ($i = 0; $i < 10; $i++) {
        $session->apply($batch, 'human');
    }
    $before = $session->get();
    try {
        $session->apply([$batch[0]], 'agent');
        $this->fail('Full history must refuse, not silently prune.');
    } catch (PageSessionException $e) {
        $this->assertSame('history_limit', $e->getErrorCode());
    }
    $this->assertSame($before, $session->get());
    $this->assertCount(10000, $session->opLog());
    $this->assertTrue($session->changesSince($initial)['complete']);
});

test('feed handles preserve ids that a consumer would otherwise trim', function () {
    $doc = F::doc();
    $doc['id'] = ' page ';
    $doc['nodes'] = ['0' => F::node('0'), ' spaced ' => F::node(' spaced '), 'café:id' => F::node('café:id')];
    $session = new PageSession($doc);
    $revision = $session->get()['revision'];
    $ops = [];
    foreach ($doc['nodes'] as $node) {
        $ops[] = patch($node['id'], 'Edited');
    }
    $ops[] = ['t' => 'set_meta', 'patch' => ['title' => 'Edited']];
    $session->apply($ops, 'human');
    $this->assertSame(['node:0', 'node:%20spaced%20', 'node:caf%C3%A9%3Aid', 'page:%20page%20'], array_column($session->changesSince($revision)['changes'], 'handle'));
});
