<?php

namespace Tests\Feature;

use App\Contracts\Ai\CommercialEntitlements;
use App\Contracts\Ai\EmbeddingProvider;
use App\Contracts\Ai\ExternalProcessingApprovals;
use App\Contracts\Ai\TenantSettingSource;
use App\Contracts\Ai\UsageQuotaReserver;
use App\Contracts\Ai\VectorStore;
use App\Exceptions\AiEmbeddingException;
use App\Exceptions\AiKnowledgeIngestionException;
use App\Providers\AppServiceProvider;
use App\Services\Ai\ControlledEmbeddingRecovery;
use App\Services\Ai\QdrantVectorStore;
use App\Services\Ai\SettingBackedExternalProcessingApprovals;
use App\Services\Ai\UnavailableEmbeddingProvider;
use App\Services\AiEmbeddingService;
use App\Services\AiKnowledgeIngestionService;
use App\Services\MediaReadService;
use App\Support\TenantContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use Tests\Support\Ai\FakeCommercialEntitlements;
use Tests\Support\Ai\FakeEmbeddingProvider;
use Tests\Support\Ai\FakeTenantSettings;
use Tests\Support\Ai\FakeUsageQuotaReserver;
use Tests\Support\Ai\FakeVectorStore;
use Tests\TestCase;

class AiEmbeddingServiceTest extends TestCase
{
    use RefreshDatabase;

    /** Every status `ai_embeddings` is allowed to hold. `processing` is not one. */
    private const LIFECYCLE = ['pending', 'ready', 'failed', 'stale', 'deletion_pending', 'deleted'];

    private FakeTenantSettings $settings;

    private FakeCommercialEntitlements $entitlements;

    private FakeUsageQuotaReserver $quota;

    private FakeVectorStore $store;

