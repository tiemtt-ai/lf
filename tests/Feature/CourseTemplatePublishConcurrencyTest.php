<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CourseTemplatePublishConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_template_lock_serializes_same_template_but_not_another_template(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['mysql', 'pgsql'], true)) {
            $this->markTestSkipped('SQLite does not implement SELECT ... FOR UPDATE row-lock semantics.');
        }

        $customerId = DB::table('saas_customers')->insertGetId([
            'name' => 'Concurrency Tenant',
            'slug' => 'concurrency',
            'subdomain' => 'concurrency',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $firstTemplateId = $this->createTemplate($customerId, 'First Template');
        $secondTemplateId = $this->createTemplate($customerId, 'Second Template');

        $baseConfig = config('database.connections.'.config('database.default'));
        config(['database.connections.content_lock_probe' => $baseConfig]);
        $publisher = DB::connection();
        $mutation = DB::connection('content_lock_probe');

        try {
            $publisher->beginTransaction();
            $publisher->table('core_course_templates')
                ->where('customer_id', $customerId)
                ->where('id', $firstTemplateId)
                ->lockForUpdate()
                ->first();

            $mutation->beginTransaction();
            $this->setShortLockTimeout($mutation, $driver);
            $this->assertNotNull($mutation->table('core_course_templates')
                ->where('customer_id', $customerId)
                ->where('id', $secondTemplateId)
                ->lockForUpdate()
                ->first());

            try {
                $mutation->table('core_course_templates')
                    ->where('customer_id', $customerId)
                    ->where('id', $firstTemplateId)
                    ->lockForUpdate()
                    ->first();
                $this->fail('A content mutation acquired the Template row while publish held it.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        } finally {
            if ($mutation->transactionLevel() > 0) {
                $mutation->rollBack();
            }
            if ($publisher->transactionLevel() > 0) {
                $publisher->rollBack();
            }
            DB::purge('content_lock_probe');
        }
    }

    private function setShortLockTimeout(object $connection, string $driver): void
    {
        if ($driver === 'mysql') {
            $connection->statement('SET SESSION innodb_lock_wait_timeout = 1');

            return;
        }

        $connection->statement("SET LOCAL lock_timeout = '1s'");
    }

    private function createTemplate(int $customerId, string $title): int
    {
        return DB::table('core_course_templates')->insertGetId([
            'customer_id' => $customerId,
            'category_id' => null,
            'title' => $title,
            'short_description' => null,
            'description' => null,
            'publisher_name' => 'LearnForge',
            'intro_image_media_file_id' => null,
            'intro_video_source' => null,
            'intro_video_media_file_id' => null,
            'intro_video_embed_url' => null,
            'intro_video_provider' => null,
            'intro_document_media_file_id' => null,
            'difficulty_level' => null,
            'estimated_minutes_per_lesson' => null,
            'estimated_lesson_count' => null,
            'lesson_count' => 0,
            'meta_title' => null,
            'meta_description' => null,
            'meta_keywords' => null,
            'working_revision' => 1,
            'status' => 'draft',
            'created_by' => null,
            'last_version_published_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
