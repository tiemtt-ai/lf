<?php

namespace Tests\Integration;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * ADR-0035 schema on MariaDB (SQLite ignores column widths): the link of an external link Activity and its published
 * snapshot hold up to 1000 characters, sit after the embedded video link, and the migration's down refuses while an
 * external link Activity is left.
 */
class CourseExternalLinkActivityMariaDbTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_11_19_000100_add_external_link_to_course_activities.php';

    private int $customer;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires a throwaway MariaDB instance.');
        }
        if (DB::connection()->getDatabaseName() === 'learnforge_db') {
            throw new RuntimeException('Refused: run on a throwaway database.');
        }
        $this->customer = DB::table('saas_customers')->insertGetId([
            'name' => 'tenant-a', 'slug' => 'tenant-a', 'subdomain' => 'tenant-a', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_link_columns_hold_1000_characters_and_down_refuses_while_links_are_left(): void
    {
        $this->assertStringContainsString('MariaDB', DB::selectOne('select version() as v')->v);
        foreach ([['core_course_template_activities', 'external_link_url', 'external_video_url'],
            ['core_course_template_version_activities', 'external_link_url_snapshot', 'external_video_url_snapshot']] as [$table, $column, $after]) {
            $columns = collect(DB::select('select column_name as name, column_type as type, is_nullable as nullable, ordinal_position as position
                from information_schema.columns where table_schema = database() and table_name = ?', [$table]))->keyBy('name');
            $this->assertSame(['varchar(1000)', 'YES'], [$columns[$column]->type, $columns[$column]->nullable], $table);
            $this->assertSame((int) $columns[$after]->position + 1, (int) $columns[$column]->position, $table);
        }

        [$template, $lesson, $version, $versionLesson] = $this->course();
        $fits = 'https://wings.example.test/'.str_repeat('a', 1000 - 27);
        $working = fn (string $url): int => DB::table('core_course_template_activities')->insertGetId([
            'customer_id' => $this->customer, 'template_id' => $template, 'template_lesson_id' => $lesson, 'title' => 'Wings',
            'sort_order' => 0, 'activity_type' => 'external_link', 'external_link_url' => $url, 'duration_seconds' => 0,
            'is_required' => true, 'completion_rule' => 'view', 'is_preview' => false, 'unlock_rule' => 'none',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $snapshot = fn (string $url): int => DB::table('core_course_template_version_activities')->insertGetId([
            'customer_id' => $this->customer, 'template_version_id' => $version, 'version_lesson_id' => $versionLesson,
            'source_template_activity_id' => random_int(1000, 999999), 'title_snapshot' => 'Wings', 'activity_type' => 'external_link',
            'external_link_url_snapshot' => $url, 'sort_order' => 1, 'is_required' => true, 'completion_rule' => 'view',
            'unlock_rule_snapshot' => 'none', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame(1000, mb_strlen($fits));
        $this->assertSame($fits, DB::table('core_course_template_activities')->where('id', $working($fits))->value('external_link_url'));
        $this->assertSame($fits, DB::table('core_course_template_version_activities')->where('id', $snapshot($fits))->value('external_link_url_snapshot'));
        $this->assertTooLong(fn () => $working($fits.'b'));
        $this->assertTooLong(fn () => $snapshot($fits.'b'));

        $migration = require base_path(self::MIGRATION);
        try {
            $migration->down();
            $this->fail('Expected down to refuse while external link Activities are left.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('external_link', $e->getMessage());
        }
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('core_course_template_activities', 'external_link_url'));
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('core_course_template_version_activities', 'external_link_url_snapshot'));
    }

    private function assertTooLong(callable $write): void
    {
        try {
            DB::transaction(fn () => $write());
        } catch (QueryException $e) {
            $this->assertStringContainsString('Data too long', $e->getMessage());

            return;
        }
        $this->fail('Expected a link over 1000 characters to be rejected.');
    }

    /** @return array{0: int, 1: int, 2: int, 3: int} template, lesson, template version, version lesson */
    private function course(): array
    {
        $admin = User::forceCreate([
            'customer_id' => $this->customer, 'name' => 'Admin', 'email' => 'admin@example.test', 'password' => Hash::make('password123'),
            'role' => 'customer_admin', 'status' => 'active', 'email_verified_at' => now(),
        ])->id;
        $template = DB::table('core_course_templates')->insertGetId([
            'course_type' => 'self_paced', 'customer_id' => $this->customer, 'title' => 'Course', 'estimated_minutes_per_lesson' => 0,
            'lesson_count' => 0, 'working_revision' => 1, 'status' => 'active', 'created_by' => $admin, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $lesson = DB::table('core_course_template_lessons')->insertGetId([
            'customer_id' => $this->customer, 'template_id' => $template, 'title' => 'Lesson', 'sort_order' => 0, 'is_preview' => false,
            'duration_seconds' => 0, 'activity_count' => 0, 'unlock_rule' => 'none', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $version = DB::table('core_course_template_versions')->insertGetId([
            'course_type_snapshot' => 'self_paced', 'customer_id' => $this->customer, 'template_id' => $template, 'version_number' => 1,
            'version_code' => 'V-1', 'is_current' => true, 'title_snapshot' => 'Course', 'estimated_minutes_per_lesson_snapshot' => 0,
            'lesson_count_snapshot' => 1, 'source_working_revision' => 1, 'status' => 'published', 'published_at' => now(),
            'published_by' => $admin, 'source_template_updated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $versionLesson = DB::table('core_course_template_version_lessons')->insertGetId([
            'customer_id' => $this->customer, 'template_version_id' => $version, 'source_template_lesson_id' => $lesson,
            'title_snapshot' => 'Lesson', 'sort_order' => 1, 'unlock_rule_snapshot' => 'none', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$template, $lesson, $version, $versionLesson];
    }
}
