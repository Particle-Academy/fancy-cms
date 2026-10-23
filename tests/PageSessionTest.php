<?php

declare(strict_types=1);

namespace FancyCms\Tests;

use FancyCms\Document\PageJson;
use FancyCms\Exceptions\PageSessionException;
use FancyCms\Exceptions\SchemaException;
use FancyCms\PageSession;
use FancyCms\Tests\Support\PageFixtures as F;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PageSessionTest extends TestCase
{
    private function patch(string $id, mixed $content): array
    {
        return ['t' => 'set_node_props', 'id' => $id, 'patch' => ['content' => $content]];
    }

    public function test_atomic_batch_returns_a_revision_and_an_attributed_op_log(): void
    {
        $session = new PageSession(F::doc());
        $initial = $session->get();
        $ops = [$this->patch('a', 'First'), $this->patch('b', 'Second')];
        $result = $session->apply($ops, 'agent', $initial['revision']);
        self::assertSame(2, $result['doc']['seq']);
        self::assertSame('First', $result['doc']['nodes']['a']['props']['content']);
        self::assertNotSame($initial['revision'], $result['revision']);
        self::assertSame($result, $session->get());
        self::assertSame($ops, array_column($session->opLog(), 'op'));
        self::assertSame(['agent', 'agent'], array_column($session->opLog(), 'actor'));
        self::assertCount(2, array_unique(array_column($session->opLog(), 'revision')));
    }

    public function test_human_edit_between_agent_read_and_write_is_refused_and_visible(): void
    {
        $session = new PageSession(F::doc());
        $read = $session->get();
        $session->apply([$this->patch('a', 'Human wording')], 'human', $read['revision']);
        $before = $session->toJson();
        try {
            $session->apply([$this->patch('a', 'Agent overwrite')], 'agent', $read['revision']);
            self::fail('A stale write must fail.');
        } catch (PageSessionException $e) {
            self::assertSame('surface_changed_under_you', $e->getErrorCode());
            self::assertSame($session->get()['revision'], $e->getRevision());
            self::assertSame('$.basedOn', $e->getErrors()[0]['path']);
        }
        self::assertSame($before, $session->toJson(), 'Refusal must not change document, history or revision.');
        self::assertSame('Human wording', $session->get()['doc']['nodes']['a']['props']['content']);
        self::assertSame([
            'revision' => $session->get()['revision'], 'complete' => true,
            'changes' => [['handle' => 'node:a', 'change' => 'updated', 'actor' => 'human']],
        ], $session->changesSince($read['revision']));
    }

    public function test_bad_second_op_rolls_back_the_entire_batch_with_index_and_path(): void
    {
        $session = new PageSession(F::doc());
        $snapshot = $session->get();
        $before = $session->toJson();
        try {
            $session->apply([$this->patch('a', 'Must roll back'), ['t' => 'set_style', 'id' => 'b', 'breakpoint' => 'base', 'patch' => ['fontSize' => ['value' => 20, 'unit' => 'evil']]]], 'agent');
            self::fail('Invalid second op must fail.');
        } catch (PageSessionException $e) {
            self::assertSame('invalid_operation', $e->getErrorCode());
            self::assertSame(1, $e->getErrors()[0]['index']);
            self::assertSame('$.ops.1.patch.fontSize.unit', $e->getErrors()[0]['path']);
        }
        self::assertSame($snapshot, $session->get(), 'The live document must roll back as well as the saved log.');
        self::assertSame([], $session->opLog());
        self::assertSame($before, $session->toJson());
    }

    public function test_semantically_inapplicable_later_op_rolls_back_without_logging(): void
    {
        $session = new PageSession(F::doc());
        $snapshot = $session->get();
        $before = $session->toJson();
        try {
            $session->apply([$this->patch('a', 'Rollback'), $this->patch('missing', 'No node')], 'human');
            self::fail('Missing target must fail.');
        } catch (PageSessionException $e) {
            self::assertSame(1, $e->getErrors()[0]['index']);
        }
        self::assertSame($snapshot, $session->get());
        self::assertSame($before, $session->toJson());
    }

    public function test_unknown_revision_is_an_error_instead_of_false_silence(): void
    {
        $session = new PageSession(F::doc());
        try {
            $session->changesSince('never-observed');
            self::fail('Unknown history is not a complete empty feed.');
        } catch (PageSessionException $e) {
            self::assertSame('unknown_revision', $e->getErrorCode());
        }
        $revision = $session->get()['revision'];
        self::assertSame(['revision' => $revision, 'complete' => true, 'changes' => []], $session->changesSince($revision));
        self::assertSame($revision, $session->apply([], 'agent', $revision)['revision']);
    }

    public function test_subtree_deletion_has_tombstones_for_every_removed_handle(): void
    {
        $session = new PageSession(F::doc());
        $revision = $session->get()['revision'];
        $session->apply([['t' => 'remove_node', 'id' => 'a']], 'human');
        self::assertSame([
            ['handle' => 'node:a', 'change' => 'deleted', 'actor' => 'human'],
            ['handle' => 'node:child', 'change' => 'deleted', 'actor' => 'human'],
            ['handle' => 'node:grandchild', 'change' => 'deleted', 'actor' => 'human'],
        ], $session->changesSince($revision)['changes']);
    }

    public function test_created_moved_and_page_changes_use_unambiguous_stable_handles(): void
    {
        $session = new PageSession(F::doc());
        $revision = $session->get()['revision'];
        $session->apply([
            ['t' => 'insert_node', 'node' => F::node('page:page')],
            ['t' => 'move_node', 'id' => 'b', 'parent' => 'a', 'order' => 'X'],
            ['t' => 'set_meta', 'patch' => ['title' => 'Human page']],
            ['t' => 'set_theme', 'patch' => ['color' => '#fff']],
        ], 'human');
        self::assertSame([
            ['handle' => 'node:page%3Apage', 'change' => 'created', 'actor' => 'human'],
            ['handle' => 'node:b', 'change' => 'moved', 'actor' => 'human'],
            ['handle' => 'page:page', 'change' => 'updated', 'actor' => 'human'],
            ['handle' => 'page:page', 'change' => 'updated', 'actor' => 'human'],
        ], $session->changesSince($revision)['changes']);
    }

    public function test_reorder_reports_moved_roots_and_repeated_actors_are_not_coalesced(): void
    {
        $session = new PageSession(F::doc());
        $revision = $session->get()['revision'];
        $session->apply([['t' => 'reorder_roots', 'order' => ['b', 'a']]], 'human');
        $moves = $session->changesSince($revision)['changes'];
        self::assertEqualsCanonicalizing(['node:a', 'node:b'], array_column($moves, 'handle'));
        self::assertSame(['moved', 'moved'], array_column($moves, 'change'));
        $since = $session->get()['revision'];
        $session->apply([$this->patch('a', 'Person')], 'human');
        $session->apply([$this->patch('a', 'Agent')], 'agent');
        self::assertSame(['human', 'agent'], array_column($session->changesSince($since)['changes'], 'actor'));
    }

    public function test_session_round_trip_preserves_cursors_attribution_and_json_object_tags(): void
    {
        $doc = F::doc();
        $doc['nodes']['0'] = F::node('0');
        $doc['nodes']['0']['props']['opaque'] = (object) ['empty' => new \stdClass, 'list' => []];
        $session = new PageSession($doc);
        $revision = $session->get()['revision'];
        $session->apply([$this->patch('a', 'Person')], 'human');
        $mid = $session->get()['revision'];
        $session->apply([$this->patch('b', 'Agent')], 'agent');
        $restored = PageSession::fromJson($session->toJson());
        self::assertSame($session->toJson(), $restored->toJson());
        self::assertSame(PageJson::encodeDocument($session->get()['doc']), PageJson::encodeDocument($restored->get()['doc']));
        self::assertSame($session->changesSince($revision), $restored->changesSince($revision));
        self::assertSame(['agent'], array_column($restored->changesSince($mid)['changes'], 'actor'));
        $restored->apply([$this->patch('a', 'After restore')], 'agent', $restored->get()['revision']);
        self::assertCount(3, $restored->opLog());
    }

    public function test_opaque_json_objects_cannot_mutate_session_through_inputs_or_results(): void
    {
        $object = (object) ['value' => 'original'];
        $doc = F::doc();
        $doc['nodes']['a']['props']['object'] = $object;
        $session = new PageSession($doc);
        $before = $session->toJson();
        $object->value = 'external';
        $snapshot = $session->get();
        $snapshot['doc']['nodes']['a']['props']['object']->value = 'snapshot';
        self::assertSame($before, $session->toJson());
        $payload = (object) ['value' => 'committed'];
        $session->apply([$this->patch('a', $payload)], 'human');
        $before = $session->toJson();
        $payload->value = 'external op';
        $log = $session->opLog();
        $log[0]['op']['patch']['content']->value = 'log';
        self::assertSame($before, $session->toJson());
    }

    #[DataProvider('invalidRequests')]
    public function test_bad_envelopes_are_refused_without_writes(array $ops, string $actor): void
    {
        $session = new PageSession(F::doc());
        $before = $session->toJson();
        try {
            $session->apply($ops, $actor);
            self::fail('Bad envelope must fail.');
        } catch (PageSessionException $e) {
            self::assertNotEmpty($e->getErrors());
        }
        self::assertSame($before, $session->toJson());
    }

    public static function invalidRequests(): array
    {
        return ['actor' => [[], 'operator'], 'map' => [['one' => ['t' => 'set_theme', 'patch' => []]], 'agent'], 'scalar' => [[42], 'agent'], 'too many' => [array_fill(0, 1001, ['t' => 'set_theme', 'patch' => []]), 'human']];
    }

    public function test_independent_sessions_never_accept_each_others_revision(): void
    {
        $a = new PageSession(F::doc());
        $b = new PageSession(F::doc());
        self::assertNotSame($a->get()['revision'], $b->get()['revision']);
        $this->expectException(PageSessionException::class);
        $b->apply([$this->patch('a', 'Cross page')], 'agent', $a->get()['revision']);
    }

    public function test_addon_registry_is_host_supplied_on_create_and_restore(): void
    {
        $doc = F::doc();
        $doc['nodes']['a']['type'] = 'store-card';
        $session = new PageSession($doc, ['text', 'store-card']);
        $session->apply([$this->patch('a', 'Product')], 'agent');
        self::assertSame($session->toJson(), PageSession::fromJson($session->toJson(), ['text', 'store-card'])->toJson());
        $this->expectException(PageSessionException::class);
        PageSession::fromJson($session->toJson());
    }

    public function test_invalid_initial_document_is_refused(): void
    {
        $doc = F::doc();
        $doc['nodes']['a']['parent'] = 'missing';
        $this->expectException(SchemaException::class);
        new PageSession($doc);
    }

    public function test_corrupt_state_and_duplicate_revision_history_are_refused(): void
    {
        $session = new PageSession(F::doc());
        $session->apply([$this->patch('a', 'Edited')], 'human');
        $state = json_decode($session->toJson(), true, 128, JSON_THROW_ON_ERROR);
        $state['log'][0]['revision'] = $state['initialRevision'];
        try {
            PageSession::fromJson(json_encode($state, JSON_THROW_ON_ERROR));
            self::fail('Duplicate revision must fail.');
        } catch (PageSessionException $e) {
            self::assertSame('invalid_state', $e->getErrorCode());
        }
        $this->expectException(PageSessionException::class);
        PageSession::fromJson('{bad json');
    }

    public function test_object_in_place_of_log_list_is_not_a_complete_empty_history(): void
    {
        $state = json_decode((new PageSession(F::doc()))->toJson(), false, 32, JSON_THROW_ON_ERROR);
        $state->log = new \stdClass;
        $this->expectException(PageSessionException::class);
        PageSession::fromJson(json_encode($state, JSON_THROW_ON_ERROR));
    }

    public function test_restore_revalidates_operations_and_does_not_trust_recorded_effects(): void
    {
        $session = new PageSession(F::doc());
        $session->apply([$this->patch('a', 'Edited')], 'human');
        $state = json_decode($session->toJson(), true, 32, JSON_THROW_ON_ERROR);
        $state['log'][0]['op'] = PageJson::encodeOp($this->patch('missing', 'Bogus target'));
        $this->expectException(PageSessionException::class);
        PageSession::fromJson(json_encode($state, JSON_THROW_ON_ERROR));
    }

    public function test_invalid_result_document_also_rolls_back_the_batch(): void
    {
        $session = new PageSession(F::doc());
        $snapshot = $session->get();
        $before = $session->toJson();
        try {
            $session->apply([$this->patch('a', 'Rollback'), ['t' => 'set_meta', 'patch' => ['title' => null]]], 'agent');
            self::fail('Post-op document validation must be atomic too.');
        } catch (PageSessionException $e) {
            self::assertSame(1, $e->getErrors()[0]['index']);
            self::assertStringContainsString('.meta.title', $e->getErrors()[0]['path']);
        }
        self::assertSame($snapshot, $session->get());
        self::assertSame($before, $session->toJson());
    }

    public function test_reloading_the_persisted_human_state_refuses_the_agents_old_cursor(): void
    {
        $original = new PageSession(F::doc());
        $agentRead = $original->get();
        $humanRequest = PageSession::fromJson($original->toJson());
        $humanRequest->apply([$this->patch('a', 'Persisted human edit')], 'human');
        $agentRequest = PageSession::fromJson($humanRequest->toJson());
        try {
            $agentRequest->apply([$this->patch('a', 'Stale agent')], 'agent', $agentRead['revision']);
            self::fail('Reloaded state must retain optimistic concurrency.');
        } catch (PageSessionException $e) {
            self::assertSame('surface_changed_under_you', $e->getErrorCode());
        }
        self::assertSame(['human'], array_column($agentRequest->changesSince($agentRead['revision'])['changes'], 'actor'));
    }

    public function test_history_limit_refuses_without_pruning_or_changing_the_page(): void
    {
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
            self::fail('Full history must refuse, not silently prune.');
        } catch (PageSessionException $e) {
            self::assertSame('history_limit', $e->getErrorCode());
        }
        self::assertSame($before, $session->get());
        self::assertCount(10000, $session->opLog());
        self::assertTrue($session->changesSince($initial)['complete']);
    }

    public function test_feed_handles_preserve_ids_that_a_consumer_would_otherwise_trim(): void
    {
        $doc = F::doc();
        $doc['id'] = ' page ';
        $doc['nodes'] = ['0' => F::node('0'), ' spaced ' => F::node(' spaced '), 'café:id' => F::node('café:id')];
        $session = new PageSession($doc);
        $revision = $session->get()['revision'];
        $ops = [];
        foreach ($doc['nodes'] as $node) {
            $ops[] = $this->patch($node['id'], 'Edited');
        }
        $ops[] = ['t' => 'set_meta', 'patch' => ['title' => 'Edited']];
        $session->apply($ops, 'human');
        self::assertSame(['node:0', 'node:%20spaced%20', 'node:caf%C3%A9%3Aid', 'page:%20page%20'], array_column($session->changesSince($revision)['changes'], 'handle'));
    }
}
