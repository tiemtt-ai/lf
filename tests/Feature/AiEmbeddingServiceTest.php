<?php

namespace Tests\Feature;

use App\Contracts\Ai\CommercialEntitlements;
use App\Contracts\Ai\EmbeddingProvider;
use App\Contracts\Ai\ExternalProcessingApprovals;
use App\Contracts\Ai\TenantSettingSource;
use App\Contracts\Ai\UsageQuotaReserver;
use App\Contracts\Ai\VectorStore;
use App\Exceptions\AiKnowledgeIngestionException;
use App\Providers\AppServiceProvider;
use App\Services\Ai\SettingBackedExternalProcessingApprovals;
use App\Services\Ai\UnavailableEmbeddingProvider;
use App\Services\AiEmbeddingService;
use App\Services\AiKnowledgeIngestionService;
use App\Services\MediaReadService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_a_superseded_identity_is_reported_as_blocked_not_retried(): void
    {
        [$customerId, , , , $chunkIds] = $this->fixture('blocked-identity');
        $this->approve($customerId);
        $this->service()->embedPending();
        // `stale` has no documented path back to `pending`, and the amendment
        // deliberately did not add one — so an active chunk whose identity
        // landed there stays out of the index.
        $this->service()->markStale([$chunkIds[0]]);

        $outcome = $this->service()->embedPending();

        $this->assertSame(1, $outcome['stranded']);
        $this->assertSame(0, $outcome['embedded']);
        $this->assertSame('stale', DB::table('ai_embeddings')->where('customer_id', $customerId)
            ->where('knowledge_chunk_id', $chunkIds[0])->value('status'));
        // And it costs nothing to rediscover: the pass gives up before the gate.
        $this->assertSame(1, DB::table('ai_model_runs')->where('customer_id', $customerId)->count());
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

    public function test_purge_tombstones_only_what_the_store_acknowledged(): void
    {
        [$customerId, , , , $chunkIds] = $this->fixture('purge');
        $this->approve($customerId);
        $this->service()->embedPending();
        $this->service()->requestDeletion($chunkIds);
        $this->store->acknowledgeDeletes = false;

        $first = $this->service()->purgeDeletionPending();

        $this->assertSame(['deleted' => 0, 'retained' => count($chunkIds)], $first);
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
        $this->assertSame(['deleted' => 0, 'retained' => 0], $outcome);
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
        return (int) DB::table('ai_model_runs')->insertGetId([
            'customer_id' => $customerId, 'run_uuid' => $this->uuid('run-'.$customerId.'-'.$status.'-'.uniqid()),
            'prompt_hash' => 'sha256:fixture', 'purpose' => 'knowledge_embedding',
            'provider' => 'approved-provider', 'model' => 'approved-model', 'status' => $status,
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
