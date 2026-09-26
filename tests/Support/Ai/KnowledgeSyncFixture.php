<?php

namespace Tests\Support\Ai;

use App\Exceptions\MediaReadException;
use App\Services\AiKnowledgeSyncService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Knowledge Sync fixtures: published Course Version Activities holding Media
 * whose ready output rows are inserted directly. Services under test read
 * them only through Media Read.
 */
trait KnowledgeSyncFixture
{
    protected function sync(): AiKnowledgeSyncService
    {
        return app(AiKnowledgeSyncService::class);
    }

    protected function assertMediaReadError(string $code, \Closure $call): void
    {
        try {
            $call();
            $this->fail("Expected Media Read error {$code}.");
        } catch (MediaReadException $exception) {
            $this->assertSame($code, $exception->errorCode);
        }
    }

    /** @return array<string,int> */
    protected function tenant(string $slug): array
    {
        $now = now();
        $customerId = DB::table('saas_customers')->insertGetId([
            'name' => "Sync {$slug}", 'slug' => "sync-{$slug}", 'subdomain' => "sync-{$slug}",
            'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $users = [];
        foreach (['admin' => 'customer_admin', 'teacher' => 'teacher'] as $key => $role) {
            $users[$key] = DB::table('users')->insertGetId([
                'customer_id' => $customerId, 'name' => ucfirst($key), 'email' => "{$key}-sync-{$slug}@example.test",
                'password' => bcrypt('password'), 'role' => $role, 'status' => 'active',
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $templateId = DB::table('core_course_templates')->insertGetId([
            'customer_id' => $customerId, 'title' => "Template {$slug}", 'estimated_minutes_per_lesson' => 0,
            'lesson_count' => 0, 'working_revision' => 1, 'status' => 'active', 'created_by' => $users['admin'],
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $versionId = DB::table('core_course_template_versions')->insertGetId([
            'customer_id' => $customerId, 'template_id' => $templateId, 'version_number' => 1,
            'version_code' => "V-{$slug}", 'is_current' => true, 'title_snapshot' => "Template {$slug}",
            'estimated_minutes_per_lesson_snapshot' => 0, 'lesson_count_snapshot' => 0,
            'source_working_revision' => 1, 'status' => 'published', 'published_at' => $now,
            'published_by' => $users['admin'], 'source_template_updated_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $lessonId = DB::table('core_course_template_version_lessons')->insertGetId([
            'customer_id' => $customerId, 'template_version_id' => $versionId, 'source_template_lesson_id' => 1,
            'title_snapshot' => 'Lesson', 'sort_order' => 1, 'is_preview' => false, 'duration_seconds' => 0,
            'activity_count' => 1, 'unlock_rule_snapshot' => 'none', 'created_at' => $now, 'updated_at' => $now,
        ]);
        TenantContext::set((object) ['id' => $customerId]);

        return [
            'customer_id' => $customerId, 'admin_id' => $users['admin'], 'teacher_id' => $users['teacher'],
            'template_id' => $templateId, 'version_id' => $versionId, 'lesson_id' => $lessonId,
            'version_activity_id' => $this->versionActivity($customerId, $versionId, $lessonId, 'Bài nghe'),
        ];
    }

    protected function versionActivity(int $customerId, int $versionId, int $lessonId, string $title): int
    {
        return DB::table('core_course_template_version_activities')->insertGetId([
            'customer_id' => $customerId, 'template_version_id' => $versionId, 'version_lesson_id' => $lessonId,
            'source_template_activity_id' => random_int(100000, 999999), 'title_snapshot' => $title,
            'sort_order' => 1, 'activity_type' => 'audio', 'duration_seconds' => 0, 'is_required' => true,
            'completion_rule' => 'manual', 'is_preview' => false, 'unlock_rule_snapshot' => 'none',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A real working (draft) Activity of the fixture Template. */
    protected function draftActivity(array $f): int
    {
        $lesson = DB::table('core_course_template_lessons')->insertGetId([
            'customer_id' => $f['customer_id'], 'template_id' => $f['template_id'], 'title' => 'Draft lesson',
            'sort_order' => 0, 'is_preview' => false, 'duration_seconds' => 0, 'activity_count' => 1,
            'unlock_rule' => 'none', 'created_by' => $f['admin_id'], 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('core_course_template_activities')->insertGetId([
            'customer_id' => $f['customer_id'], 'template_id' => $f['template_id'], 'template_lesson_id' => $lesson,
            'title' => 'Draft audio', 'activity_type' => 'audio', 'sort_order' => 1, 'created_by' => $f['admin_id'],
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param array<string,int> $f */
    protected function audioOnVersion(array &$f, string $versionStatus = 'published', ?string $title = null): int
    {
        DB::table('core_course_template_versions')->where('id', $f['version_id'])->update(['status' => $versionStatus]);
        $ownerId = $title === null ? $f['version_activity_id']
            : $this->versionActivity($f['customer_id'], $f['version_id'], $f['lesson_id'], $title);
        $media = $this->mediaFile($f, 'audio', 'audio-'.$ownerId);
        $this->usage($f, $media, 'course_version_activity', $ownerId, 'audio');
        $this->transcriptRevision($f, $media, 'stt-v1', 'b');

        return $media;
    }

    /** @param array<string,int> $f */
    protected function mediaFile(array $f, string $type, string $name): int
    {
        [$mime, $ext] = $type === 'audio' ? ['audio/mpeg', 'mp3'] : ['application/pdf', 'pdf'];

        return DB::table('media_files')->insertGetId([
            'customer_id' => $f['customer_id'], 'uploaded_by' => $f['admin_id'], 'file_type' => $type,
            'mime_type' => $mime, 'original_name' => "{$name}.{$ext}", 'display_name' => $name, 'extension' => $ext,
            'storage_disk' => 'media_local', 'storage_bucket' => 'test-media', 'storage_key' => "sync/{$f['customer_id']}/{$name}.{$ext}",
            'checksum' => 'sha256:'.$name, 'file_size_bytes' => 1, 'visibility' => 'private', 'status' => 'ready',
            'processing_locale' => 'vi', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param array<string,int> $f */
    protected function usage(array $f, int $media, string $ownerType, int $ownerId, string $usageType): void
    {
        DB::table('media_file_usages')->insert([
            'customer_id' => $f['customer_id'], 'media_file_id' => $media, 'owner_type' => $ownerType,
            'owner_id' => $ownerId, 'usage_type' => $usageType, 'status' => 'active', 'created_by' => $f['admin_id'],
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string,int>  $f
     * @param  array<int,string>  $texts
     */
    protected function transcriptRevision(array $f, int $media, string $version, string $fingerprintChar, array $texts = ['Xin chào', 'Tạm biệt'], int $offsetMs = 0): void
    {
        $fingerprint = str_repeat($fingerprintChar, 64);
        $job = $this->job($f, $media, 'speech_to_text', $version, $fingerprint, 'diarization=off;locale=vi');
        $first = null;
        foreach ($texts as $i => $text) {
            $id = DB::table('media_transcripts')->insertGetId([
                'customer_id' => $f['customer_id'], 'media_file_id' => $media, 'locale' => 'vi', 'provider' => 'fake',
                'status' => 'ready', 'text' => $text, 'processing_job_id' => $job, 'processing_version' => $version,
                'source_fingerprint' => $fingerprint, 'locator_type' => 'timespan',
                'locator_value' => ($offsetMs + $i * 1000).'-'.($offsetMs + ($i + 1) * 1000), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $first ??= $id;
        }
        $this->markReady($job, 'transcript', $first);
    }

    /** @param array<string,int> $f */
    protected function structuredJob(array $f, int $media, string $version, string $fingerprintChar): int
    {
        return $this->job($f, $media, 'structured_extraction', $version, str_repeat($fingerprintChar, 64), 'locale=vi;structure=layout');
    }

    /** @param array<string,int> $f */
    protected function job(array $f, int $media, string $type, string $version, string $fingerprint, string $profile): int
    {
        return DB::table('media_processing_jobs')->insertGetId([
            'customer_id' => $f['customer_id'], 'media_file_id' => $media, 'job_type' => $type, 'status' => 'processing',
            'attempt' => 1, 'provider' => 'fake', 'idempotency_key' => $this->uuid("job-{$media}-{$version}-{$type}-{$fingerprint}"),
            'correlation_id' => $this->uuid("corr-{$media}-{$version}-{$type}-{$fingerprint}"), 'source_fingerprint' => $fingerprint,
            'processing_version' => $version, 'output_profile' => $profile, 'output_profile_hash' => hash('sha256', $profile),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** chk_mpj_ready: a ready job names its output, as the worker leaves it. */
    protected function markReady(int $job, string $outputType, int $outputId): void
    {
        DB::table('media_processing_jobs')->where('id', $job)->update([
            'status' => 'ready', 'output_type' => $outputType, 'output_id' => $outputId, 'completed_at' => now(),
        ]);
    }

    /** @param array<string,int> $f */
    protected function region(array $f, int $media, int $job, int $order, string $role, string $text): int
    {
        $jobRow = DB::table('media_processing_jobs')->where('id', $job)->first();

        $id = DB::table('media_extracted_regions')->insertGetId([
            'customer_id' => $f['customer_id'], 'media_file_id' => $media, 'processing_job_id' => $job, 'locale' => 'vi',
            'locator_type' => 'region', 'locator_value' => "1#{$order}", 'page' => 1, 'ordinal' => $order,
            'reading_order' => $order, 'role' => $role, 'text' => $text, 'char_count' => mb_strlen($text),
            'extraction_method' => 'embedded_text', 'provider' => 'fake', 'processing_version' => $jobRow->processing_version,
            'source_fingerprint' => $jobRow->source_fingerprint, 'status' => 'ready', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->markReady($job, 'extracted_region', $id);

        return $id;
    }

    /** @param array<string,int> $f */
    protected function table(array $f, int $media, int $job, ?int $region, string $locatorType, string $locator): void
    {
        $jobRow = DB::table('media_processing_jobs')->where('id', $job)->first();
        $table = DB::table('media_extracted_tables')->insertGetId([
            'customer_id' => $f['customer_id'], 'media_file_id' => $media, 'processing_job_id' => $job, 'region_id' => $region,
            'locale' => 'vi', 'locator_type' => $locatorType, 'locator_value' => $locator, 'sequence' => 1,
            'row_count' => 1, 'column_count' => 2, 'has_header' => false,
            'extraction_method' => $locatorType === 'sheet' ? 'spreadsheet_cells' : 'embedded_text', 'provider' => 'fake',
            'processing_version' => $jobRow->processing_version, 'source_fingerprint' => $jobRow->source_fingerprint,
            'status' => 'ready', 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($region === null) {
            $this->markReady($job, 'extracted_table', $table);
        }
        foreach (['A', 'B'] as $column => $text) {
            DB::table('media_table_cells')->insert([
                'customer_id' => $f['customer_id'], 'extracted_table_id' => $table, 'row_index' => 1,
                'column_index' => $column + 1, 'text' => $text, 'char_count' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** @param array<string,int> $f */
    protected function handPreparedSource(array $f, int $media, int $ownerId): int
    {
        $source = DB::table('ai_knowledge_sources')->insertGetId([
            'customer_id' => $f['customer_id'], 'source_uuid' => $this->uuid("hand-{$media}"), 'source_type' => 'course_activity',
            'source_id' => $ownerId, 'media_file_id' => $media, 'usage_type' => 'audio', 'generation' => 1,
            'content_type' => 'transcript', 'title' => 'Bản nháp', 'locale' => 'vi', 'source_fingerprint' => str_repeat('9', 64),
            'processing_version' => 'stt-hand', 'status' => 'active', 'created_by' => $f['admin_id'],
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('ai_knowledge_chunks')->insert([
            'customer_id' => $f['customer_id'], 'knowledge_source_id' => $source, 'chunk_uuid' => $this->uuid("hand-chunk-{$media}"),
            'sequence_no' => 1, 'content' => 'Nội dung nháp', 'content_hash' => 'sha256:hand', 'char_start' => 0, 'char_end' => 13,
            'locator_type' => 'timespan', 'locator_start' => '0', 'locator_end' => '1000', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $source;
    }

    /** @param array<string,int> $f */
    protected function embedding(array $f, int $chunk, string $status, ?string $key = null, string $hash = 'sha256:fixture'): int
    {
        $run = DB::table('ai_model_runs')->insertGetId([
            'customer_id' => $f['customer_id'], 'run_uuid' => $this->uuid("run-{$chunk}-{$status}"),
            'prompt_hash' => 'sha256:fixture', 'purpose' => 'knowledge_embedding', 'provider' => 'fixture-provider',
            'model' => 'fixture-model', 'status' => $status === 'pending' ? 'running' : 'completed',
            'completed_at' => $status === 'pending' ? null : now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('ai_embeddings')->insertGetId([
            'customer_id' => $f['customer_id'], 'knowledge_chunk_id' => $chunk, 'model_run_id' => $run,
            'provider' => 'fixture-provider', 'model' => 'fixture-model', 'dimensions' => 3, 'vector_store' => 'qdrant',
            'vector_index' => 'lf_text_fixture_model', 'vector_key' => $key ?? $this->uuid("embedding-{$chunk}-{$status}"),
            'embedding_hash' => $hash, 'status' => $status, 'embedded_at' => $status === 'ready' ? now() : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param array<string,int> $f */
    protected function deleteMedia(array $f, int $media): void
    {
        // The Media tombstone as MediaService leaves it; the listener is driven
        // explicitly so each test controls when erasure runs.
        DB::table('media_files')->where('customer_id', $f['customer_id'])->where('id', $media)
            ->update(['status' => 'deleted', 'updated_at' => now()]);
    }

    protected function uuid(string $seed): string
    {
        $hex = substr(hash('sha256', $seed), 0, 32);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-4'.substr($hex, 13, 3)
            .'-8'.substr($hex, 17, 3).'-'.substr($hex, 20, 12);
    }
}