    private FakeEmbeddingProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai.providers', [
            'approved-provider' => [
                'managed' => false,
                'models' => ['approved-model'],
                'purposes' => ['knowledge_embedding'],
                'regions' => ['lf_managed'],
                'retention_classes' => ['none', 'transient'],
                'data_classes' => ['derived_text'],
            ],
        ]);
        config()->set('ai.embedding.provider', 'approved-provider');
        config()->set('ai.embedding.model', 'approved-model');
        config()->set('ai.embedding.dimensions', 3);
        config()->set('ai.embedding.retention_class', 'transient');
        config()->set('ai.vector_store.host', 'http://qdrant.test');

        $this->settings = new FakeTenantSettings;
        $this->entitlements = new FakeCommercialEntitlements;
        $this->quota = new FakeUsageQuotaReserver(100.0);
        $this->store = new FakeVectorStore;
        $this->provider = new FakeEmbeddingProvider;

        $this->app->instance(TenantSettingSource::class, $this->settings);
        $this->app->bind(ExternalProcessingApprovals::class, SettingBackedExternalProcessingApprovals::class);
        $this->app->instance(CommercialEntitlements::class, $this->entitlements);
        $this->app->instance(UsageQuotaReserver::class, $this->quota);
        $this->app->instance(VectorStore::class, $this->store);
        $this->app->instance(EmbeddingProvider::class, $this->provider);
    }

    public function test_controlled_recovery_preserves_quota_and_history_until_purge_then_uses_new_generation(): void
    {
        [$customer, $actor, , , $chunks] = $this->fixture('controlled-recovery');
        $this->approve($customer);
        $run = $this->modelRun($customer, 'running');
        $uuid = DB::table('ai_model_runs')->where('id', $run)->value('run_uuid');
        DB::table('ai_model_runs')->where('id', $run)->update(['metadata' => json_encode(['original' => 'keep'])]);
        $ids = array_map(fn ($chunk) => $this->pendingEmbedding($customer, $chunk, $run), $chunks);
        $hold = $this->quota->reserve($customer, $run, $uuid, 'ai_knowledge_embedding', 'provider_call', 3, 'call');
        $this->quota->markExecuting($hold);
        $beforeQuota = $this->quota->reservations;
        $beforeRows = DB::table('ai_embeddings')->whereIn('id', $ids)->get()->keyBy('id');
        $recovery = new ControlledEmbeddingRecovery;
        $result = $recovery->recover($actor, $uuid, 'INC-123', true, true);
        $this->assertSame(3, $result['deletion_requested']);
        $this->assertSame($beforeQuota, $this->quota->reservations);
        $this->assertSame(0, $this->quota->releaseCalls);
        $this->assertSame([], $this->provider->calls);
        $this->assertSame(0, $this->store->deleteCalls);
        $saved = DB::table('ai_model_runs')->where('id', $run)->first();
        $audit = json_decode($saved->metadata, true);
        $this->assertSame('cancelled', $saved->status);
        $this->assertSame('keep', $audit['original']);
        $this->assertSame($actor, $audit['controlled_recovery']['actor_id']);
        $this->assertSame('INC-123', $audit['controlled_recovery']['evidence_reference']);
        $this->assertSame(0, $this->service()->embedPending()['embedded']);
        $this->assertTrue($recovery->recover($actor, $uuid, 'INC-456', true, true)['already_recovered']);
        $this->assertSame($saved->metadata, DB::table('ai_model_runs')->where('id', $run)->value('metadata'));
        $this->store->acknowledgeDeletes = false;
        $this->assertSame(3, $this->service()->purgeDeletionPending()['retained']);
        $this->assertSame(0, $this->service()->embedPending()['embedded']);
        $this->store->acknowledgeDeletes = true;
        $this->assertSame(3, $this->service()->purgeDeletionPending()['deleted']);
        $this->assertSame(3, $this->service()->embedPending()['embedded']);
        foreach ($ids as $id) {
            $old = DB::table('ai_embeddings')->where('id', $id)->first();
            $this->assertSame('deleted', $old->status);
            $this->assertSame($run, (int) $old->model_run_id);
            $this->assertSame($beforeRows[$id]->vector_key, $old->vector_key);
            $new = DB::table('ai_embeddings')->where('knowledge_chunk_id', $old->knowledge_chunk_id)->where('generation', 2)->first();
            $this->assertSame('ready', $new->status);
            $this->assertNotSame($old->vector_key, $new->vector_key);
        }
        $this->assertSame($beforeQuota[$hold->reservationId], $this->quota->reservations[$hold->reservationId]);
    }

    public function test_controlled_recovery_rolls_back_run_and_embeddings_on_write_failure(): void
    {
        [$customer, $actor, , , $chunks] = $this->fixture('recovery-rollback');
        $run = $this->modelRun($customer, 'running');
        $uuid = DB::table('ai_model_runs')->where('id', $run)->value('run_uuid');
        $embedding = $this->pendingEmbedding($customer, $chunks[0], $run);
        $armed = true;
        DB::listen(function (QueryExecuted $query) use (&$armed): void {
            if ($armed && str_starts_with(strtolower($query->sql), 'update')
                && str_contains($query->sql, 'ai_embeddings')) {
                $armed = false;
                throw new \RuntimeException('simulated recovery storage failure');
            }
        });
        try {
            (new ControlledEmbeddingRecovery)->recover($actor, $uuid, 'INC-rollback', true, true);
            $this->fail('The injected write failure must roll back recovery.');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated recovery storage failure', $e->getMessage());
        }
        $this->assertFalse($armed);
        $saved = DB::table('ai_model_runs')->where('id', $run)->first();
        $this->assertSame('running', $saved->status);
        $this->assertNull($saved->completed_at);
        $this->assertArrayNotHasKey('controlled_recovery', json_decode($saved->metadata ?? '{}', true));
        $row = DB::table('ai_embeddings')->where('id', $embedding)->first();
        $this->assertSame('pending', $row->status);
        $this->assertNull($row->deletion_requested_at);
    }

    public function test_controlled_recovery_refuses_missing_confirmations_wrong_actor_and_nonrunning_states(): void
    {
        [$customer, $actor] = $this->fixture('recovery-negative');
        $recovery = new ControlledEmbeddingRecovery;
        $run = $this->modelRun($customer, 'running');
        $uuid = DB::table('ai_model_runs')->where('id', $run)->value('run_uuid');
        foreach ([[false, true], [true, false]] as [$stopped, $drained]) {
            try {
                $recovery->recover($actor, $uuid, 'INC-123', $stopped, $drained);
                $this->fail('Both confirmations are mandatory.');
            } catch (AiEmbeddingException $e) {
                $this->assertSame('LF_RECOVERY_CONFIRMATION_REQUIRED', $e->errorCode);
            }
        }
        DB::table('users')->where('id', $actor)->update(['role' => 'teacher']);
        try {
            $recovery->recover($actor, $uuid, 'INC-123', true, true);
            $this->fail('Teacher must not recover runs.');
        } catch (AiEmbeddingException $e) {
            $this->assertSame('unauthorized', $e->errorCode);
        }
        DB::table('users')->where('id', $actor)->update(['role' => 'customer_admin']);
        foreach (['queued', 'completed', 'failed', 'blocked', 'cancelled'] as $status) {
            $other = $this->modelRun($customer, $status);
            try {
                $recovery->recover($actor, DB::table('ai_model_runs')->where('id', $other)->value('run_uuid'), 'INC-123', true, true);
                $this->fail('Nonrunning state cannot be recovered.');
            } catch (AiEmbeddingException $e) {
                $this->assertSame('AI_RUN_TRANSITION_CONFLICT', $e->errorCode);
            }
        }
        [$otherCustomer, $otherActor] = $this->fixture('recovery-other');
        try {
            $recovery->recover($otherActor, $uuid, 'INC-123', true, true);
            $this->fail('Run from another tenant must not resolve.');
        } catch (AiEmbeddingException $e) {
            $this->assertSame('LF_RECOVERY_RUN_NOT_FOUND', $e->errorCode);
        }
        $this->assertSame('running', DB::table('ai_model_runs')->where('id', $run)->value('status'));
    }

    public function test_controlled_recovery_command_requires_confirmation_and_restores_tenant_context(): void
    {
        [$customer, $actor] = $this->fixture('recovery-cli');
        $run = $this->modelRun($customer, 'running');
        $previous = (object) ['id' => 999999];
        TenantContext::set($previous);
        $options = ['--customer' => $customer, '--actor' => $actor,
            '--run-uuid' => DB::table('ai_model_runs')->where('id', $run)->value('run_uuid'), '--evidence' => 'INC-123'];
        $this->artisan('ai:embedding-recover', $options)->assertFailed();
        $this->assertSame($previous, TenantContext::customer());
        $this->assertSame('running', DB::table('ai_model_runs')->where('id', $run)->value('status'));
        $this->artisan('ai:embedding-recover', $options + ['--confirm-writer-stopped' => true, '--confirm-store-quiesced' => true])->assertSuccessful();
        $this->assertSame($previous, TenantContext::customer());
        $this->assertSame('cancelled', DB::table('ai_model_runs')->where('id', $run)->value('status'));
    }

    public function test_unconfigured_worker_writes_nothing_and_calls_nothing(): void
    {
        [$customerId] = $this->fixture('unconfigured');
        config()->set('ai.embedding.model', '');

        $outcome = $this->service()->embedPending();

        $this->assertSame('LF_EMBEDDING_NOT_CONFIGURED', $outcome['error_code']);
        $this->assertSame(0, DB::table('ai_embeddings')->where('customer_id', $customerId)->count());
        $this->assertSame(0, DB::table('ai_model_runs')->where('customer_id', $customerId)->count());
        $this->assertSame([], $this->provider->calls);
    }

    public function test_shipped_bindings_refuse_without_any_test_double(): void
    {
        // The real container bindings and the real config defaults: no
        // provider is approved and no store host is set, so a deployment that
        // merely published the config indexes nothing.
        $this->app->forgetInstance(VectorStore::class);
        $this->app->forgetInstance(EmbeddingProvider::class);
        (new AppServiceProvider($this->app))->register();
        config()->set('ai.vector_store.host', null);
        config()->set('ai.providers', []);
        [$customerId] = $this->fixture('shipped');

        $outcome = $this->service()->embedPending();

        $this->assertSame('LF_VECTOR_STORE_UNAVAILABLE', $outcome['error_code']);
        $this->assertSame(0, DB::table('ai_embeddings')->where('customer_id', $customerId)->count());
        $this->assertInstanceOf(
            UnavailableEmbeddingProvider::class,
            $this->app->make(EmbeddingProvider::class),
        );
        $this->assertFalse($this->app->make(VectorStore::class)->isConfigured());
    }

    public function test_unconfigured_store_stops_before_the_provider_is_asked(): void
    {
        [$customerId] = $this->fixture('no-store');
        $this->approve($customerId);
        $this->store->configured = false;

        $outcome = $this->service()->embedPending();

        // Spending a provider call to produce a vector with nowhere to put it
        // costs quota and yields nothing.
        $this->assertSame('LF_VECTOR_STORE_UNAVAILABLE', $outcome['error_code']);
        $this->assertSame([], $this->provider->calls);
        $this->assertSame(0, DB::table('ai_embeddings')->where('customer_id', $customerId)->count());
    }

    public function test_gate_refusal_leaves_no_embedding_row_and_no_side_effect(): void
    {
        [$customerId] = $this->fixture('unapproved');
        // Deliberately no tenant approval.

        $outcome = $this->service()->embedPending();

        $this->assertSame('AI_APPROVAL_REQUIRED', $outcome['error_code']);
        $this->assertSame('tenant_approval', $outcome['blocked_at']);
        $this->assertSame([], $this->provider->calls);
        $this->assertSame([], $this->store->points);
        $this->assertSame(0, DB::table('ai_embeddings')->where('customer_id', $customerId)->count());
        // The refusal is still audited.
        $this->assertSame('blocked', DB::table('ai_model_runs')->where('customer_id', $customerId)->value('status'));
    }

    public function test_successful_pass_marks_rows_ready_and_indexes_every_chunk(): void
    {
        [$customerId, , , $sourceId, $chunkIds] = $this->fixture('happy');
        $this->approve($customerId);

        $outcome = $this->service()->embedPending();

        $this->assertNull($outcome['error_code']);
        $this->assertSame(count($chunkIds), $outcome['embedded']);
        $this->assertCount(count($chunkIds), $this->store->points);

        $rows = DB::table('ai_embeddings')->where('customer_id', $customerId)->get();
        $this->assertCount(count($chunkIds), $rows);
        foreach ($rows as $row) {
            $this->assertSame('ready', $row->status);
            $this->assertNotNull($row->embedded_at);
            $this->assertSame('qdrant', $row->vector_store);
            $this->assertSame(3, (int) $row->dimensions);
            $this->assertSame(
                'completed',
                DB::table('ai_model_runs')->where('id', $row->model_run_id)->value('status')
            );
        }
        $this->assertSame($sourceId, (int) DB::table('ai_knowledge_chunks')
            ->where('id', $chunkIds[0])->value('knowledge_source_id'));
    }

    public function test_point_payload_carries_identity_only(): void
    {
        [$customerId, , , , $chunkIds] = $this->fixture('payload');
        $this->approve($customerId);
        $text = (string) DB::table('ai_knowledge_chunks')->where('id', $chunkIds[0])->value('content');

        $this->service()->embedPending();

        foreach ($this->store->points as $point) {
            $this->assertSame(
                ['customer_id', 'is_tenant', 'knowledge_chunk_id', 'knowledge_source_id', 'source_fingerprint', 'processing_version'],
                array_keys($point['payload']),
            );
            $this->assertSame($customerId, $point['payload']['customer_id']);
            // The index is outside relational retention and deletion. Text put
            // there escapes both.
            $this->assertStringNotContainsString($text, json_encode($point['payload']));
        }
    }

    public function test_second_pass_is_idempotent(): void
    {
        [$customerId, , , , $chunkIds] = $this->fixture('idempotent');
        $this->approve($customerId);

        $this->service()->embedPending();
        $outcome = $this->service()->embedPending();

        $this->assertSame(0, $outcome['embedded']);
        $this->assertCount(1, $this->provider->calls);
        $this->assertSame(count($chunkIds), DB::table('ai_embeddings')->where('customer_id', $customerId)->count());
    }

    public function test_completed_prefix_does_not_starve_later_batches(): void
    {
        [$customerId] = $this->fixture('pagination');
        $this->approve($customerId);
        config(['ai.embedding.chunk_batch' => 1]);
        $this->assertSame(1, $this->service()->embedPending()['embedded']);
        $this->assertSame(1, $this->service()->embedPending()['embedded']);
        $this->assertSame(1, $this->service()->embedPending()['embedded']);
        $this->assertSame(0, $this->service()->embedPending()['embedded']);
    }

    public function test_dimension_mismatch_never_reaches_the_index(): void
    {
        [$customerId] = $this->fixture('width');
        $this->approve($customerId);
        $this->app->instance(EmbeddingProvider::class, new FakeEmbeddingProvider(returnDimensions: 5));

        $outcome = $this->service()->embedPending();

        $this->assertSame('AI_PROVIDER_CALL_FAILED', $outcome['error_code']);
        $this->assertSame([], $this->store->points);
        $this->assertSame(3, DB::table('ai_embeddings')->where('customer_id', $customerId)
            ->where('status', 'failed')->count());
    }

    public function test_non_numeric_and_non_finite_vectors_never_reach_the_store(): void
    {
        foreach (['not-a-number', INF, NAN] as $index => $value) {
            [$customerId] = $this->fixture('invalid-vector-'.$index);
            $this->approve($customerId);
            $provider = Mockery::mock(EmbeddingProvider::class);
            $provider->shouldReceive('provider')->andReturn('approved-provider');
            $provider->shouldReceive('supportsModel')->andReturn(true);
            $provider->shouldReceive('embed')->andReturn([[0.1, 0.2, 0.3], [$value, 0.2, 0.3], [0.1, 0.2, 0.3]]);
            $this->app->instance(EmbeddingProvider::class, $provider);
            $this->assertSame('AI_PROVIDER_CALL_FAILED', $this->service()->embedPending()['error_code']);
            $this->assertSame([], $this->store->points);
        }
    }

    public function test_misaligned_provider_response_is_refused(): void
    {
        [$customerId] = $this->fixture('misaligned');
        $this->approve($customerId);
        // One vector short: positional alignment would attach chunk 1's vector
        // to chunk 2's identity, and nothing downstream could detect it.
        $this->app->instance(EmbeddingProvider::class, new FakeEmbeddingProvider(returnCount: 1));

        $outcome = $this->service()->embedPending();

        $this->assertSame('AI_PROVIDER_CALL_FAILED', $outcome['error_code']);
        $this->assertSame([], $this->store->points);
        $this->assertSame(3, DB::table('ai_embeddings')->where('customer_id', $customerId)
            ->where('status', 'failed')->count());
    }

    public function test_partial_store_failure_queues_the_points_that_landed_for_purge(): void
    {
        [$customerId] = $this->fixture('partial');
        $this->approve($customerId);
        $this->store->failUpsertAt = 2;

        $outcome = $this->service()->embedPending();

        $this->assertSame('AI_PROVIDER_CALL_FAILED', $outcome['error_code']);
        $this->assertSame(1, $outcome['orphaned']);
        $this->assertCount(1, $this->store->points);
        // The one that landed is queued for removal: its only audit record says
        // the attempt failed, so it must not stay in the index as `ready`.
        $this->assertSame(1, DB::table('ai_embeddings')->where('customer_id', $customerId)
            ->where('status', 'deletion_pending')->whereNotNull('deletion_requested_at')->count());
        $this->assertSame(2, DB::table('ai_embeddings')->where('customer_id', $customerId)
            ->where('status', 'failed')->count());
    }

    public function test_failed_rows_are_retried_by_a_later_pass(): void
    {
        [$customerId, , , , $chunkIds] = $this->fixture('retry');
        $this->approve($customerId);
        $this->store->failUpsertAt = 1;
        $this->service()->embedPending();
        $this->assertSame(count($chunkIds), DB::table('ai_embeddings')->where('customer_id', $customerId)
            ->where('status', 'failed')->count());

        $this->store->failUpsertAt = null;
        $outcome = $this->service()->embedPending();

        // `failed → pending` — Amendment 2026-09-10. Without it a transient
        // provider error would strand the chunk forever: `uk_aem_chunk_model`
        // pins the identity, and a tombstone would pin it just as hard.
        $this->assertNull($outcome['error_code']);
        $this->assertSame(count($chunkIds), $outcome['embedded']);
        $this->assertSame(0, $outcome['stranded']);
        $this->assertCount(count($chunkIds), $this->store->points);
        // Re-attempted in place: the identity never changed, so neither did
        // the row count.
        $this->assertSame(count($chunkIds), DB::table('ai_embeddings')->where('customer_id', $customerId)->count());
    }

    public function test_a_retry_keeps_the_failed_attempt_as_evidence(): void
    {
        [$customerId, , , , $chunkIds] = $this->fixture('retry-audit');
        $this->approve($customerId);
        $this->store->failUpsertAt = 1;
        $this->service()->embedPending();
        $failedRunId = (int) DB::table('ai_model_runs')->where('customer_id', $customerId)->value('id');

        $this->store->failUpsertAt = null;
        $this->service()->embedPending();

        // Reusing the embedding row costs no audit: the failure keeps its own
        // immutable run, and the row now points at the attempt that succeeded.
        // That is what makes the row a current-state record rather than an
        // attempt log — the log is `ai_model_runs`.
        $this->assertSame('failed', DB::table('ai_model_runs')->where('id', $failedRunId)->value('status'));
        $this->assertSame('AI_PROVIDER_CALL_FAILED', DB::table('ai_model_runs')
            ->where('id', $failedRunId)->value('error_code'));
        $this->assertSame(2, DB::table('ai_model_runs')->where('customer_id', $customerId)->count());
        $this->assertSame(0, DB::table('ai_embeddings')->where('customer_id', $customerId)
            ->where('model_run_id', $failedRunId)->count());
        $this->assertSame(count($chunkIds), DB::table('ai_embeddings')->where('customer_id', $customerId)
            ->where('status', 'ready')->count());
    }

    public function test_superseded_identity_gets_new_generation_without_rewriting_history(): void
    {
        [$customerId, , , , $chunkIds] = $this->fixture('blocked-identity');
        $this->approve($customerId);
        $this->service()->embedPending();
        $old = DB::table('ai_embeddings')->where('customer_id', $customerId)
            ->where('knowledge_chunk_id', $chunkIds[0])->first();
        $this->service()->markStale([$chunkIds[0]]);

        $outcome = $this->service()->embedPending();

        $this->assertSame(0, $outcome['stranded']);
        $this->assertSame(1, $outcome['embedded']);
        $this->assertSame('stale', DB::table('ai_embeddings')->where('customer_id', $customerId)
            ->where('knowledge_chunk_id', $chunkIds[0])->value('status'));
        $new = DB::table('ai_embeddings')->where('customer_id', $customerId)
            ->where('knowledge_chunk_id', $chunkIds[0])->orderByDesc('generation')->first();
        $this->assertSame(2, (int) $new->generation);
        $this->assertNotSame($old->vector_key, $new->vector_key);
        $this->assertSame($old->model_run_id, DB::table('ai_embeddings')->where('id', $old->id)->value('model_run_id'));
        $this->assertSame(0, $this->service()->embedPending()['embedded']);
    }

    public function test_deleted_generation_and_its_vector_key_are_never_reused(): void
    {
        [$customerId, , , , $chunkIds] = $this->fixture('deleted-generation');
        $this->approve($customerId);
        $this->service()->embedPending();
        $old = DB::table('ai_embeddings')->where('knowledge_chunk_id', $chunkIds[0])->first();
        $this->service()->requestDeletion([$chunkIds[0]]);
        $this->assertSame(0, $this->service()->embedPending()['embedded']);
        $this->service()->purgeDeletionPending();
        $this->assertSame(1, $this->service()->embedPending()['embedded']);
        $new = DB::table('ai_embeddings')->where('knowledge_chunk_id', $chunkIds[0])->orderByDesc('generation')->first();
        $this->assertSame('deleted', DB::table('ai_embeddings')->where('id', $old->id)->value('status'));
        $this->assertNotSame($old->vector_key, $new->vector_key);
        $this->assertSame(2, (int) $new->generation);
        $migration = require database_path('migrations/2026_09_13_000100_add_ai_embedding_generation.php');
        try {
            $migration->down();
            $this->fail('Rollback must preserve generation history.');
        } catch (\RuntimeException $e) {
            $this->assertSame('LF_EMBEDDING_GENERATION_ROLLBACK_REFUSED', $e->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('ai_embeddings', 'generation'));
    }

    public function test_generation_constraints_are_physically_enforced_on_mariadb(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Physical CHECK requires MariaDB.');
        }
        [$customerId] = $this->fixture('physical-generation');
        $this->approve($customerId);
        $this->service()->embedPending();
        $row = (array) DB::table('ai_embeddings')->where('customer_id', $customerId)->first();
        unset($row['id']);
        $row['vector_key'] = (string) Str::uuid();
        foreach ([0, 1] as $generation) {
            $row['generation'] = $generation;
            $error = null;
            try {
                DB::table('ai_embeddings')->insert($row);
            } catch (QueryException $e) {
                $error = $e->errorInfo[1];
            }
            $this->assertSame($generation === 0 ? 4025 : 1062, $error);
        }
        $row['generation'] = 2;
        DB::table('ai_embeddings')->insert($row);
        $this->assertSame(4, DB::table('ai_embeddings')->where('customer_id', $customerId)->count());
    }

    public function test_pending_rows_under_a_live_run_are_not_claimed_again(): void
    {
        [$customerId, , , , $chunkIds] = $this->fixture('live-run');
        $this->approve($customerId);
        $runId = $this->modelRun($customerId, 'queued');
        $this->pendingEmbedding($customerId, $chunkIds[0], $runId);

        $this->service()->embedPending();

        // Two chunks embedded, not three: the third belongs to a run that could
        // still be mid-flight.
        $this->assertSame(count($chunkIds) - 1, $this->provider->calls[0]['count']);
        $this->assertCount(count($chunkIds) - 1, $this->store->points);
        $this->assertSame($runId, (int) DB::table('ai_embeddings')->where('customer_id', $customerId)
            ->where('knowledge_chunk_id', $chunkIds[0])->value('model_run_id'));
    }

    public function test_abandoned_queued_runs_are_cancelled_and_their_rows_freed(): void
    {
        [$customerId, , , , $chunkIds] = $this->fixture('abandoned');
        $this->approve($customerId);
        $runId = $this->modelRun($customerId, 'queued', now()->subHours(2));
        $this->pendingEmbedding($customerId, $chunkIds[0], $runId);

        $this->service()->embedPending();

        // The gate moves a run to `running` before it builds an adapter, so a
        // run still `queued` provably never reached a provider.
        $this->assertSame('cancelled', DB::table('ai_model_runs')->where('id', $runId)->value('status'));
        $this->assertSame(count($chunkIds), $this->provider->calls[0]['count']);
    }

    public function test_running_runs_are_never_reaped(): void
    {
        [$customerId, , , , $chunkIds] = $this->fixture('running');
        $this->approve($customerId);
        $runId = $this->modelRun($customerId, 'running', now()->subHours(2));
        $this->pendingEmbedding($customerId, $chunkIds[0], $runId);

        $this->service()->embedPending();

        // A `running` run may have reached the provider. Only provider-aware
        // reconciliation can decide what happened to it.
        $this->assertSame('running', DB::table('ai_model_runs')->where('id', $runId)->value('status'));
        $this->assertSame('pending', DB::table('ai_embeddings')->where('customer_id', $customerId)
            ->where('knowledge_chunk_id', $chunkIds[0])->value('status'));
    }

    public function test_only_documented_statuses_are_ever_written(): void
    {
        [$customerId, , , , $chunkIds] = $this->fixture('vocabulary');
        $this->approve($customerId);
        $service = $this->service();

        $service->embedPending();
        $service->markStale([$chunkIds[0]]);
        $service->requestDeletion([$chunkIds[1]]);
        $service->purgeDeletionPending();

        $seen = DB::table('ai_embeddings')->where('customer_id', $customerId)->distinct()->pluck('status')->all();
        $this->assertNotEmpty($seen);
        foreach ($seen as $status) {
            $this->assertContains($status, self::LIFECYCLE);
        }
        $this->assertNotContains('processing', $seen);
    }

    public function test_stale_marks_supersede_without_claiming_the_point_is_gone(): void
    {
        [$customerId, , , , $chunkIds] = $this->fixture('stale');
        $this->approve($customerId);
        $this->service()->embedPending();

        $this->assertSame(1, $this->service()->markStale([$chunkIds[0]]));

        $this->assertSame('stale', DB::table('ai_embeddings')->where('customer_id', $customerId)
            ->where('knowledge_chunk_id', $chunkIds[0])->value('status'));
        // Still indexed: `stale` says "no longer eligible", not "removed".
        $this->assertCount(count($chunkIds), $this->store->points);
    }

    public function test_a_refusal_on_the_second_authorization_fails_claimed_rows_for_retry(): void
    {
        [$customerId] = $this->fixture('second-authorize-refused');
        $this->approve($customerId);
        // The gate authorizes twice: once so the worker can bind rows to a run,
        // again inside execute(). Withdraw approval between the two — from
        // inside the first reservation, after its approval check has passed.
        $this->quota->onReserve(fn () => $this->settings->revoke(
            $customerId, 'ai.external_processing.approved-provider.knowledge_embedding',
        ));

        $outcome = $this->service()->embedPending();

        // Previously: error_code null, three rows stuck `pending` under a
        // blocked run, and no later pass could ever reclaim them.
        $this->assertSame(100.0, $this->quota->balance());
        $this->assertSame(1, $this->quota->releaseCalls);
        $this->assertSame('AI_APPROVAL_REQUIRED', $outcome['error_code']);
        $this->assertSame('tenant_approval', $outcome['blocked_at']);
        $this->assertSame(0, $outcome['embedded']);
        $this->assertSame(3, $outcome['failed']);
        $this->assertSame([], $this->provider->calls);
        $this->assertSame([], $this->store->points);
        $this->assertSame(3, DB::table('ai_embeddings')->where('customer_id', $customerId)
            ->where('status', 'failed')->where('last_error_code', 'AI_APPROVAL_REQUIRED')->count());
        $this->assertSame(0, DB::table('ai_embeddings')->where('customer_id', $customerId)
            ->where('status', 'pending')->count());
        $this->assertSame(['blocked'], DB::table('ai_model_runs')->where('customer_id', $customerId)
            ->distinct()->pluck('status')->all());

        // Once the cause clears, the canonical `failed → pending` retry takes over.
        $this->approve($customerId);
        $retry = $this->service()->embedPending();

        $this->assertNull($retry['error_code']);
        $this->assertSame(3, $retry['embedded']);
        $this->assertCount(1, $this->provider->calls);
        $this->assertSame(3, DB::table('ai_embeddings')->where('customer_id', $customerId)
            ->where('status', 'ready')->count());
    }

    public function test_one_embedding_pass_holds_and_settles_exactly_one_reservation(): void
    {
        [$customerId, , , , $chunkIds] = $this->fixture('single-hold');
        $this->approve($customerId);

        $this->service()->embedPending();

        // The gate authorizes twice per execution. With an idempotent reserver
        // that must still be ONE hold, settled once at the true quantity — the
        // property the non-idempotent fake could not express (review AR-P3-5).
        $runUuid = (string) DB::table('ai_model_runs')->where('customer_id', $customerId)->value('run_uuid');
        $this->assertCount(1, $this->quota->reservations);
        $this->assertNotNull($this->quota->attemptReservation(
            $customerId, $runUuid, 'ai_knowledge_embedding', 'provider_call', 'call',
        ));
        $this->assertSame('committed', array_values($this->quota->reservations)[0]['status']);
        $this->assertSame(100.0 - count($chunkIds), $this->quota->balance());
    }

    public function test_a_second_authorization_refusal_releases_the_single_hold(): void
    {
        [$customerId] = $this->fixture('refusal-releases-hold');
        $this->approve($customerId);
        $this->quota->onReserve(fn () => $this->settings->revoke(
            $customerId, 'ai.external_processing.approved-provider.knowledge_embedding',
        ));

        $outcome = $this->service()->embedPending();

        // Nothing crossed the provider boundary, so the tenant is not charged:
        // the one hold is handed back, not left `reserved` until lease expiry.
        $this->assertSame('AI_APPROVAL_REQUIRED', $outcome['error_code']);
        $this->assertCount(1, $this->quota->reservations);
        $this->assertSame('released', array_values($this->quota->reservations)[0]['status']);
        $this->assertSame(100.0, $this->quota->balance());
        $this->assertSame([], $this->provider->calls);
    }

    public function test_an_adapter_whose_provider_does_not_support_the_approved_model_is_never_called(): void
    {
        [$customerId] = $this->fixture('adapter-model-pin');
        // Allow-listed, so the gate reaches the adapter check rather than
        // refusing earlier; but the provider itself cannot serve this model.
        config()->set('ai.providers.approved-provider.models', ['approved-model', 'unlisted-by-provider']);
        config()->set('ai.embedding.model', 'unlisted-by-provider');
        $this->approve($customerId);

        $outcome = $this->service()->embedPending();

        // EmbeddingProviderAdapter::supportsModel() must delegate. A constant
        // `true` there let every embedding test stay green (review AR-P3-4a).
        $this->assertSame('AI_ADAPTER_MISMATCH', $outcome['error_code']);
        $this->assertSame([], $this->provider->calls);
        $this->assertSame([], $this->store->points);
    }

    public function test_an_adapter_for_a_different_provider_is_never_called(): void
    {
        [$customerId] = $this->fixture('adapter-provider-pin');
        $this->approve($customerId);
        $impostor = new FakeEmbeddingProvider(name: 'other-provider');
        $this->app->instance(EmbeddingProvider::class, $impostor);

        $outcome = $this->service()->embedPending();

        // EmbeddingProviderAdapter::provider() must report the provider it
        // wraps. Returning the approved name instead would let a different
        // vendor pass an approval granted to another one (review AR-P3-4a).
        $this->assertSame('AI_ADAPTER_MISMATCH', $outcome['error_code']);
        $this->assertSame([], $impostor->calls);
        $this->assertSame([], $this->store->points);
    }

    public function test_pending_rows_under_a_blocked_run_are_reclaimed(): void
    {
        [$customerId, , , , $chunkIds] = $this->fixture('blocked-run-reclaim');
        $this->approve($customerId);
        // The crash window: a process died after the second authorization
        // refused but before it could move its rows to `failed`. A blocked run
        // never ran (the recorder has no `running → blocked`), so no provider
        // saw these rows and they are free to reclaim.
        $blockedRun = $this->modelRun($customerId, 'blocked');
        $this->pendingEmbedding($customerId, $chunkIds[0], $blockedRun);

        $outcome = $this->service()->embedPending();

        $this->assertNull($outcome['error_code']);
        $this->assertSame(count($chunkIds), $this->provider->calls[0]['count']);
        $row = DB::table('ai_embeddings')->where('customer_id', $customerId)
            ->where('knowledge_chunk_id', $chunkIds[0])->first();
        $this->assertSame('ready', $row->status);
        $this->assertNotSame($blockedRun, (int) $row->model_run_id);
    }

    public function test_purge_is_not_starved_by_rows_behind_a_live_writer(): void
    {
        [$customerId, , , , $chunkIds] = $this->fixture('purge-starvation');
        $this->approve($customerId);
        $this->service()->embedPending();
        // The lowest id — the row a naive `ORDER BY id LIMIT n` reaches first —
        // belongs to a writer that is still running.
        $firstId = (int) DB::table('ai_embeddings')->where('customer_id', $customerId)->min('id');
        DB::table('ai_embeddings')->where('id', $firstId)
            ->update(['model_run_id' => $this->modelRun($customerId, 'running')]);
        $this->service()->requestDeletion($chunkIds);

        $first = $this->service()->purgeDeletionPending(1);
        $second = $this->service()->purgeDeletionPending(1);

        // Previously both passes returned deleted 0 / retained 1 and never
        // called the store: the live row filled the only slot every time.
        $this->assertSame(['deleted' => 1, 'retained' => 0, 'held_by_writer_barrier' => 1], $first);
        $this->assertSame(['deleted' => 1, 'retained' => 0, 'held_by_writer_barrier' => 1], $second);
        $this->assertSame(2, $this->store->deleteCalls);
        // The barrier itself is untouched: the live writer's row and point stay.
        $this->assertSame('deletion_pending', DB::table('ai_embeddings')->where('id', $firstId)->value('status'));
        $this->assertSame(0, (int) DB::table('ai_embeddings')->where('id', $firstId)->value('deletion_attempts'));
        $this->assertCount(1, $this->store->points);
    }

    public function test_persistent_point_delete_error_does_not_starve_later_points(): void
    {
        [$customer, , , , $chunks] = $this->fixture('delete-point-outage');
        $this->approve($customer);
        $this->service()->embedPending();
        $first = DB::table('ai_embeddings')->where('customer_id', $customer)->orderBy('id')->first();
        $this->store->failingKeys = [$first->vector_key];
        $this->service()->requestDeletion($chunks);

        $this->assertSame(1, $this->service()->purgeDeletionPending(1)['retained']);
        $this->assertSame(1, $this->service()->purgeDeletionPending(1)['deleted']);
        $this->assertSame(1, $this->service()->purgeDeletionPending(1)['deleted']);
        $this->assertSame('deletion_pending', DB::table('ai_embeddings')->where('id', $first->id)->value('status'));
        $this->assertSame(1, $this->service()->purgeDeletionPending(1)['retained']);
    }

    public function test_persistent_point_lookup_error_does_not_starve_later_points(): void
    {
        [$customer] = $this->fixture('lookup-point-outage');
        $this->approve($customer);
        $this->service()->embedPending();
        DB::table('ai_embeddings')->where('customer_id', $customer)
            ->update(['status' => 'pending', 'updated_at' => now()->subMinute()]);
        $first = DB::table('ai_embeddings')->where('customer_id', $customer)->orderBy('id')->first();
        $this->store->failingKeys = [$first->vector_key];

        $this->assertSame(1, $this->service()->reconcilePending(1)['undetermined']);
        $this->assertSame(1, $this->service()->reconcilePending(1)['ready']);
        $this->assertSame(1, $this->service()->reconcilePending(1)['ready']);
        $this->assertSame('pending', DB::table('ai_embeddings')->where('id', $first->id)->value('status'));
        $this->assertSame(1, $this->service()->reconcilePending(1)['undetermined']);
    }

    public function test_real_qdrant_maintenance_passes_a_broken_collection_and_recovers_it(): void
    {
        $url = getenv('LF_QDRANT_TEST_URL');
        if (! $url) {
            $this->markTestSkipped('Dedicated local Qdrant endpoint required.');
        }
        $parts = parse_url($url);
        $this->assertSame('http', $parts['scheme']);
        $this->assertContains($parts['host'], ['127.0.0.1', 'localhost']);
        config(['ai.vector_store.host' => 'http://'.$parts['host'],
            'ai.vector_store.port' => $parts['port'] ?? 6333,
            'ai.vector_store.collection_prefix' => 'lf_maintenance_'.str_replace('-', '', (string) Str::uuid())]);
        $real = new QdrantVectorStore;
        $this->app->instance(VectorStore::class, $real);
        $collection = $real->collectionFor('approved-model');
        $base = rtrim($url, '/').'/collections/'.$collection;
        Http::put($base, ['vectors' => ['size' => 3, 'distance' => 'Cosine']])->throw();
        try {
            Http::put($base.'/index?wait=true', ['field_name' => 'customer_id',
                'field_schema' => ['type' => 'keyword', 'is_tenant' => true]])->throw();
            [$customer, , , , $chunks] = $this->fixture('real-maintenance');
            $this->approve($customer);
            $this->assertSame(3, $this->service()->embedPending()['embedded']);
            $rows = DB::table('ai_embeddings')->where('customer_id', $customer)->orderBy('id')->get();
            $first = $rows->first();
            $this->assertTrue($real->exists($customer, $collection, $first->vector_key));
            DB::table('ai_embeddings')->where('customer_id', $customer)
                ->update(['status' => 'pending', 'updated_at' => now()->subMinute()]);
            // Real Qdrant 404 for one damaged locator, not an HTTP mock.
            DB::table('ai_embeddings')->where('id', $first->id)->update(['vector_index' => $collection.'_missing']);
            $this->assertSame(1, $this->service()->reconcilePending(1)['undetermined']);
            $this->assertSame(1, $this->service()->reconcilePending(1)['ready']);
            $this->assertSame(1, $this->service()->reconcilePending(1)['ready']);
            $this->assertSame('pending', DB::table('ai_embeddings')->where('id', $first->id)->value('status'));
            DB::table('ai_embeddings')->where('id', $first->id)->update(['vector_index' => $collection]);
            $this->assertSame(1, $this->service()->reconcilePending(1)['ready']);

            $this->service()->requestDeletion($chunks);
            DB::table('ai_embeddings')->where('id', $first->id)->update(['vector_index' => $collection.'_missing']);
            $this->assertSame(1, $this->service()->purgeDeletionPending(1)['retained']);
            $this->assertSame(1, $this->service()->purgeDeletionPending(1)['deleted']);
            $this->assertSame(1, $this->service()->purgeDeletionPending(1)['deleted']);
            $this->assertTrue($real->exists($customer, $collection, $first->vector_key));
            foreach ($rows->skip(1) as $row) {
                $this->assertFalse($real->exists($customer, $collection, $row->vector_key));
            }
            DB::table('ai_embeddings')->where('id', $first->id)->update(['vector_index' => $collection]);
            $this->assertSame(1, $this->service()->purgeDeletionPending(1)['deleted']);
            $this->assertFalse($real->exists($customer, $collection, $first->vector_key));
        } finally {
            Http::delete($base)->throw();
        }
    }

    public function test_run_status_policy_covers_exactly_the_schema_vocabulary(): void
    {
        $contract = json_decode((string) file_get_contents(base_path('docs/database/LF-SCHEMA-CONTRACT.json')), true);
        $table = collect($contract['tables'])->firstWhere('name', 'ai_model_runs');
        $expression = collect($table['checks'])->pluck('expression')
            ->first(static fn (string $e): bool => str_starts_with($e, '`status` in ('));
        $this->assertNotNull($expression, 'chk_amr_status not found in LF-SCHEMA-CONTRACT.json');
        preg_match_all("/'([a-z_]+)'/", $expression, $matches);

        $policy = (new \ReflectionClassConstant(AiEmbeddingService::class, 'RUN_STATUS_POLICY'))->getValue();

        // A status added to the schema must be classified on purpose, not fall
        // through to "unknown" by omission — that omission is how `blocked`
        // was stranded.
        $this->assertEqualsCanonicalizing($matches[1], array_keys($policy));

        $this->assertSame(['writer_stopped' => false, 'reclaimable' => false], $policy['queued']);
        $this->assertSame(['writer_stopped' => false, 'reclaimable' => false], $policy['running']);
        $this->assertSame(['writer_stopped' => true, 'reclaimable' => false], $policy['completed']);
        $this->assertSame(['writer_stopped' => true, 'reclaimable' => true], $policy['failed']);
        $this->assertSame(['writer_stopped' => true, 'reclaimable' => true], $policy['cancelled']);
        $this->assertSame(['writer_stopped' => true, 'reclaimable' => true], $policy['blocked']);
    }

    public function test_purge_waits_until_a_live_writer_cannot_recreate_the_old_point(): void
    {
        [$customerId, , , , $chunkIds] = $this->fixture('purge-live-writer');
        $this->approve($customerId);
        $this->service()->embedPending();
        DB::table('ai_model_runs')->where('customer_id', $customerId)->update(['status' => 'running']);
        $this->service()->requestDeletion($chunkIds);
        // Held by the barrier, not refused by the store: the two are reported
        // apart so an outage cannot be mistaken for a writer still running.
        $this->assertSame(
            ['deleted' => 0, 'retained' => 0, 'held_by_writer_barrier' => 3],
            $this->service()->purgeDeletionPending(),
        );
        $this->assertSame(0, $this->store->deleteCalls);
        DB::table('ai_model_runs')->where('customer_id', $customerId)->update(['status' => 'completed']);
        $this->assertSame(3, $this->service()->purgeDeletionPending()['deleted']);
    }

    public function test_purge_tombstones_only_what_the_store_acknowledged(): void
    {
        [$customerId, , , , $chunkIds] = $this->fixture('purge');
        $this->approve($customerId);
        $this->service()->embedPending();
        $this->service()->requestDeletion($chunkIds);
        $this->store->acknowledgeDeletes = false;

        $first = $this->service()->purgeDeletionPending();

        $this->assertSame(['deleted' => 0, 'retained' => count($chunkIds), 'held_by_writer_barrier' => 0], $first);
        $this->assertSame(count($chunkIds), DB::table('ai_embeddings')->where('customer_id', $customerId)
            ->where('status', 'deletion_pending')->where('deletion_attempts', 1)->count());
        $this->assertSame(0, DB::table('ai_embeddings')->where('customer_id', $customerId)
            ->whereNotNull('deleted_at')->count());

        $this->store->acknowledgeDeletes = true;
        $second = $this->service()->purgeDeletionPending();

        $this->assertSame(count($chunkIds), $second['deleted']);
        $this->assertSame([], $this->store->points);
        $this->assertSame(count($chunkIds), DB::table('ai_embeddings')->where('customer_id', $customerId)
            ->where('status', 'deleted')->whereNotNull('deleted_at')->count());
    }

    public function test_store_error_during_purge_keeps_the_row_retryable(): void
    {
        [$customerId, , , , $chunkIds] = $this->fixture('purge-error');
        $this->approve($customerId);
        $this->service()->embedPending();
        $this->service()->requestDeletion($chunkIds);
        $this->store->failure = new \RuntimeException('connection to qdrant://secret-host refused');

        $outcome = $this->service()->purgeDeletionPending();

        $this->assertSame(count($chunkIds), $outcome['retained']);
        $codes = DB::table('ai_embeddings')->where('customer_id', $customerId)->pluck('last_error_code')->unique()->all();
        // The driver message named a host. Only the reduced code is stored.
        $this->assertSame(['LF_VECTOR_STORE_ERROR'], $codes);
    }

    public function test_purge_is_what_releases_the_source_delete_barrier(): void
    {
        [$customerId, , , $sourceId, $chunkIds] = $this->fixture('barrier');
        $this->approve($customerId);
        $this->service()->embedPending();
        $ingestion = new AiKnowledgeIngestionService(Mockery::mock(MediaReadService::class));
        $ingestion->requestSourceDeletion($sourceId);

        try {
            $ingestion->finalizeSourceDeletion($sourceId);
            $this->fail('Deletion must be refused while a point may still be indexed.');
        } catch (AiKnowledgeIngestionException $exception) {
            $this->assertSame('embedding_delete_barrier', $exception->errorCode);
        }

        $this->service()->purgeDeletionPending();
        $ingestion->finalizeSourceDeletion($sourceId);

        $this->assertSame('deleted', DB::table('ai_knowledge_sources')->where('id', $sourceId)->value('status'));
        $this->assertSame(count($chunkIds), DB::table('ai_embeddings')->where('customer_id', $customerId)
            ->where('status', 'deleted')->count());
        $this->assertSame([], $this->store->points);
    }

    public function test_reconciliation_cannot_resurrect_a_concurrent_deletion(): void
    {
        [$customerId, , , , $chunkIds] = $this->fixture('reconcile-delete');
        $this->approve($customerId);
        $this->service()->embedPending();
        DB::table('ai_embeddings')->where('customer_id', $customerId)->update(['status' => 'pending']);
        $this->store->beforeExists = fn () => $this->service()->requestDeletion($chunkIds);
        $this->assertSame(0, $this->service()->reconcilePending()['ready']);
        $this->assertSame(count($chunkIds), DB::table('ai_embeddings')->where('customer_id', $customerId)
            ->where('status', 'deletion_pending')->count());
    }

    public function test_reconciliation_cannot_finish_a_different_attempt(): void
    {
        [$customerId] = $this->fixture('reconcile-attempt');
        $this->approve($customerId);
        $this->service()->embedPending();
        DB::table('ai_embeddings')->where('customer_id', $customerId)->update(['status' => 'pending']);
        $newRun = $this->modelRun($customerId, 'running');
        $this->store->beforeExists = fn () => DB::table('ai_embeddings')->where('customer_id', $customerId)
            ->update(['model_run_id' => $newRun]);
        $this->assertSame(0, $this->service()->reconcilePending()['ready']);
        $this->assertSame(3, DB::table('ai_embeddings')->where('customer_id', $customerId)
            ->where('status', 'pending')->where('model_run_id', $newRun)->count());
    }

    public function test_reconcile_promotes_a_pending_row_whose_point_did_land(): void
    {
        [$customerId, , , , $chunkIds] = $this->fixture('reconcile-ready');
        $this->approve($customerId);
        $this->service()->embedPending();
        // The crash window: the store has the point, the row never got updated.
        DB::table('ai_embeddings')->where('customer_id', $customerId)
            ->update(['status' => 'pending', 'embedded_at' => null]);

        $outcome = $this->service()->reconcilePending();

        $this->assertSame(count($chunkIds), $outcome['ready']);
        $this->assertSame(count($chunkIds), DB::table('ai_embeddings')->where('customer_id', $customerId)
            ->where('status', 'ready')->whereNotNull('embedded_at')->count());
    }

    public function test_reconcile_fails_a_pending_row_whose_point_is_absent(): void
    {
        [$customerId] = $this->fixture('reconcile-missing');
        $this->approve($customerId);
        $this->service()->embedPending();
        DB::table('ai_embeddings')->where('customer_id', $customerId)->update(['status' => 'pending']);
        $this->store->points = [];

        $outcome = $this->service()->reconcilePending();

        $this->assertSame(0, $outcome['ready']);
        $this->assertSame(
            ['LF_EMBEDDING_POINT_MISSING'],
            DB::table('ai_embeddings')->where('customer_id', $customerId)->pluck('last_error_code')->unique()->all(),
        );
    }

    public function test_reconcile_treats_an_unreachable_store_as_unknown_not_absent(): void
    {
        [$customerId, , , , $chunkIds] = $this->fixture('reconcile-outage');
        $this->approve($customerId);
        $this->service()->embedPending();
        DB::table('ai_embeddings')->where('customer_id', $customerId)->update(['status' => 'pending']);
        $this->store->failure = new \RuntimeException('LF_VECTOR_STORE_REQUEST_FAILED_503');

        $outcome = $this->service()->reconcilePending();

        $this->assertSame(count($chunkIds), $outcome['undetermined']);
        // Still pending. An outage is not evidence that the write never landed.
        $this->assertSame(count($chunkIds), DB::table('ai_embeddings')->where('customer_id', $customerId)
            ->where('status', 'pending')->count());
    }

    public function test_reconcile_ignores_rows_whose_run_has_not_finished(): void
    {
        [$customerId, , , , $chunkIds] = $this->fixture('reconcile-live');
        $this->approve($customerId);
        $runId = $this->modelRun($customerId, 'running');
        $this->pendingEmbedding($customerId, $chunkIds[0], $runId);

        $outcome = $this->service()->reconcilePending();

        $this->assertSame(['ready' => 0, 'failed' => 0, 'undetermined' => 0], $outcome);
    }

    public function test_another_tenant_cannot_be_embedded_or_purged(): void
    {
        [$otherId] = $this->fixture('tenant-a');
        $this->approve($otherId);
        $this->service()->embedPending();
        $indexed = $this->store->points;

        [$customerId] = $this->fixture('tenant-b');
        $this->approve($customerId);
        $marked = $this->service()->requestDeletion(
            DB::table('ai_knowledge_chunks')->where('customer_id', $otherId)->pluck('id')->all()
        );
        $outcome = $this->service()->purgeDeletionPending();

        // The chunk ids are real and belong to a real source — they are just
        // another tenant's. Nothing about them is reachable from here.
        $this->assertSame(0, $marked);
        $this->assertSame(['deleted' => 0, 'retained' => 0, 'held_by_writer_barrier' => 0], $outcome);
        $this->assertNotEmpty($indexed);
        $this->assertSame($indexed, $this->store->points);
        $this->assertSame(0, DB::table('ai_embeddings')->where('customer_id', $otherId)
            ->where('status', '<>', 'ready')->count());
    }

    private function service(): AiEmbeddingService
    {
        return $this->app->make(AiEmbeddingService::class);
    }

    private function approve(int $customerId): void
    {
        $this->settings->approve($customerId, 'ai.external_processing.approved-provider.knowledge_embedding', [
            'approved' => true,
            'data_classes' => ['derived_text'],
            'execution_regions' => ['lf_managed'],
            'retention_classes' => ['transient'],
        ]);
        $this->entitlements->grant($customerId, 'ai_knowledge_embedding');
    }

    /** @return array{0:int,1:int,2:int,3:int,4:array<int,int>} */
    public function test_mysql_all_ready_pass_uses_one_candidate_query_without_identity_batch_queries(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('SQL SHA2 prefilter is a MariaDB/MySQL optimization.');
        }
        [$customerId] = $this->fixture('query-budget', 8);
        $this->approve($customerId);
        $this->service()->embedPending();
        config()->set('ai.embedding.chunk_batch', 1);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $result = $this->service()->embedPending();
            $queries = array_column(DB::getQueryLog(), 'query');
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertSame(0, $result['embedded']);
        $this->assertCount(1, array_filter($queries, fn ($sql) => str_contains($sql, 'from `ai_knowledge_chunks` as `c`')));
        $this->assertCount(0, array_filter($queries, fn ($sql) => str_contains($sql, 'from `ai_embeddings` where')));
    }

    public function test_candidate_prefilter_does_not_hide_a_changed_source_fingerprint(): void
    {
        [$customerId, , , $sourceId, $chunks] = $this->fixture('changed-fingerprint');
        $this->approve($customerId);
        $this->service()->embedPending();
        DB::table('ai_knowledge_sources')->where('id', $sourceId)->update(['source_fingerprint' => str_repeat('e', 64)]);
        $this->assertSame(count($chunks), $this->service()->embedPending()['embedded']);
    }

    public function test_latest_replaceable_generation_is_not_hidden_by_an_older_ready_generation(): void
    {
        [$customerId, , , , $chunks] = $this->fixture('latest-generation');
        $this->approve($customerId);
        $this->service()->embedPending();
        $old = (array) DB::table('ai_embeddings')->where('knowledge_chunk_id', $chunks[0])->first();
        unset($old['id']);
        $old['generation'] = 2;
        $old['status'] = 'stale';
        $old['vector_key'] = (string) Str::uuid();
        DB::table('ai_embeddings')->insert($old);
        $this->assertSame(1, $this->service()->embedPending()['embedded']);
        $this->assertSame(3, (int) DB::table('ai_embeddings')->where('knowledge_chunk_id', $chunks[0])->max('generation'));
    }

    private function fixture(string $slug, int $chunks = 3): array
    {
        $customerId = DB::table('saas_customers')->insertGetId([
            'name' => "Embed {$slug}", 'slug' => "embed-{$slug}", 'subdomain' => "embed-{$slug}",
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $userId = DB::table('users')->insertGetId([
            'customer_id' => $customerId, 'name' => "Embed {$slug}", 'email' => "embed-{$slug}@example.test",
            'password' => bcrypt('password'), 'role' => 'customer_admin', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $mediaId = DB::table('media_files')->insertGetId([
            'customer_id' => $customerId, 'uploaded_by' => $userId, 'file_type' => 'document',
            'mime_type' => 'application/pdf', 'original_name' => "{$slug}.pdf", 'display_name' => $slug,
            'extension' => 'pdf', 'storage_disk' => 'media_local', 'storage_bucket' => 'test-media',
            'storage_key' => "embed/{$slug}.pdf", 'checksum' => 'sha256:'.$slug, 'file_size_bytes' => 1,
            'visibility' => 'private', 'status' => 'ready', 'created_at' => now(), 'updated_at' => now(),
        ]);
        TenantContext::set((object) ['id' => $customerId]);

        $sourceId = DB::table('ai_knowledge_sources')->insertGetId([
            'customer_id' => $customerId, 'source_uuid' => $this->uuid("source-{$slug}"),
            'source_type' => 'course_activity', 'source_id' => 4242, 'media_file_id' => $mediaId,
            'usage_type' => 'document', 'content_type' => 'extracted_text', 'title' => "Source {$slug}",
            'locale' => 'vi', 'content_hash' => 'sha256:content-'.$slug,
            'source_fingerprint' => str_pad('fp'.$slug, 64, '0'), 'processing_version' => 'docling@1',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $chunkIds = [];
        for ($i = 1; $i <= $chunks; $i++) {
            $chunkIds[] = (int) DB::table('ai_knowledge_chunks')->insertGetId([
                'customer_id' => $customerId, 'knowledge_source_id' => $sourceId,
                'chunk_uuid' => $this->uuid("chunk-{$slug}-{$i}"), 'sequence_no' => $i,
                'content' => "Noi dung khoi {$i} cua {$slug}", 'content_hash' => 'sha256:chunk-'.$slug.'-'.$i,
                'char_start' => 0, 'char_end' => 40, 'locator_type' => 'page',
                'locator_start' => (string) $i, 'locator_end' => (string) $i,
                'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return [$customerId, $userId, $mediaId, $sourceId, $chunkIds];
    }

    private function modelRun(int $customerId, string $status, $updatedAt = null): int
    {
        // Mirrors AiModelRunRecorder so the row satisfies chk_amr_completed,
        // chk_amr_failed and chk_amr_blocked on MariaDB, not only on SQLite.
        $finished = in_array($status, ['completed', 'failed', 'blocked', 'cancelled'], true);

        return (int) DB::table('ai_model_runs')->insertGetId([
            'customer_id' => $customerId, 'run_uuid' => $this->uuid('run-'.$customerId.'-'.$status.'-'.uniqid()),
            'prompt_hash' => 'sha256:fixture', 'purpose' => 'knowledge_embedding',
            'provider' => 'approved-provider', 'model' => 'approved-model', 'status' => $status,
            'error_code' => match ($status) {
                'failed' => 'AI_PROVIDER_CALL_FAILED',
                'blocked' => 'AI_APPROVAL_REQUIRED',
                default => null,
            },
            'completed_at' => $finished ? now() : null,
            'created_at' => now(), 'updated_at' => $updatedAt ?? now(),
        ]);
    }

    private function pendingEmbedding(int $customerId, int $chunkId, int $runId): int
    {
        return (int) DB::table('ai_embeddings')->insertGetId([
            'customer_id' => $customerId, 'knowledge_chunk_id' => $chunkId, 'model_run_id' => $runId,
            'provider' => 'approved-provider', 'model' => 'approved-model', 'dimensions' => 3,
            'vector_store' => 'qdrant', 'vector_index' => 'lf_text_approved_model',
            'vector_key' => $this->uuid('pending-'.$chunkId),
            'embedding_hash' => $this->currentHash($chunkId),
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** The identity the worker would compute for this chunk right now. */
    private function currentHash(int $chunkId): string
    {
        $row = DB::table('ai_knowledge_chunks as c')
            ->join('ai_knowledge_sources as s', 's.id', '=', 'c.knowledge_source_id')
            ->where('c.id', $chunkId)
            ->first(['c.content_hash', 's.identity_fingerprint', 's.identity_version']);

        return 'sha256:'.hash('sha256', implode('|', [
            $row->content_hash, $row->identity_fingerprint, $row->identity_version,
            'approved-provider', 'approved-model', '3',
        ]));
    }

    private function uuid(string $seed): string
    {
        $hex = substr(hash('sha256', $seed), 0, 32);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-4'.substr($hex, 13, 3)
            .'-8'.substr($hex, 17, 3).'-'.substr($hex, 20, 12);
    }
}
