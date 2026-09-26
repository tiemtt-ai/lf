<?php

namespace Tests\Integration;

use App\Services\AiKnowledgeIngestionService;
use App\Support\TenantContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Ai\KnowledgeSyncFixture;
use Tests\TestCase;

/**
 * Knowledge Sync on the physical schema: the whole lifecycle under MariaDB
 * CHECK/FK constraints, and the race where another worker registers the same
 * revision between the sync's metadata check and its ingest.
 */
class AiKnowledgeSyncMariaDbTest extends TestCase
{
    use KnowledgeSyncFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Knowledge Sync physical checks require MariaDB.');
        }
    }

    protected function tearDown(): void
    {
        TenantContext::set(null);
        parent::tearDown();
    }

    public function test_create_stale_archive_and_delete_hold_under_the_physical_constraints(): void
    {
        $f = $this->tenant('maria-life');
        $media = $this->audioOnVersion($f);

        $this->sync()->reconcileTenant();
        $first = DB::table('ai_knowledge_sources')->sole();
        $this->assertNull($first->created_by);
        $this->assertNull(DB::table('media_access_logs')->value('user_id'));

        DB::table('media_transcripts')->where('media_file_id', $media)->update(['status' => 'archived']);
        $this->transcriptRevision($f, $media, 'stt-v2', 'e', ['Mới']);
        $this->sync()->reconcileTenant();
        $this->assertSame('stale', DB::table('ai_knowledge_sources')->where('id', $first->id)->value('status'));

        DB::table('media_file_usages')->where('media_file_id', $media)->update(['status' => 'detached']);
        $this->sync()->reconcileTenant();
        $this->assertSame(['archived'], DB::table('ai_knowledge_sources')->distinct()->pluck('status')->all());
        $this->assertSame(['archived'], DB::table('ai_knowledge_chunks')->distinct()->pluck('status')->all());

        $this->deleteMedia($f, $media);
        $this->sync()->reconcileTenant();
        $this->assertSame(['deleted'], DB::table('ai_knowledge_sources')->distinct()->pluck('status')->all());
        $this->assertSame(0, DB::table('ai_knowledge_chunks')->whereNotNull('content')->count());
        $this->assertSame(0, DB::table('ai_knowledge_sources')->whereNull('deletion_requested_at')->count());
    }

    public function test_a_revision_registered_by_another_worker_mid_pass_is_reused_not_duplicated(): void
    {
        $f = $this->tenant('maria-race');
        $this->audioOnVersion($f);
        $injected = false;

        // Fires once the sync's own "already active?" check has answered no:
        // exactly the window a concurrent listener or command would use.
        DB::listen(function (QueryExecuted $query) use (&$injected): void {
            if ($injected || ! str_contains($query->sql, 'from `ai_knowledge_sources`')
                || ! str_contains($query->sql, 'select exists')) {
                return;
            }
            $injected = true;
            app(AiKnowledgeIngestionService::class)->ingestForSync(
                'course_version_activity', (int) DB::table('media_file_usages')->value('owner_id'),
                'audio', 'transcript', 'vi', 'Worker khác',
            );
        });

        $counts = $this->sync()->reconcileTenant();

        $this->assertTrue($injected, 'The concurrent registration was never simulated.');
        $this->assertSame(1, $counts['ingested']);
        $source = DB::table('ai_knowledge_sources')->sole();
        $this->assertSame('active', $source->status);
        $this->assertSame(1, (int) $source->generation);
        $this->assertSame(2, DB::table('ai_knowledge_chunks')->where('knowledge_source_id', $source->id)->count());
    }

    public function test_archive_takes_a_real_row_lock_on_the_source(): void
    {
        $f = $this->tenant('maria-lock');
        $media = $this->audioOnVersion($f);
        $this->sync()->reconcileTenant();
        DB::table('media_file_usages')->where('media_file_id', $media)->update(['status' => 'detached']);
        $locks = [];
        DB::listen(function (QueryExecuted $query) use (&$locks): void {
            if (str_contains($query->sql, 'from `ai_knowledge_sources`') && str_contains($query->sql, 'for update')) {
                $locks[] = $query->sql;
            }
        });

        $this->sync()->reconcileTenant();

        $this->assertNotEmpty($locks);
        $this->assertSame('archived', DB::table('ai_knowledge_sources')->value('status'));
    }
}
