<?php

namespace Tests\Feature;

use App\Contracts\Ai\VectorStore;
use App\Events\MediaFileDeleted;
use App\Events\MediaRevisionReady;
use App\Exceptions\AiKnowledgeIngestionException;
use App\Listeners\EraseKnowledgeOfDeletedMedia;
use App\Listeners\SyncKnowledgeOfReadyMedia;
use App\Services\AiKnowledgeIngestionService;
use App\Services\AiKnowledgeRetrievalService;
use App\Services\MediaReadService;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Ai\FakeVectorStore;
use Tests\Support\Ai\KnowledgeSyncFixture;
use Tests\TestCase;

/**
 * Knowledge Sync Contract: Media → Knowledge create, stale/archive and delete,
 * through the real Media Read system principal. Media output rows are inserted
 * directly; the services under test read them only through Media Read.
 */
class AiKnowledgeSyncServiceTest extends TestCase
{
    use KnowledgeSyncFixture;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        TenantContext::set(null);
        parent::tearDown();
    }

    // ---------------------------------------------------------------- Create

    public function test_published_version_audio_is_ingested_with_system_provenance_and_a_quiet_rerun(): void
    {
        $f = $this->tenant('create');
        $media = $this->audioOnVersion($f);

        $counts = $this->sync()->reconcileTenant();

        $this->assertSame(1, $counts['ingested']);
        $source = DB::table('ai_knowledge_sources')->where('customer_id', $f['customer_id'])->sole();
        $this->assertSame('active', $source->status);
        $this->assertSame('course_version_activity', $source->source_type);
        $this->assertSame($f['version_activity_id'], (int) $source->source_id);
        $this->assertSame($media, (int) $source->media_file_id);
        $this->assertNull($source->created_by);
        $this->assertSame('Bài nghe', $source->title);
        $this->assertSame(['Xin chào', 'Tạm biệt'], DB::table('ai_knowledge_chunks')
            ->where('knowledge_source_id', $source->id)->orderBy('sequence_no')->pluck('content')->all());

        $audit = DB::table('media_access_logs')->where('customer_id', $f['customer_id'])->sole();
        $this->assertNull($audit->user_id);
        $this->assertSame('ai_knowledge_sync', $audit->source_type);
        $this->assertSame('allowed', json_decode($audit->metadata, true)['decision']);

        // Nothing changed: no new registration and no new content read.
        $again = $this->sync()->reconcileTenant();
        $this->assertSame(0, $again['ingested']);
        $this->assertSame(1, $again['unchanged']);
        $this->assertDatabaseCount('ai_knowledge_sources', 1);
        $this->assertSame(1, DB::table('media_access_logs')->count());
        $this->assertDatabaseCount('ai_model_runs', 0);
        $this->assertDatabaseCount('ai_embeddings', 0);
    }

    public function test_draft_activity_and_unpublished_version_are_outside_the_corpus(): void
    {
        $f = $this->tenant('corpus');
        $this->audioOnVersion($f, 'draft_snapshot');
        $draftActivityMedia = $this->mediaFile($f, 'audio', 'draft');
        $this->usage($f, $draftActivityMedia, 'course_activity', 424242, 'audio');
        $this->transcriptRevision($f, $draftActivityMedia, 'stt-v1', 'd');

        $this->sync()->reconcileTenant();

        $this->assertDatabaseCount('ai_knowledge_sources', 0);
        $this->assertDatabaseCount('media_access_logs', 0);
    }

    public function test_deprecated_version_stays_in_the_corpus(): void
    {
        $f = $this->tenant('deprecated');
        $this->audioOnVersion($f, 'deprecated');

        $this->sync()->reconcileTenant();

        $this->assertSame('active', DB::table('ai_knowledge_sources')->value('status'));
    }

    public function test_a_new_revision_stales_the_previous_source(): void
    {
        $f = $this->tenant('revision');
        $media = $this->audioOnVersion($f);
        $this->sync()->reconcileTenant();
        $old = DB::table('ai_knowledge_sources')->value('id');

        DB::table('media_transcripts')->where('media_file_id', $media)->update(['status' => 'archived']);
        $this->transcriptRevision($f, $media, 'stt-v2', 'e', ['Phiên bản mới']);
        $this->sync()->reconcileTenant();

        $this->assertSame('stale', DB::table('ai_knowledge_sources')->where('id', $old)->value('status'));
        $current = DB::table('ai_knowledge_sources')->where('status', 'active')->sole();
        $this->assertSame('stt-v2', $current->processing_version);
        $this->assertSame(['Phiên bản mới'], DB::table('ai_knowledge_chunks')
            ->where('knowledge_source_id', $current->id)->pluck('content')->all());
    }

    public function test_revision_ready_event_syncs_only_the_media_it_names(): void
    {
        $f = $this->tenant('event-ready');
        $named = $this->audioOnVersion($f);
        $other = $this->audioOnVersion($f, 'published', 'Bài khác');

        (new SyncKnowledgeOfReadyMedia($this->sync()))->handle(new MediaRevisionReady($f['customer_id'], $named));

        $this->assertSame([$named], DB::table('ai_knowledge_sources')->pluck('media_file_id')->map(fn ($id) => (int) $id)->all());
        $this->assertNotSame($named, $other);
    }

    // ------------------------------------------------------------ Documents

    public function test_document_regions_are_the_corpus_and_tables_inside_them_are_not_duplicated(): void
    {
        $f = $this->tenant('regions');
        $media = $this->mediaFile($f, 'document', 'regions');
        $this->usage($f, $media, 'course_version_activity', $f['version_activity_id'], 'document');
        $job = $this->structuredJob($f, $media, 'docling-v1', 'f');
        $region = $this->region($f, $media, $job, 1, 'paragraph', 'Đoạn mở đầu');
        $tableRegion = $this->region($f, $media, $job, 2, 'table', 'A B 1 2');
        $this->table($f, $media, $job, $tableRegion, 'region', '1#2');

        $this->sync()->reconcileTenant();

        $source = DB::table('ai_knowledge_sources')->sole();
        $this->assertSame('region', $source->content_type);
        $this->assertSame(['Đoạn mở đầu', 'A B 1 2'], DB::table('ai_knowledge_chunks')
            ->orderBy('sequence_no')->pluck('content')->all());
        $this->assertNotNull($region);
    }

    public function test_a_spreadsheet_without_regions_uses_its_tables(): void
    {
        $f = $this->tenant('sheet');
        $media = $this->mediaFile($f, 'document', 'sheet');
        $this->usage($f, $media, 'course_version_activity', $f['version_activity_id'], 'document');
        $job = $this->structuredJob($f, $media, 'sheet-v1', 'a');
        $this->table($f, $media, $job, null, 'sheet', 'Sheet1');

        $this->sync()->reconcileTenant();

        $this->assertSame('table', DB::table('ai_knowledge_sources')->sole()->content_type);
    }

    // ------------------------------------------------------------- Archive

    public function test_detaching_the_usage_archives_the_source_and_reattaching_opens_a_new_generation(): void
    {
        $f = $this->tenant('detach');
        $media = $this->audioOnVersion($f);
        $this->sync()->reconcileTenant();
        $source = DB::table('ai_knowledge_sources')->sole();
        $chunk = (int) DB::table('ai_knowledge_chunks')->where('knowledge_source_id', $source->id)->value('id');
        $ready = $this->embedding($f, $chunk, 'ready');

        DB::table('media_file_usages')->where('media_file_id', $media)->update(['status' => 'detached']);
        $counts = $this->sync()->reconcileTenant();

        $this->assertSame(1, $counts['archived']);
        $this->assertSame('archived', DB::table('ai_knowledge_sources')->where('id', $source->id)->value('status'));
        $this->assertSame(['archived'], DB::table('ai_knowledge_chunks')->where('knowledge_source_id', $source->id)
            ->distinct()->pluck('status')->all());
        $this->assertNotNull(DB::table('ai_knowledge_chunks')->where('id', $chunk)->value('content'), 'Archive keeps provenance content.');
        $this->assertSame('stale', DB::table('ai_embeddings')->where('id', $ready)->value('status'));

        DB::table('media_file_usages')->where('media_file_id', $media)->update(['status' => 'active']);
        $this->sync()->reconcileTenant();

        $this->assertSame('archived', DB::table('ai_knowledge_sources')->where('id', $source->id)->value('status'), 'Archived never returns.');
        $revived = DB::table('ai_knowledge_sources')->where('status', 'active')->sole();
        $this->assertSame(2, (int) $revived->generation);
    }

    public function test_archiving_a_version_archives_its_sources_and_moves_pending_embeddings_to_deletion(): void
    {
        $f = $this->tenant('version-archive');
        $this->audioOnVersion($f);
        $this->sync()->reconcileTenant();
        $chunk = (int) DB::table('ai_knowledge_chunks')->value('id');
        $pending = $this->embedding($f, $chunk, 'pending');

        DB::table('core_course_template_versions')->where('id', $f['version_id'])->update(['status' => 'archived']);
        $this->sync()->reconcileTenant();

        $this->assertSame('archived', DB::table('ai_knowledge_sources')->value('status'));
        $this->assertSame('deletion_pending', DB::table('ai_embeddings')->where('id', $pending)->value('status'));
    }

    // -------------------------------------------------------------- Delete

    public function test_deleting_media_erases_chunk_content_and_keeps_minimal_provenance(): void
    {
        $f = $this->tenant('delete');
        $media = $this->audioOnVersion($f);
        $this->sync()->reconcileTenant();

        $this->deleteMedia($f, $media);
        (new EraseKnowledgeOfDeletedMedia($this->sync()))->handle(new MediaFileDeleted($f['customer_id'], $media));

        $source = DB::table('ai_knowledge_sources')->sole();
        $this->assertSame('deleted', $source->status);
        $this->assertSame('stt-v1', $source->processing_version);
        $this->assertSame(['deleted'], DB::table('ai_knowledge_chunks')->distinct()->pluck('status')->all());
        $this->assertSame([null], DB::table('ai_knowledge_chunks')->distinct()->pluck('content')->all());
    }

    public function test_deletion_waits_for_the_vector_purge_and_retries_until_acknowledged(): void
    {
        $f = $this->tenant('delete-vector');
        $media = $this->audioOnVersion($f);
        $this->sync()->reconcileTenant();
        $chunks = DB::table('ai_knowledge_chunks')->pluck('id');
        $embedding = $this->embedding($f, (int) $chunks[0], 'ready');
        $store = new FakeVectorStore;
        $store->failure = new \RuntimeException('LF_VECTOR_STORE_REQUEST_FAILED_503');
        $this->app->instance(VectorStore::class, $store);

        $this->deleteMedia($f, $media);
        $first = $this->sync()->reconcileTenant();

        $this->assertSame(1, $first['held_by_embedding_barrier']);
        $this->assertSame('deletion_pending', DB::table('ai_embeddings')->where('id', $embedding)->value('status'));
        $this->assertSame('deletion_pending', DB::table('ai_knowledge_sources')->value('status'));
        $this->assertNotNull(DB::table('ai_knowledge_chunks')->where('id', $chunks[0])->value('content'), 'Barrier keeps content until the vector is gone.');

        $store->failure = null;
        $second = $this->sync()->reconcileTenant();

        $this->assertSame(1, $second['vectors_deleted']);
        $this->assertSame('deleted', DB::table('ai_embeddings')->where('id', $embedding)->value('status'));
        $this->assertSame('deleted', DB::table('ai_knowledge_sources')->value('status'));
        $this->assertSame([null], DB::table('ai_knowledge_chunks')->distinct()->pluck('content')->all());
    }

    public function test_a_hand_prepared_draft_source_is_erased_and_archived_with_its_media(): void
    {
        $f = $this->tenant('draft-erase');
        $activity = $this->draftActivity($f);
        $media = $this->mediaFile($f, 'audio', 'draft-erase');
        $this->usage($f, $media, 'course_activity', $activity, 'audio');
        $source = $this->handPreparedSource($f, $media, $activity);

        $this->sync()->reconcileTenant();
        $this->assertSame('active', DB::table('ai_knowledge_sources')->where('id', $source)->value('status'), 'Draft usage is still held.');

        $this->deleteMedia($f, $media);
        $this->sync()->reconcileTenant();

        $this->assertSame('deleted', DB::table('ai_knowledge_sources')->where('id', $source)->value('status'));
        $this->assertNull(DB::table('ai_knowledge_chunks')->where('knowledge_source_id', $source)->value('content'));
    }

    public function test_repeated_deletion_events_are_idempotent(): void
    {
        $f = $this->tenant('delete-repeat');
        $media = $this->audioOnVersion($f);
        $this->sync()->reconcileTenant();
        $this->deleteMedia($f, $media);
        $listener = new EraseKnowledgeOfDeletedMedia($this->sync());

        $listener->handle(new MediaFileDeleted($f['customer_id'], $media));
        $deletedAt = DB::table('ai_knowledge_sources')->value('deleted_at');
        $listener->handle(new MediaFileDeleted($f['customer_id'], $media));
        $this->sync()->reconcileTenant();

        $this->assertSame($deletedAt, DB::table('ai_knowledge_sources')->value('deleted_at'));
        $this->assertDatabaseCount('ai_knowledge_sources', 1);
    }

    public function test_an_event_for_a_live_media_file_deletes_nothing(): void
    {
        $f = $this->tenant('delete-live');
        $media = $this->audioOnVersion($f);
        $this->sync()->reconcileTenant();

        (new EraseKnowledgeOfDeletedMedia($this->sync()))->handle(new MediaFileDeleted($f['customer_id'], $media));

        $this->assertSame('active', DB::table('ai_knowledge_sources')->value('status'));
    }

    // ------------------------------------------------ Independent review 1

    /**
     * Review F1: held sources must not occupy every finalize slot. With a batch
     * of one, a source still waiting on its vectors used to be re-selected on
     * every pass, so a later source with no embeddings was never erased.
     */
    public function test_finalize_does_not_starve_sources_already_free_of_embeddings(): void
    {
        config(['ai.knowledge_sync.deletion_limit' => 1]);
        $f = $this->tenant('starve');
        $held = $this->audioOnVersion($f);
        $free = $this->audioOnVersion($f, 'published', 'Bài sau');
        $this->sync()->reconcileTenant();
        [$heldSource, $freeSource] = DB::table('ai_knowledge_sources')->orderBy('id')->pluck('id')->all();
        $chunk = (int) DB::table('ai_knowledge_chunks')->where('knowledge_source_id', $heldSource)->value('id');
        $this->embedding($f, $chunk, 'pending');   // live writer: the barrier must hold

        $this->deleteMedia($f, $held);
        $this->deleteMedia($f, $free);
        $counts = $this->sync()->reconcileTenant();

        $this->assertSame(1, $counts['held_by_embedding_barrier']);
        $this->assertSame('deletion_pending', DB::table('ai_knowledge_sources')->where('id', $heldSource)->value('status'));
        $this->assertNotNull(DB::table('ai_knowledge_chunks')->where('knowledge_source_id', $heldSource)->value('content'));
        $this->assertSame('deleted', DB::table('ai_knowledge_sources')->where('id', $freeSource)->value('status'));
        $this->assertSame(0, DB::table('ai_knowledge_chunks')->where('knowledge_source_id', $freeSource)->whereNotNull('content')->count());
    }

    /**
     * Review F2: replacing draft content deletes the Activity but can leave its
     * generic usage row active. The usage alone must not keep the source alive.
     */
    public function test_a_removed_draft_activity_archives_its_hand_prepared_source(): void
    {
        $f = $this->tenant('draft-gone');
        $activity = $this->draftActivity($f);
        $media = $this->mediaFile($f, 'audio', 'draft-gone');
        $this->usage($f, $media, 'course_activity', $activity, 'audio');
        $source = $this->handPreparedSource($f, $media, $activity);

        DB::table('core_course_template_activities')->where('id', $activity)->delete();
        $this->assertSame('active', DB::table('media_file_usages')->where('media_file_id', $media)->value('status'));
        $this->sync()->reconcileTenant();

        $this->assertSame('archived', DB::table('ai_knowledge_sources')->where('id', $source)->value('status'));
    }

    /**
     * Review F3: a ready candidate that Media Read refuses is a defect to see,
     * not an empty pass. It is counted on every pass and logged once per window.
     */
    public function test_a_refused_ready_candidate_is_counted_and_logged_once_per_window(): void
    {
        $f = $this->tenant('candidate');
        $media = $this->audioOnVersion($f);
        DB::table('media_transcripts')->where('media_file_id', $media)->update(['locale' => 'invalid_locale']);
        Log::spy();

        $first = $this->sync()->reconcileTenant();
        $second = $this->sync()->reconcileTenant();

        $this->assertSame(1, $first['failed']);
        $this->assertSame(1, $second['failed']);
        $this->assertDatabaseCount('ai_knowledge_sources', 0);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $message === 'ai_knowledge_sync_ingest_failed'
            && $context['error_code'] === 'locale_unavailable' && $context['content_type'] === 'transcript'
            && ! array_key_exists('message', $context));
    }

    /** Review F4: `--dry-run` reports a pass without changing anything. */
    public function test_dry_run_reports_the_pass_and_writes_nothing(): void
    {
        $f = $this->tenant('dry-run');
        $archivable = $this->audioOnVersion($f);
        $deletable = $this->audioOnVersion($f, 'published', 'Bài xoá');
        $this->sync()->reconcileTenant();
        $this->audioOnVersion($f, 'published', 'Bài mới');     // not yet synced
        DB::table('media_file_usages')->where('media_file_id', $archivable)->update(['status' => 'detached']);
        $this->deleteMedia($f, $deletable);
        Cache::flush();
        $before = [
            DB::table('ai_knowledge_sources')->orderBy('id')->get(['id', 'status', 'updated_at'])->toJson(),
            DB::table('ai_knowledge_chunks')->orderBy('id')->get(['id', 'status', 'content'])->toJson(),
            DB::table('media_access_logs')->count(),
        ];

        $this->assertSame(0, Artisan::call('ai:knowledge-sync', ['--customer' => $f['customer_id'], '--dry-run' => true]));
        $output = Artisan::output();
        foreach (['[dry-run]', 'would_request_deletion=1', 'would_archive=1', 'would_ingest=1'] as $expected) {
            $this->assertStringContainsString($expected, $output);
        }

        $this->assertSame($before, [
            DB::table('ai_knowledge_sources')->orderBy('id')->get(['id', 'status', 'updated_at'])->toJson(),
            DB::table('ai_knowledge_chunks')->orderBy('id')->get(['id', 'status', 'content'])->toJson(),
            DB::table('media_access_logs')->count(),
        ]);
        $this->assertFalse(Cache::has('ai_knowledge_sync:owner_cursor:'.$f['customer_id']));
    }

    /**
     * Review round 2 F6: an owner Media Read refuses (here two active usages,
     * `ambiguous_source`) must not pin the dry-run cursor. With a batch of one,
     * that owner alone filled every batch and the command never returned.
     * The listener only turns a regression into a red test instead of a hang.
     */
    #[DataProvider('dryRunBatchSizes')]
    public function test_dry_run_moves_past_owners_that_fail_to_resolve(int $batch): void
    {
        config(['ai.knowledge_sync.owner_limit' => $batch]);
        $a = $this->tenant('f6-a');
        // Readable owner first, then two usages of one ambiguous owner. A batch
        // of two is then [good, bad] followed by [bad, bad]: a genuinely mixed
        // batch that ends on a failure, then a full batch of failures — the
        // shape on which a cursor pinned by failing owners re-reads forever
        // (review round 3, F7). A batch of one is [good], [bad], [bad].
        $readable = $this->audioOnVersion($a, 'published', 'Bài đọc được');
        $readableOwner = (int) DB::table('media_file_usages')->where('media_file_id', $readable)->value('owner_id');
        $this->audioOnVersion($a);
        $second = $this->mediaFile($a, 'audio', 'f6-second');
        $this->usage($a, $second, 'course_version_activity', $a['version_activity_id'], 'audio');
        TenantContext::set((object) ['id' => $a['customer_id']]);
        $this->assertSame(
            $batch === 1 ? [$readableOwner] : [$readableOwner, $a['version_activity_id']],
            array_column(app(MediaReadService::class)->knowledgeSyncOwners(0, $batch), 'owner_id'),
        );
        $b = $this->tenant('f6-b');
        $this->audioOnVersion($b);
        $listings = 0;
        DB::listen(function ($query) use (&$listings): void {
            if (str_contains($query->sql, 'core_course_template_versions') && str_contains($query->sql, 'media_file_usages')
                && str_contains($query->sql, 'order by') && ++$listings > 20) {
                throw new \RuntimeException('dry-run re-read the same owner batch');
            }
        });

        $exit = Artisan::call('ai:knowledge-sync', ['--dry-run' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('tenant_errors=0', $output);
        // Two refused usages of the ambiguous owner; one readable owner in A, one in B.
        $this->assertStringContainsString('candidate_errors=2', $output);
        $this->assertStringContainsString('would_ingest=2', $output);
        $this->assertDatabaseCount('ai_knowledge_sources', 0);
        $this->assertDatabaseCount('media_access_logs', 0);
        foreach ([$a, $b] as $f) {
            $this->assertFalse(Cache::has('ai_knowledge_sync:owner_cursor:'.$f['customer_id']));
        }
    }

    /** @return array<string,array{int}> */
    public static function dryRunBatchSizes(): array
    {
        return ['a batch made only of failing owners' => [1], 'a batch mixing failing and readable owners' => [2]];
    }

    /** Review M02: swapping the Activity's file archives exactly the old file's source. */
    public function test_replacing_the_media_archives_the_exact_previous_file(): void
    {
        $f = $this->tenant('swap');
        $old = $this->audioOnVersion($f);
        $this->sync()->reconcileTenant();
        DB::table('media_file_usages')->where('media_file_id', $old)->update(['status' => 'detached']);
        $new = $this->mediaFile($f, 'audio', 'swap-new');
        $this->usage($f, $new, 'course_version_activity', $f['version_activity_id'], 'audio');
        $this->transcriptRevision($f, $new, 'stt-v1', 'c');

        $this->sync()->reconcileTenant();

        $this->assertSame('archived', DB::table('ai_knowledge_sources')->where('media_file_id', $old)->value('status'));
        $this->assertSame('active', DB::table('ai_knowledge_sources')->where('media_file_id', $new)->value('status'));
    }

    /** Review M03: deleting the file erases every historical source, not only the current one. */
    public function test_deleting_media_erases_stale_archived_and_current_sources(): void
    {
        $f = $this->tenant('history');
        $media = $this->audioOnVersion($f);
        $this->sync()->reconcileTenant();
        DB::table('media_transcripts')->where('media_file_id', $media)->update(['status' => 'archived']);
        $this->transcriptRevision($f, $media, 'stt-v2', 'e');
        $this->sync()->reconcileTenant();                       // v1 stale, v2 active
        DB::table('media_file_usages')->where('media_file_id', $media)->update(['status' => 'detached']);
        $this->sync()->reconcileTenant();                       // both archived
        DB::table('media_file_usages')->where('media_file_id', $media)->update(['status' => 'active']);
        $this->sync()->reconcileTenant();                       // v2 generation 2 active
        $this->assertEqualsCanonicalizing(['archived', 'archived', 'active'], DB::table('ai_knowledge_sources')->pluck('status')->all());

        $this->deleteMedia($f, $media);
        $this->sync()->reconcileTenant();

        $this->assertSame(['deleted'], DB::table('ai_knowledge_sources')->distinct()->pluck('status')->all());
        $this->assertSame(0, DB::table('ai_knowledge_chunks')->whereNotNull('content')->count());
    }

    /** Review M04/M05: fingerprint alone, or processing version alone, is a new revision. */
    public function test_fingerprint_alone_and_version_alone_each_trigger_a_rebuild(): void
    {
        $f = $this->tenant('identity');
        $media = $this->audioOnVersion($f);                      // stt-v1 / 'b'
        $this->sync()->reconcileTenant();

        DB::table('media_transcripts')->where('media_file_id', $media)->update(['status' => 'archived']);
        $this->transcriptRevision($f, $media, 'stt-v1', 'c', offsetMs: 5000);   // same version, new fingerprint
        $this->assertSame(1, $this->sync()->reconcileTenant()['ingested']);

        DB::table('media_transcripts')->where('media_file_id', $media)->where('status', 'ready')->update(['status' => 'archived']);
        $this->transcriptRevision($f, $media, 'stt-v2', 'c');   // same fingerprint, new version
        $this->assertSame(1, $this->sync()->reconcileTenant()['ingested']);

        $this->assertSame(1, DB::table('ai_knowledge_sources')->where('status', 'active')->count());
        $this->assertSame(2, DB::table('ai_knowledge_sources')->where('status', 'stale')->count());
    }

    // ------------------------------------------------------ Tenant/authority

    public function test_tenants_are_synced_and_erased_in_isolation(): void
    {
        $a = $this->tenant('iso-a');
        $b = $this->tenant('iso-b');
        $mediaA = $this->audioOnVersion($a);
        $mediaB = $this->audioOnVersion($b);

        $this->artisan('ai:knowledge-sync', ['--customer' => $a['customer_id']])->assertSuccessful();
        $this->assertSame([$a['customer_id']], DB::table('ai_knowledge_sources')->pluck('customer_id')->map(fn ($id) => (int) $id)->all());

        // The owner listing is tenant-scoped itself, not only saved by the
        // eligibility re-check downstream: tenant A never even sees B's owners.
        TenantContext::set((object) ['id' => $a['customer_id']]);
        $this->assertSame([$mediaA], array_column(app(MediaReadService::class)->knowledgeSyncOwners(0, 100), 'media_file_id'));
        $this->assertSame(0, $this->sync()->reconcileTenant()['failed']);

        $this->artisan('ai:knowledge-sync')->assertSuccessful();
        $this->deleteMedia($b, $mediaB);
        // An event naming tenant A with tenant B's file must not reach B.
        (new EraseKnowledgeOfDeletedMedia($this->sync()))->handle(new MediaFileDeleted($a['customer_id'], $mediaB));
        $this->assertSame('active', DB::table('ai_knowledge_sources')->where('customer_id', $b['customer_id'])->value('status'));

        $this->artisan('ai:knowledge-sync', ['--customer' => $b['customer_id']])->assertSuccessful();
        $this->assertSame('deleted', DB::table('ai_knowledge_sources')->where('customer_id', $b['customer_id'])->value('status'));
        $this->assertSame('active', DB::table('ai_knowledge_sources')->where('customer_id', $a['customer_id'])->value('status'));
        $this->assertNotSame($mediaA, $mediaB);
    }

    public function test_the_system_principal_refuses_every_owner_outside_the_corpus(): void
    {
        $f = $this->tenant('principal');
        $reader = app(MediaReadService::class);
        // Same numeric id as a real published Version Activity: only the owner
        // type separates a draft activity from the corpus here.
        $draft = $this->mediaFile($f, 'audio', 'principal-draft');
        $this->usage($f, $draft, 'course_activity', $f['version_activity_id'], 'audio');
        $this->transcriptRevision($f, $draft, 'stt-v1', 'c');

        foreach ([
            fn () => $reader->readForKnowledgeSync('course_activity', $f['version_activity_id'], 'audio', 'transcript', 'vi'),
            fn () => $reader->revisionsForKnowledgeSync('course_activity', $f['version_activity_id'], 'audio', ['transcript']),
        ] as $call) {
            $this->assertMediaReadError('unauthorized', $call);
        }

        $media = $this->audioOnVersion($f, 'draft_snapshot');
        $this->assertMediaReadError('unauthorized', fn () => $reader->readForKnowledgeSync(
            'course_version_activity', $f['version_activity_id'], 'audio', 'transcript', 'vi'));

        DB::table('core_course_template_versions')->where('id', $f['version_id'])->update(['status' => 'published']);
        DB::table('media_file_usages')->where('media_file_id', $media)->update(['status' => 'detached']);
        $this->assertMediaReadError('detached', fn () => $reader->readForKnowledgeSync(
            'course_version_activity', $f['version_activity_id'], 'audio', 'transcript', 'vi'));

        DB::table('media_file_usages')->where('media_file_id', $media)->update(['status' => 'active']);
        $this->deleteMedia($f, $media);
        $this->assertMediaReadError('missing', fn () => $reader->readForKnowledgeSync(
            'course_version_activity', $f['version_activity_id'], 'audio', 'transcript', 'vi'));

        $this->assertMediaReadError('unsupported_source', fn () => $reader->readForKnowledgeSync(
            'course_version_activity', $f['version_activity_id'], 'video', 'caption_asset', 'vi'));
    }

    public function test_no_http_surface_reaches_the_system_principal(): void
    {
        foreach (['app/Http', 'routes'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir)));
            foreach ($files as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                    $this->assertStringNotContainsString('KnowledgeSync', (string) file_get_contents($file->getPathname()), $file->getPathname());
                }
            }
        }
    }

    public function test_synced_content_is_retrievable_only_by_readers_authorized_on_the_owner(): void
    {
        $f = $this->tenant('retrieve');
        $this->audioOnVersion($f);
        $this->sync()->reconcileTenant();
        config(['ai.embedding.provider' => 'fixture-provider', 'ai.embedding.model' => 'fixture-model', 'ai.embedding.dimensions' => 3]);
        $chunk = DB::table('ai_knowledge_chunks as c')->join('ai_knowledge_sources as s', 's.id', '=', 'c.knowledge_source_id')
            ->orderBy('c.sequence_no')->first(['c.id', 'c.content_hash', 's.identity_fingerprint', 's.identity_version']);
        $key = $this->uuid('retrieve-point');
        $this->embedding($f, (int) $chunk->id, 'ready', $key, 'sha256:'.hash('sha256', implode('|', [
            $chunk->content_hash, $chunk->identity_fingerprint, $chunk->identity_version, 'fixture-provider', 'fixture-model', '3',
        ])));
        $store = new FakeVectorStore;
        $store->searchResult = [$key];
        $retrieval = new AiKnowledgeRetrievalService($store, app(MediaReadService::class));

        $this->assertSame(['Xin chào'], array_column($retrieval->retrieve($f['admin_id'], [0.1, 0.2, 0.3]), 'content'));
        $this->assertSame([], $retrieval->retrieve($f['teacher_id'], [0.1, 0.2, 0.3]));
    }

    // --------------------------------------------------------- Retry/queue

    public function test_a_database_write_failure_is_counted_without_blocking_the_next_owner(): void
    {
        $f = $this->tenant('database-failure');
        $firstMedia = $this->audioOnVersion($f);
        $healthyMedia = $this->audioOnVersion($f, title: 'Healthy owner');
        $failOnce = true;
        DB::connection()->beforeExecuting(function ($query, $bindings, $connection) use (&$failOnce): void {
            if ($failOnce && str_starts_with(strtolower($query), 'insert into') && str_contains($query, 'ai_knowledge_chunks')) {
                $failOnce = false;
                throw new UniqueConstraintViolationException(
                    $connection->getName(), $query, $bindings, self::uniqueViolationPdo(),
                );
            }
        });
        Log::spy();
        $counts = $this->sync()->reconcileTenant();
        $this->assertSame(1, $counts['failed']);
        $this->assertSame(1, $counts['ingested']);
        $this->assertSame(0, DB::table('ai_knowledge_sources')->where('media_file_id', $firstMedia)->count());
        $this->assertSame(1, DB::table('ai_knowledge_sources')->where('media_file_id', $healthyMedia)->where('status', 'active')->count());
        Log::shouldHaveReceived('warning')->once()->with('ai_knowledge_sync_ingest_failed', Mockery::on(
            fn ($context) => $context['owner_id'] === $f['version_activity_id']
                && $context['error_code'] === 'database_write_failed'
                && ! str_contains(json_encode($context), 'private-source-content'),
        ));
    }

    /**
     * K3-R2: permission, connection, lock-wait and exhausted deadlock failures
     * are the database, not the revision. They stop the tenant pass instead of
     * being counted and skipped, and the exception that leaves carries neither
     * SQL, bindings nor the driver message.
     */
    #[DataProvider('systemicDatabaseFailures')]
    public function test_a_systemic_database_failure_stops_the_tenant_without_leaking_the_query(string $table, string $sqlState, int $driverCode): void
    {
        $f = $this->tenant('database-systemic');
        $this->audioOnVersion($f);
        $this->audioOnVersion($f, title: 'Second owner');
        $attempts = $this->failInsertsInto($table, $sqlState, $driverCode);
        // K3-R7: with argument collection on, every frame argument is kept in
        // the trace a reporter may serialize. Off is PHP's production default,
        // so the test forces the unsafe setting.
        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');

        try {
            $this->sync()->reconcileTenant();
            $this->fail('A systemic database failure must stop the tenant pass.');
        } catch (\RuntimeException $exception) {
            $this->assertNotInstanceOf(QueryException::class, $exception);
            $this->assertSame("Knowledge sync stopped on a database failure (SQLSTATE {$sqlState}, driver code {$driverCode}).", $exception->getMessage());
            $this->assertNull($exception->getPrevious(), 'The query exception holds SQL and bindings.');
            $this->assertStringNotContainsString('private-source-content', (string) $exception);
            $this->assertStringNotContainsString('insert into', strtolower((string) $exception));
            $this->assertArrayHasKey('args', $exception->getTrace()[0], 'Argument collection must be on for this check to mean anything.');
            foreach ($exception->getTrace() as $frame) {
                foreach ($frame['args'] ?? [] as $argument) {
                    $this->assertNotInstanceOf(\Throwable::class, $argument, 'No exception object may ride in a trace argument.');
                    if (is_string($argument)) {
                        $this->assertStringNotContainsString('private-source-content', $argument);
                        $this->assertStringNotContainsString('insert into', strtolower($argument));
                    }
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', $ignoreArgs === false ? '1' : $ignoreArgs);
        }
        $this->assertSame(1, $attempts(), 'The second owner is not attempted after a systemic failure.');
        $this->assertDatabaseCount('ai_knowledge_sources', 0);
    }

    /** @return array<string,array{string,string,int}> */
    public static function systemicDatabaseFailures(): array
    {
        return [
            'chunk write denied' => ['ai_knowledge_chunks', '42000', 1142],
            'deadlock retries exhausted' => ['ai_knowledge_chunks', '40001', 1213],
            'connection gone' => ['ai_knowledge_chunks', 'HY000', 2006],
            'lock wait timeout' => ['ai_knowledge_chunks', 'HY000', 1205],
            // register() used to turn any source insert failure into
            // registration_conflict, hiding the database behind a domain code.
            'source write denied' => ['ai_knowledge_sources', '42000', 1142],
        ];
    }

    /** A failure of the revision's own data is counted and the pass goes on. */
    #[DataProvider('revisionLocalDatabaseFailures')]
    public function test_a_revision_local_database_failure_is_counted_and_the_pass_continues(string $sqlState, int $driverCode): void
    {
        $f = $this->tenant('database-local');
        $this->audioOnVersion($f);
        $this->audioOnVersion($f, title: 'Second owner');
        $this->failInsertsInto('ai_knowledge_chunks', $sqlState, $driverCode, once: true);

        $counts = $this->sync()->reconcileTenant();

        $this->assertSame(1, $counts['failed']);
        $this->assertSame(1, $counts['ingested']);
    }

    /** @return array<string,array{string,int}> */
    public static function revisionLocalDatabaseFailures(): array
    {
        // SQLSTATE/driver pairs observed on MariaDB 11.4.12, except the last:
        // MySQL and older servers report 1366 under HY000, which only the
        // driver-code branch keeps revision-local.
        return [
            'CHECK violated (K3)' => ['23000', 4025],
            'value too long' => ['22001', 1406],
            'invalid string value (MariaDB 11.4)' => ['22007', 1366],
            'invalid string value (HY000)' => ['HY000', 1366],
        ];
    }

    /** The command reports a systemic failure as a tenant error and exits non-zero. */
    public function test_the_command_fails_the_tenant_on_a_systemic_database_failure(): void
    {
        $f = $this->tenant('database-command');
        $this->audioOnVersion($f);
        $this->failInsertsInto('ai_knowledge_chunks', 'HY000', 2006);

        $this->assertSame(1, Artisan::call('ai:knowledge-sync', ['--customer' => $f['customer_id']]));
        $output = Artisan::output();
        $this->assertStringContainsString('tenant_errors=1', $output);
        $this->assertStringNotContainsString('private-source-content', $output);
    }

    /** What the driver reports for a duplicate key, with a message standing in for Media text. */
    private static function uniqueViolationPdo(): \PDOException
    {
        $pdo = new \PDOException('private-source-content');
        $pdo->errorInfo = ['23000', 1062, 'private-source-content'];

        return $pdo;
    }

    /**
     * Makes INSERTs into one table fail with the given SQLSTATE and driver code;
     * the driver message stands in for Media text that must never surface.
     *
     * @return \Closure(): int the number of INSERTs attempted
     */
    private function failInsertsInto(string $table, string $sqlState, int $driverCode, bool $once = false): \Closure
    {
        $attempts = 0;
        DB::connection()->beforeExecuting(function ($query, $bindings, $connection) use ($table, $sqlState, $driverCode, $once, &$attempts): void {
            if (! str_starts_with(strtolower($query), 'insert into') || ! str_contains($query, $table.'"') && ! str_contains($query, $table.'`')) {
                return;
            }
            $attempts++;
            if ($once && $attempts > 1) {
                return;
            }
            $pdo = new \PDOException('private-source-content');
            $pdo->errorInfo = [$sqlState, $driverCode, 'private-source-content'];
            throw $sqlState === '23000' && $driverCode === 1062
                ? new UniqueConstraintViolationException($connection->getName(), $query, $bindings, $pdo)
                : new QueryException($connection->getName(), $query, $bindings, $pdo);
        });

        return function () use (&$attempts): int {
            return $attempts;
        };
    }

    /**
     * R2: a database failure that repeats is retried on every pass but logged
     * once per window; after the revision syncs, a new failure logs again.
     */
    public function test_a_repeating_database_failure_is_retried_every_pass_and_logged_once_per_window(): void
    {
        $f = $this->tenant('database-repeat');
        $media = $this->audioOnVersion($f);
        $failing = true;
        $attempts = 0;
        DB::connection()->beforeExecuting(function ($query, $bindings, $connection) use (&$failing, &$attempts): void {
            if (str_starts_with(strtolower($query), 'insert into') && str_contains($query, 'ai_knowledge_chunks')) {
                $attempts++;
                if ($failing) {
                    throw new UniqueConstraintViolationException(
                        $connection->getName(), $query, $bindings, self::uniqueViolationPdo(),
                    );
                }
            }
        });
        Log::spy();

        $first = $this->sync()->reconcileTenant();
        $second = $this->sync()->reconcileTenant();

        $this->assertSame(1, $first['failed']);
        $this->assertSame(1, $second['failed']);
        $this->assertSame(0, $second['backoff'], 'A database failure never stops the next attempt.');
        $this->assertSame(2, $attempts);
        Log::shouldHaveReceived('warning')->once();

        $failing = false;
        $this->assertSame(1, $this->sync()->reconcileTenant()['ingested']);
        $this->assertSame(1, DB::table('ai_knowledge_sources')->where('media_file_id', $media)->where('status', 'active')->count());

        // Recovery clears the window: a later failure of the same revision is
        // a new incident, not hidden behind the old one.
        $failing = true;
        DB::table('ai_knowledge_chunks')->where('customer_id', $f['customer_id'])->delete();
        DB::table('ai_knowledge_sources')->where('media_file_id', $media)->delete();
        $this->sync()->reconcileTenant();
        Log::shouldHaveReceived('warning')->twice();
    }

    public function test_a_permanent_failure_backs_off_while_a_new_revision_is_tried_at_once(): void
    {
        $f = $this->tenant('backoff');
        $media = $this->audioOnVersion($f);
        $ingestion = Mockery::mock(AiKnowledgeIngestionService::class)->makePartial();
        $ingestion->shouldReceive('ingestForSync')->twice()->andThrow(new AiKnowledgeIngestionException('mixed_revision'));
        $this->app->instance(AiKnowledgeIngestionService::class, $ingestion);

        $this->assertSame(1, $this->sync()->reconcileTenant()['failed']);
        $this->assertSame(1, $this->sync()->reconcileTenant()['backoff']);

        DB::table('media_transcripts')->where('media_file_id', $media)->update(['status' => 'archived']);
        $this->transcriptRevision($f, $media, 'stt-v2', 'e');
        $this->assertSame(1, $this->sync()->reconcileTenant()['failed'], 'A new revision is not held by the old backoff.');
    }

    public function test_both_listener_jobs_are_explicitly_after_commit(): void
    {
        Queue::fake();
        MediaFileDeleted::dispatch(1, 1);
        MediaRevisionReady::dispatch(1, 1);

        foreach ([EraseKnowledgeOfDeletedMedia::class, SyncKnowledgeOfReadyMedia::class] as $listener) {
            Queue::assertPushed(CallQueuedListener::class, fn (CallQueuedListener $job): bool => $job->class === $listener && $job->afterCommit === true);
        }
    }

    public function test_erasure_runs_after_the_outermost_commit_and_never_after_a_rollback(): void
    {
        $f = $this->tenant('after-commit');
        $media = $this->audioOnVersion($f);
        $this->sync()->reconcileTenant();
        $runs = 0;
        Queue::before(function (JobProcessing $event) use (&$runs): void {
            if (($event->job->payload()['displayName'] ?? null) === EraseKnowledgeOfDeletedMedia::class) {
                $runs++;
            }
        });

        try {
            DB::transaction(function () use ($f, $media): void {
                $this->deleteMedia($f, $media);
                MediaFileDeleted::dispatch($f['customer_id'], $media);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, $runs);
        $this->assertSame('active', DB::table('ai_knowledge_sources')->value('status'));

        DB::transaction(function () use ($f, $media, &$runs): void {
            $this->deleteMedia($f, $media);
            MediaFileDeleted::dispatch($f['customer_id'], $media);
            $this->assertSame(0, $runs, 'The job must not run inside the caller transaction.');
        });
        $this->assertSame(1, $runs);
        $this->assertSame('deleted', DB::table('ai_knowledge_sources')->value('status'));
    }

    public function test_the_command_is_scheduled_and_restores_the_tenant_context(): void
    {
        $f = $this->tenant('command');
        $this->audioOnVersion($f);
        $previous = (object) ['id' => 987654];
        TenantContext::set($previous);

        $this->artisan('ai:knowledge-sync')->assertSuccessful();

        $this->assertSame($previous, TenantContext::customer());
        $this->assertDatabaseCount('ai_knowledge_sources', 1);
        $this->assertStringContainsString('ai:knowledge-sync', (string) file_get_contents(base_path('routes/console.php')));
    }
}
