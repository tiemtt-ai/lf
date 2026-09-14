<?php

namespace Tests\Feature;

use App\Contracts\Ai\CommercialEntitlements;
use App\Contracts\Ai\EmbeddingProvider;
use App\Contracts\Ai\ExternalProcessingApprovals;
use App\Contracts\Ai\TenantSettingSource;
use App\Contracts\Ai\UsageQuotaReserver;
use App\Contracts\Ai\VectorStore;
use App\Exceptions\AiEmbeddingException;
use App\Services\Ai\SettingBackedExternalProcessingApprovals;
use App\Services\AiEmbeddingService;
use App\Services\AiKnowledgeRetrievalService;
use App\Services\CourseMediaOwnerContextAuthorizer;
use App\Services\MediaReadService;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Ai\FakeCommercialEntitlements;
use Tests\Support\Ai\FakeEmbeddingProvider;
use Tests\Support\Ai\FakeTenantSettings;
use Tests\Support\Ai\FakeUsageQuotaReserver;
use Tests\Support\Ai\FakeVectorStore;
use Tests\TestCase;

/**
 * The index is a candidate generator with no authority. Every test here asks
 * the same question from a different angle: the store returned a hit — what
 * still has to be true before it may be cited?
 */
class AiKnowledgeRetrievalServiceTest extends TestCase
{
    use RefreshDatabase;

    private FakeTenantSettings $settings;

    private FakeCommercialEntitlements $entitlements;

    private FakeVectorStore $store;

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
        $this->store = new FakeVectorStore;

        $this->app->instance(TenantSettingSource::class, $this->settings);
        $this->app->bind(ExternalProcessingApprovals::class, SettingBackedExternalProcessingApprovals::class);
        $this->app->instance(CommercialEntitlements::class, $this->entitlements);
        $this->app->instance(UsageQuotaReserver::class, new FakeUsageQuotaReserver(100.0));
        $this->app->instance(VectorStore::class, $this->store);
        $this->app->instance(EmbeddingProvider::class, new FakeEmbeddingProvider);
    }

    public function test_archived_source_is_dropped_even_when_chunks_are_active(): void
    {
        [$customer, $actor, , $source] = $this->indexed('source-guard');
        $this->store->searchResult = $this->keysFor($customer);
        DB::table('ai_knowledge_sources')->where('id', $source)->update(['status' => 'archived']);
        $this->assertSame([], $this->retrieval(true)->retrieve($actor, [0.1, 0.2, 0.3]));
        $this->assertSame(0, DB::table('media_access_logs')->where('customer_id', $customer)->count());
    }

    public function test_active_chunk_with_deletion_request_is_not_returned(): void
    {
        [$customer, $actor, $chunks] = $this->indexed('chunk-deletion-guard');
        $this->store->searchResult = $this->keysFor($customer);
        DB::table('ai_knowledge_chunks')->where('id', $chunks[0])->update(['deletion_requested_at' => now()]);
        $hits = $this->retrieval(true)->retrieve($actor, [0.1, 0.2, 0.3]);
        $this->assertSame(array_slice($chunks, 1), array_column($hits, 'knowledge_chunk_id'));
        $this->assertSame(2, DB::table('media_access_logs')->where('customer_id', $customer)->count());
    }

    public static function mismatchingMediaRevisions(): array
    {
        return [
            'ambiguous identity' => ['ambiguous'],
            'locale only' => ['locale'],
            'fingerprint only' => ['source_fingerprint'],
            'version only' => ['processing_version'],
        ];
    }

    #[DataProvider('mismatchingMediaRevisions')]
    public function test_each_media_revision_guard_independently_refuses_a_candidate(string $field): void
    {
        [$customer, $actor, , $sourceId] = $this->indexed('isolated-'.$field);
        $this->store->searchResult = $this->keysFor($customer);
        $source = DB::table('ai_knowledge_sources')->where('id', $sourceId)->first();
        $identity = [
            'media_file_id' => (int) $source->media_file_id,
            'locale' => $source->locale,
            'source_fingerprint' => (string) $source->identity_fingerprint,
            'processing_version' => (string) $source->identity_version,
        ];
        $different = $identity;
        if ($field === 'ambiguous') {
            $different['processing_version'] = 'another-version';
            // First identity matches perfectly: only cardinality can reject it.
            $returned = [$identity, $different];
        } else {
            $different[$field] = match ($field) {
                'locale' => 'ko',
                'source_fingerprint' => str_repeat('f', 64),
                default => 'another-version',
            };
            $returned = [$different];
        }
        // Test the consumer guard separately from the real Media selector.
        // Existing integration-style tests still exercise that selector.
        $reader = Mockery::mock(MediaReadService::class);
        $reader->shouldReceive('currentRevision')->once()->andReturn($returned);
        $service = new AiKnowledgeRetrievalService($this->store, $reader);
        $this->assertSame([], $service->retrieve($actor, [0.1, 0.2, 0.3]));
        $logs = DB::table('media_access_logs')->where('customer_id', $customer)->get();
        $this->assertCount(3, $logs);
        foreach ($logs as $log) {
            $metadata = json_decode($log->metadata, true);
            $this->assertSame('denied', $metadata['decision']);
            $this->assertSame('revision_mismatch', $metadata['error_code']);
        }
    }

    public function test_authorized_hits_are_returned_in_store_rank_order(): void
    {
        [$customerId, $userId, $chunkIds] = $this->indexed('ranked');
        $keys = $this->keysFor($customerId);
        // Deliberately not the insertion order: the relational join will not
        // preserve the store's ranking, so the service has to restore it.
        $this->store->searchResult = [$keys[2], $keys[0], $keys[1]];

        $hits = $this->retrieval(true)->retrieve($userId, [0.1, 0.2, 0.3]);

        $this->assertSame(
            [$chunkIds[2], $chunkIds[0], $chunkIds[1]],
            array_column($hits, 'knowledge_chunk_id'),
        );
        $this->assertArrayHasKey('locator', $hits[0]);
        $this->assertSame('page', $hits[0]['locator']['type']);
        $logs = DB::table('media_access_logs')->where('customer_id', $customerId)->get();
        $this->assertCount(3, $logs);
        $evidence = json_decode($logs[0]->metadata, true);
        $this->assertSame('allowed', $evidence['decision']);
        $this->assertSame('knowledge_retrieval', $evidence['operation']);
        $this->assertArrayNotHasKey('content', $evidence);
        $this->assertSame($userId, (int) $logs[0]->user_id);
    }

    public function test_unauthorized_actor_gets_nothing_even_though_the_index_matched(): void
    {
        [$customerId, $userId] = $this->indexed('unauthorized');
        $this->store->searchResult = $this->keysFor($customerId);

        $hits = $this->retrieval(false)->retrieve($userId, [0.1, 0.2, 0.3]);
        $log = DB::table('media_access_logs')->where('customer_id', $customerId)->first();
        $this->assertSame('denied', json_decode($log->metadata, true)['decision']);

        // The vector matched. Media authorization is re-entered per query, so
        // the match grants nothing on its own.
        $this->assertSame([], $hits);
    }

    public function test_audit_failure_prevents_returning_retrieved_content(): void
    {
        $mysql = DB::getDriverName() === 'mysql';
        if ($mysql) {
            DB::rollBack();
        }
        DB::statement(DB::getDriverName() === 'mysql'
            ? "CREATE TRIGGER test_retrieval_audit_fail BEFORE INSERT ON media_access_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit unavailable'"
            : "CREATE TRIGGER test_retrieval_audit_fail BEFORE INSERT ON media_access_logs BEGIN SELECT RAISE(ABORT, 'audit unavailable'); END");
        if ($mysql) {
            DB::beginTransaction();
        }
        try {
            [$customerId, $userId] = $this->indexed('audit-failure');
            $this->store->searchResult = $this->keysFor($customerId);
            $this->expectException(QueryException::class);
            $this->retrieval(true)->retrieve($userId, [0.1, 0.2, 0.3]);
        } finally {
            if ($mysql) {
                DB::rollBack();
            }
            DB::statement('DROP TRIGGER test_retrieval_audit_fail');
            if ($mysql) {
                DB::beginTransaction();
            }
        }
    }

    public function test_archived_chunk_is_dropped(): void
    {
        [$customerId, $userId, $chunkIds] = $this->indexed('archived');
        $this->store->searchResult = $this->keysFor($customerId);
        DB::table('ai_knowledge_chunks')->where('id', $chunkIds[0])->update(['status' => 'archived']);

        $hits = $this->retrieval(true)->retrieve($userId, [0.1, 0.2, 0.3]);

        $this->assertNotContains($chunkIds[0], array_column($hits, 'knowledge_chunk_id'));
        $this->assertCount(count($chunkIds) - 1, $hits);
    }

    public function test_stale_and_deletion_pending_embeddings_are_dropped(): void
    {
        [$customerId, $userId, $chunkIds] = $this->indexed('superseded');
        $this->store->searchResult = $this->keysFor($customerId);
        $service = $this->app->make(AiEmbeddingService::class);
        $service->markStale([$chunkIds[0]]);
        $service->requestDeletion([$chunkIds[1]]);

        $hits = $this->retrieval(true)->retrieve($userId, [0.1, 0.2, 0.3]);

        // Both points are still in the index — that is exactly the window this
        // filter exists for.
        $this->assertCount(3, $this->store->points);
        $this->assertSame([$chunkIds[2]], array_column($hits, 'knowledge_chunk_id'));
    }

    public function test_a_revision_that_moved_on_is_dropped_even_if_nothing_marked_it_stale(): void
    {
        [$customerId, $userId, $chunkIds] = $this->indexed('revision');
        $this->store->searchResult = $this->keysFor($customerId);
        // The text changed underneath a `ready` embedding. Nothing in the index
        // knows; the recomputed identity does.
        DB::table('ai_knowledge_chunks')->where('id', $chunkIds[0])
            ->update(['content' => 'Noi dung da thay doi', 'content_hash' => 'sha256:moved-on']);

        $hits = $this->retrieval(true)->retrieve($userId, [0.1, 0.2, 0.3]);

        $this->assertNotContains($chunkIds[0], array_column($hits, 'knowledge_chunk_id'));
    }

    public function test_a_source_pending_deletion_is_dropped(): void
    {
        [$customerId, $userId, $chunkIds, $sourceId] = $this->indexed('source-deleting');
        $this->store->searchResult = $this->keysFor($customerId);
        DB::table('ai_knowledge_sources')->where('id', $sourceId)
            ->update(['deletion_requested_at' => now()]);

        $this->assertSame([], $this->retrieval(true)->retrieve($userId, [0.1, 0.2, 0.3]));
    }

    public function test_hits_belonging_to_another_tenant_are_dropped(): void
    {
        [$otherId] = $this->indexed('tenant-a');
        $otherKeys = $this->keysFor($otherId);

        [$customerId, $userId] = $this->indexed('tenant-b');
        // Model a store filter that failed open and returned foreign keys.
        $this->store->searchResult = $otherKeys;

        $hits = $this->retrieval(true)->retrieve($userId, [0.1, 0.2, 0.3]);

        // The relational scope is an independent second boundary, so a broken
        // payload filter still leaks nothing.
        $this->assertSame([], $hits);
        $this->assertNotSame($customerId, $otherId);
    }

    public function test_limit_is_applied_after_filtering_not_before(): void
    {
        [$customerId, $userId, $chunkIds] = $this->indexed('limit');
        $this->store->searchResult = $this->keysFor($customerId);
        DB::table('ai_knowledge_chunks')->where('id', $chunkIds[0])->update(['status' => 'archived']);

        $hits = $this->retrieval(true)->retrieve($userId, [0.1, 0.2, 0.3], 2);

        // Over-fetching is what makes this possible: two survivors are still
        // returned after one candidate was dropped.
        $this->assertCount(2, $hits);
    }

    public function test_allowed_access_is_audited_only_for_hits_actually_returned(): void
    {
        [$customerId, $userId] = $this->indexed('audit-limit');
        $this->store->searchResult = $this->keysFor($customerId);

        $hits = $this->retrieval(true)->retrieve($userId, [0.1, 0.2, 0.3], 1);

        // Three candidates are authorized, one is returned. An `allowed` row
        // means authorization to disclose that chunk; writing one for the
        // over-fetched surplus would record disclosure that never happened
        // (review AR-P3-4c).
        $this->assertCount(1, $hits);
        $logs = DB::table('media_access_logs')->where('customer_id', $customerId)->get();
        $this->assertCount(1, $logs);
        $evidence = json_decode($logs[0]->metadata, true);
        $this->assertSame('allowed', $evidence['decision']);
        $this->assertSame($hits[0]['knowledge_chunk_id'], $evidence['knowledge_chunk_id']);
    }

    public function test_a_query_vector_of_the_wrong_width_never_reaches_the_store(): void
    {
        [$customerId, $userId] = $this->indexed('width');
        $this->store->searchResult = $this->keysFor($customerId);

        try {
            $this->retrieval(true)->retrieve($userId, [0.1, 0.2]);
            $this->fail('A mis-sized query vector must be refused.');
        } catch (AiEmbeddingException $exception) {
            $this->assertSame('LF_EMBEDDING_DIMENSION_MISMATCH', $exception->errorCode);
        }
    }

    public function test_unconfigured_store_returns_nothing(): void
    {
        [$customerId, $userId] = $this->indexed('offline');
        $this->store->searchResult = $this->keysFor($customerId);
        $this->store->configured = false;

        $this->assertSame([], $this->retrieval(true)->retrieve($userId, [0.1, 0.2, 0.3]));
    }

    public function test_detached_usage_denies_old_chunks_and_audits_detached(): void
    {
        [$customerId, $actor] = $this->indexed('detached');
        DB::table('media_file_usages')->where('customer_id', $customerId)->update(['status' => 'detached']);
        $this->assertMediaDenied($customerId, $actor, 'detached');
    }

    public function test_replaced_file_denies_old_chunks_even_with_the_same_fingerprint_and_version(): void
    {
        [$customerId, $actor, , $sourceId] = $this->indexed('replaced');
        $source = DB::table('ai_knowledge_sources')->where('id', $sourceId)->first();
        $old = (array) DB::table('media_files')->where('id', $source->media_file_id)->first();
        unset($old['id']);
        $old['storage_key'] .= '-replacement';
        $replacement = DB::table('media_files')->insertGetId($old);
        DB::table('media_file_usages')->where('customer_id', $customerId)->update(['media_file_id' => $replacement]);
        $this->outputRevision($customerId, $replacement, $source->source_fingerprint, $source->processing_version);
        $this->assertMediaDenied($customerId, $actor, 'revision_mismatch');
    }

    public function test_ambiguous_active_slot_is_denied_instead_of_picking_the_indexed_file(): void
    {
        [$customerId, $actor, , $sourceId] = $this->indexed('ambiguous');
        $source = DB::table('ai_knowledge_sources')->where('id', $sourceId)->first();
        $file = (array) DB::table('media_files')->where('id', $source->media_file_id)->first();
        unset($file['id']);
        $file['storage_key'] .= '-second';
        $secondId = DB::table('media_files')->insertGetId($file);
        DB::table('media_file_usages')->insert([
            'customer_id' => $customerId, 'media_file_id' => $secondId, 'owner_type' => 'course_activity',
            'owner_id' => $source->source_id, 'usage_type' => 'document', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertMediaDenied($customerId, $actor, 'ambiguous_source');
    }

    public function test_tombstoned_media_cannot_be_read_even_if_usage_and_output_are_left_active(): void
    {
        [$customerId, $actor] = $this->indexed('tombstone');
        DB::table('media_files')->where('customer_id', $customerId)->update(['status' => 'deleted']);
        $this->assertMediaDenied($customerId, $actor, 'missing');
    }

    public function test_new_ready_media_revision_denies_internally_consistent_old_ai_snapshot(): void
    {
        [$customerId, $actor, , $sourceId] = $this->indexed('new-media-revision');
        $source = DB::table('ai_knowledge_sources')->where('id', $sourceId)->first();
        $this->outputRevision($customerId, $source->media_file_id, str_repeat('b', 64), 'docling@2');
        // Leave old AI hashes AND old Media rows ready: do not rely on stale sweeps.
        $this->assertMediaDenied($customerId, $actor, 'revision_mismatch');
    }

    public function test_a_new_failed_job_does_not_supersede_the_current_ready_revision(): void
    {
        [$customerId, $actor, , $sourceId] = $this->indexed('failed-new-job');
        $source = DB::table('ai_knowledge_sources')->where('id', $sourceId)->first();
        $job = $this->outputRevision($customerId, $source->media_file_id, str_repeat('c', 64), 'docling@2');
        DB::table('media_processing_jobs')->where('id', $job)->update(['status' => 'failed', 'error_code' => 'processing_failed']);
        $this->store->searchResult = $this->keysFor($customerId);
        $this->assertCount(3, $this->retrieval(true)->retrieve($actor, [0.1, 0.2, 0.3]));
    }

    public function test_cache_does_not_authorize_another_usage_slot_on_the_same_owner(): void
    {
        [$customerId, $actor, , $sourceId] = $this->indexed('slot-cache');
        $source = (array) DB::table('ai_knowledge_sources')->where('id', $sourceId)->first();
        foreach (['id', 'identity_usage_type', 'identity_content_type', 'identity_locale', 'identity_fingerprint', 'identity_version'] as $key) {
            unset($source[$key]);
        }
        $source['source_uuid'] = $this->uuid('other-slot');
        $source['usage_type'] = 'video';
        $source['content_type'] = 'transcript';
        $otherId = DB::table('ai_knowledge_sources')->insertGetId($source);
        $chunk = DB::table('ai_knowledge_chunks')->where('knowledge_source_id', $sourceId)->orderByDesc('id')->first();
        DB::table('ai_knowledge_chunks')->where('id', $chunk->id)->update(['knowledge_source_id' => $otherId]);
        $this->store->searchResult = $this->keysFor($customerId);
        $hits = $this->retrieval(true)->retrieve($actor, [0.1, 0.2, 0.3]);
        $this->assertCount(2, $hits);
        $this->assertNotContains((int) $chunk->id, array_column($hits, 'knowledge_chunk_id'));
    }

    public function test_real_authorizer_rechecks_actor_status_and_teacher_assignment(): void
    {
        [$customerId, $actor, , $sourceId] = $this->indexed('real-role');
        $source = DB::table('ai_knowledge_sources')->where('id', $sourceId)->first();
        $activity = DB::table('core_course_template_activities')->where('id', $source->source_id)->first();
        DB::table('users')->where('id', $actor)->update(['role' => 'teacher']);
        $this->assertMediaDenied($customerId, $actor, 'unauthorized');
        DB::table('core_course_template_teachers')->insert([
            'customer_id' => $customerId, 'template_id' => $activity->template_id,
            'teacher_id' => $actor, 'role' => 'primary', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertCount(3, $this->retrieval(true)->retrieve($actor, [0.1, 0.2, 0.3]));
        DB::table('users')->where('id', $actor)->update(['status' => 'inactive']);
        $this->assertSame([], $this->retrieval(true)->retrieve($actor, [0.1, 0.2, 0.3]));
    }

    public static function speechSources(): array
    {
        return [['audio', 'transcript'], ['video', 'transcript'], ['video', 'video_frame_text']];
    }

    #[DataProvider('speechSources')]
    public function test_multilingual_revision_is_selected_with_the_stored_profile(string $usage, string $content): void
    {
        [$customerId, $actor, , $sourceId] = $this->indexed("{$usage}-{$content}");
        $source = DB::table('ai_knowledge_sources')->where('id', $sourceId)->first();
        DB::table('media_files')->where('id', $source->media_file_id)->update(['file_type' => $usage]);
        DB::table('media_file_usages')->where('customer_id', $customerId)->update(['usage_type' => $usage]);
        DB::table('ai_knowledge_sources')->where('id', $sourceId)->update([
            'usage_type' => $usage, 'content_type' => $content, 'locale' => 'mul',
            'metadata' => json_encode(['language_profile' => ['ko', 'vi']]),
        ]);
        $jobId = (int) DB::table('media_processing_jobs')->where('customer_id', $customerId)->value('id');
        $this->speechOutput($customerId, $source->media_file_id, $jobId, $content,
            $source->source_fingerprint, $source->processing_version, 'locales=ko,vi');
        $newJob = $this->outputRevision($customerId, $source->media_file_id, str_repeat('d', 64), 'speech-v2');
        $this->speechOutput($customerId, $source->media_file_id, $newJob, $content,
            str_repeat('d', 64), 'speech-v2', 'locales=en,ko');
        $this->store->searchResult = $this->keysFor($customerId);
        $this->assertCount(3, $this->retrieval(true)->retrieve($actor, [0.1, 0.2, 0.3]));
        // A newer revision of the SAME profile must invalidate the AI snapshot.
        DB::table('media_processing_jobs')->where('id', $newJob)->update(['output_profile' => 'locales=ko,vi']);
        $this->assertSame([], $this->retrieval(true)->retrieve($actor, [0.1, 0.2, 0.3]));
        $log = DB::table('media_access_logs')->where('customer_id', $customerId)->orderByDesc('id')->first();
        $this->assertSame('revision_mismatch', json_decode($log->metadata, true)['error_code']);
    }

    private function speechOutput(int $customerId, int $mediaId, int $jobId, string $content,
        string $fingerprint, string $version, string $profile): void
    {
        DB::table('media_processing_jobs')->where('id', $jobId)->update([
            'job_type' => $content === 'transcript' ? 'speech_to_text' : 'frame_ocr',
            'output_profile' => $profile, 'output_profile_hash' => hash('sha256', $profile),
        ]);
        $row = [
            'customer_id' => $customerId, 'media_file_id' => $mediaId, 'processing_job_id' => $jobId,
            'locale' => 'mul', 'locator_type' => 'timespan', 'locator_value' => '0-1000',
            'text' => 'Tiếng Việt 한국어', 'processing_version' => $version, 'source_fingerprint' => $fingerprint,
            'status' => 'ready', 'created_at' => now(), 'updated_at' => now(),
        ];
        if ($content === 'video_frame_text') {
            $row += ['reading_order' => 1, 'bbox_x' => 0.1, 'bbox_y' => 0.1, 'bbox_width' => 0.2,
                'bbox_height' => 0.2, 'frame_width' => 1920, 'frame_height' => 1080];
        }
        $outputId = DB::table($content === 'transcript' ? 'media_transcripts' : 'media_video_frame_texts')->insertGetId($row);
        DB::table('media_processing_jobs')->where('id', $jobId)->update([
            'output_type' => $content, 'output_id' => $outputId,
        ]);
    }

    private function assertMediaDenied(int $customerId, int $actor, string $reason): void
    {
        $this->store->searchResult = $this->keysFor($customerId);
        $this->assertSame([], $this->retrieval(true)->retrieve($actor, [0.1, 0.2, 0.3]));
        $logs = DB::table('media_access_logs')->where('customer_id', $customerId)->get();
        $this->assertCount(3, $logs);
        foreach ($logs as $log) {
            $metadata = json_decode($log->metadata, true);
            $this->assertSame('denied', $metadata['decision']);
            $this->assertSame($reason, $metadata['error_code']);
        }
    }

    private function retrieval(bool $authorized): AiKnowledgeRetrievalService
    {
        $authorizer = new CourseMediaOwnerContextAuthorizer;
        if (! $authorized) {
            $authorizer = Mockery::mock(CourseMediaOwnerContextAuthorizer::class);
            $authorizer->shouldReceive('authorized')->andReturn(false);
        }

        return new AiKnowledgeRetrievalService($this->store,
            $this->app->makeWith(MediaReadService::class, ['authorizer' => $authorizer]));
    }

    /** @return array<int,string> */
    private function keysFor(int $customerId): array
    {
        return DB::table('ai_embeddings')->where('customer_id', $customerId)
            ->orderBy('knowledge_chunk_id')->pluck('vector_key')->all();
    }

    /**
     * A tenant whose chunks have actually been through the embedding worker,
     * so the rows and the index agree the way they would in production.
     *
     * @return array{0:int,1:int,2:array<int,int>,3:int}
     */
    private function indexed(string $slug): array
    {
        $customerId = DB::table('saas_customers')->insertGetId([
            'name' => "Retrieve {$slug}", 'slug' => "retrieve-{$slug}", 'subdomain' => "retrieve-{$slug}",
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $userId = DB::table('users')->insertGetId([
            'customer_id' => $customerId, 'name' => "Retrieve {$slug}", 'email' => "retrieve-{$slug}@example.test",
            'password' => bcrypt('password'), 'role' => 'customer_admin', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $mediaId = DB::table('media_files')->insertGetId([
            'customer_id' => $customerId, 'uploaded_by' => $userId, 'file_type' => 'document',
            'mime_type' => 'application/pdf', 'original_name' => "{$slug}.pdf", 'display_name' => $slug,
            'extension' => 'pdf', 'storage_disk' => 'media_local', 'storage_bucket' => 'test-media',
            'storage_key' => "retrieve/{$slug}.pdf", 'checksum' => 'sha256:'.$slug, 'file_size_bytes' => 1,
            'visibility' => 'private', 'status' => 'ready', 'created_at' => now(), 'updated_at' => now(),
        ]);
        TenantContext::set((object) ['id' => $customerId]);

        $templateId = DB::table('core_course_templates')->insertGetId([
            'customer_id' => $customerId, 'title' => $slug, 'status' => 'active',
            'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $lessonId = DB::table('core_course_template_lessons')->insertGetId([
            'customer_id' => $customerId, 'template_id' => $templateId, 'title' => $slug,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $activityId = DB::table('core_course_template_activities')->insertGetId([
            'customer_id' => $customerId, 'template_id' => $templateId, 'template_lesson_id' => $lessonId,
            'title' => $slug, 'activity_type' => 'document',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('media_file_usages')->insert([
            'customer_id' => $customerId, 'media_file_id' => $mediaId,
            'owner_type' => 'course_activity', 'owner_id' => $activityId,
            'usage_type' => 'document', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $sourceId = (int) DB::table('ai_knowledge_sources')->insertGetId([
            'customer_id' => $customerId, 'source_uuid' => $this->uuid("rsource-{$slug}"),
            'source_type' => 'course_activity', 'source_id' => $activityId, 'media_file_id' => $mediaId,
            'usage_type' => 'document', 'content_type' => 'extracted_text', 'title' => "Source {$slug}",
            'locale' => 'vi', 'content_hash' => 'sha256:rcontent-'.$slug,
            'source_fingerprint' => str_pad('rfp'.$slug, 64, '0'), 'processing_version' => 'docling@1',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->outputRevision($customerId, $mediaId, str_pad('rfp'.$slug, 64, '0'), 'docling@1');

        $chunkIds = [];
        for ($i = 1; $i <= 3; $i++) {
            $chunkIds[] = (int) DB::table('ai_knowledge_chunks')->insertGetId([
                'customer_id' => $customerId, 'knowledge_source_id' => $sourceId,
                'chunk_uuid' => $this->uuid("rchunk-{$slug}-{$i}"), 'sequence_no' => $i,
                'content' => "Khoi {$i} cua {$slug}", 'content_hash' => 'sha256:rchunk-'.$slug.'-'.$i,
                'char_start' => 0, 'char_end' => 30, 'locator_type' => 'page',
                'locator_start' => (string) $i, 'locator_end' => (string) $i,
                'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->settings->approve($customerId, 'ai.external_processing.approved-provider.knowledge_embedding', [
            'approved' => true,
            'data_classes' => ['derived_text'],
            'execution_regions' => ['lf_managed'],
            'retention_classes' => ['transient'],
        ]);
        $this->entitlements->grant($customerId, 'ai_knowledge_embedding');
        $this->app->make(AiEmbeddingService::class)->embedPending();

        return [$customerId, $userId, $chunkIds, $sourceId];
    }

    private function outputRevision(int $customerId, int $mediaId, string $fingerprint, string $version): int
    {
        $jobId = DB::table('media_processing_jobs')->insertGetId([
            'customer_id' => $customerId, 'media_file_id' => $mediaId,
            'job_type' => 'ocr', 'status' => 'processing', 'attempt' => 1, 'provider' => 'fake',
            'idempotency_key' => $this->uuid("job-{$mediaId}-{$fingerprint}-{$version}"),
            'correlation_id' => $this->uuid("corr-{$mediaId}-{$fingerprint}-{$version}"),
            'source_fingerprint' => $fingerprint, 'processing_version' => $version,
            'output_profile' => 'locale=vi', 'output_profile_hash' => hash('sha256', 'locale=vi'),
            'completed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $outputId = DB::table('media_extracted_texts')->insertGetId([
            'customer_id' => $customerId, 'media_file_id' => $mediaId, 'processing_job_id' => $jobId,
            'locale' => 'vi', 'locator_type' => 'page', 'locator_value' => '1', 'sequence' => 1,
            'text' => 'Test page', 'char_count' => 9, 'extraction_method' => 'ocr', 'provider' => 'fake',
            'processing_version' => $version, 'source_fingerprint' => $fingerprint, 'status' => 'ready',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('media_processing_jobs')->where('id', $jobId)->update([
            'status' => 'ready', 'output_type' => 'extracted_text', 'output_id' => $outputId,
        ]);

        return $jobId;
    }

    private function uuid(string $seed): string
    {
        $hex = substr(hash('sha256', $seed), 0, 32);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-4'.substr($hex, 13, 3)
            .'-8'.substr($hex, 17, 3).'-'.substr($hex, 20, 12);
    }
}
