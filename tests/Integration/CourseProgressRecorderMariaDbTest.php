<?php

namespace Tests\Integration;

use App\Models\User;
use App\Services\CourseProgressRecorder;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * ADR-0030 D3 on MariaDB: progress events of one Enrollment arriving at the same time
 * (two tabs, a player and a click) create each Progress row once and count every event.
 * Workers are forked processes, so the data is committed (no RefreshDatabase): the database
 * is migrated once and the rows are removed afterwards.
 */
class CourseProgressRecorderMariaDbTest extends TestCase
{
    private const TABLES = ['core_course_activity_progress', 'core_course_lesson_progress', 'core_course_progress', 'core_course_enrollments',
        'core_course_template_version_activities', 'core_course_template_version_lessons', 'core_course_template_versions',
        'core_course_templates', 'core_course_products', 'users', 'saas_customers'];

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires a throwaway MariaDB instance.');
        }
        if (DB::connection()->getDatabaseName() === 'learnforge_db' || ! function_exists('pcntl_fork')) {
            throw new RuntimeException('Refused: run on a throwaway database with pcntl.');
        }
        $this->artisan('migrate', ['--force' => true]);
        $this->cleanUp();
    }

    private function cleanUp(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (self::TABLES as $table) {
            DB::table($table)->delete();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    protected function tearDown(): void
    {
        TenantContext::set(null);
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->cleanUp();
        }
        parent::tearDown();
    }

    public function test_simultaneous_events_create_each_progress_row_once_and_count_all_of_them(): void
    {
        $this->assertStringContainsString('MariaDB', DB::selectOne('select version() as v')->v);
        $fixture = $this->fixture();

        // Four events of the same Enrollment start together, before any Progress row exists.
        $results = $this->together([
            [$fixture['activities'][0], 'opened'],
            [$fixture['activities'][1], 'completed'],
            [$fixture['activities'][2], 'opened'],
            [$fixture['activities'][1], 'completed'],
        ], $fixture);

        $this->assertSame(['completed', 'completed', 'completed', 'completed'], array_column($results, 'status'), json_encode($results));
        $enrollment = $fixture['enrollment'];
        $this->assertSame(1, DB::table('core_course_progress')->where('enrollment_id', $enrollment)->count());
        $this->assertSame(1, DB::table('core_course_lesson_progress')->where('enrollment_id', $enrollment)->count());
        $this->assertSame(3, DB::table('core_course_activity_progress')->where('enrollment_id', $enrollment)->count());
        $lesson = DB::table('core_course_lesson_progress')->where('enrollment_id', $enrollment)->sole();
        $this->assertSame(['completed', 3], [$lesson->status, (int) $lesson->completed_activities]);
        $course = DB::table('core_course_progress')->where('enrollment_id', $enrollment)->sole();
        $this->assertSame(['completed', 100, 3], [$course->status, (int) $course->progress_percentage, (int) $course->completed_activities]);
    }

    /** @return list<array{status: string, error?: string}> */
    private function together(array $events, array $fixture): array
    {
        DB::disconnect();
        $workers = [];
        foreach ($events as [$activity, $event]) {
            [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            $pid = pcntl_fork();
            if ($pid === 0) {
                fclose($parent);
                fread($child, 1);
                DB::purge();
                try {
                    TenantContext::set(DB::table('saas_customers')->find($fixture['customer']));
                    $row = app(CourseProgressRecorder::class)->record($fixture['customer'], $fixture['student'], $fixture['enrollment'], $activity, $event);
                    $result = ['status' => $row ? (string) $row->status : 'refused'];
                } catch (Throwable $exception) {
                    $result = ['status' => 'error', 'error' => $exception->getMessage()];
                }
                fwrite($child, json_encode($result));
                fclose($child);
                exit(0);
            }
            fclose($child);
            $workers[] = ['pid' => $pid, 'socket' => $parent];
        }
        foreach ($workers as $worker) {
            fwrite($worker['socket'], 'g');
        }

        $results = [];
        foreach ($workers as $worker) {
            stream_set_timeout($worker['socket'], 20);
            $payload = stream_get_contents($worker['socket']);
            fclose($worker['socket']);
            pcntl_waitpid($worker['pid'], $status);
            $results[] = json_decode((string) $payload, true) ?? ['status' => 'no_answer'];
        }
        DB::reconnect();

        return $results;
    }

    private function fixture(): array
    {
        $customer = DB::table('saas_customers')->insertGetId([
            'name' => 'tenant-a', 'slug' => 'tenant-a', 'subdomain' => 'tenant-a', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = fn (string $role): User => User::forceCreate([
            'customer_id' => $customer, 'name' => ucfirst($role), 'email' => $role.'-'.uniqid().'@example.test',
            'password' => Hash::make('password123'), 'role' => $role, 'status' => 'active', 'email_verified_at' => now(),
        ]);
        $admin = $user('customer_admin');
        $student = $user('student');
        $product = DB::table('core_course_products')->insertGetId([
            'customer_id' => $customer, 'product_code' => 'P-1', 'product_type' => 'single_course', 'title' => 'Tự học', 'slug' => 'tu-hoc',
            'thumbnail_type' => 'image', 'price' => 0, 'currency' => 'VND', 'enrollment_type' => 'paid', 'enrollment_count' => 0,
            'is_certificate_enabled' => false, 'is_refundable' => false, 'show_enrollment_count' => true, 'is_featured' => false,
            'sort_order' => 0, 'visibility' => 'public', 'status' => 'active', 'published_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $template = DB::table('core_course_templates')->insertGetId([
            'course_type' => 'self_paced', 'customer_id' => $customer, 'title' => 'Tự học', 'estimated_minutes_per_lesson' => 0,
            'lesson_count' => 1, 'working_revision' => 1, 'status' => 'active', 'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $version = DB::table('core_course_template_versions')->insertGetId([
            'course_type_snapshot' => 'self_paced', 'customer_id' => $customer, 'template_id' => $template, 'version_number' => 1,
            'version_code' => 'V-1', 'is_current' => true, 'title_snapshot' => 'Tự học', 'estimated_minutes_per_lesson_snapshot' => 0,
            'lesson_count_snapshot' => 1, 'source_working_revision' => 1, 'status' => 'published', 'published_at' => now(),
            'published_by' => $admin->id, 'source_template_updated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $lesson = DB::table('core_course_template_version_lessons')->insertGetId([
            'customer_id' => $customer, 'template_version_id' => $version, 'source_template_lesson_id' => 1, 'title_snapshot' => 'Bài 1',
            'sort_order' => 1, 'unlock_rule_snapshot' => 'none', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $activities = [];
        foreach (['view', 'manual', 'view'] as $i => $rule) {
            $activities[] = DB::table('core_course_template_version_activities')->insertGetId([
                'customer_id' => $customer, 'template_version_id' => $version, 'version_lesson_id' => $lesson,
                'source_template_activity_id' => $i + 1, 'title_snapshot' => 'Hoạt động '.($i + 1), 'activity_type' => 'document',
                'sort_order' => $i + 1, 'is_required' => true, 'completion_rule' => $rule, 'unlock_rule_snapshot' => 'none',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $enrollment = DB::table('core_course_enrollments')->insertGetId([
            'customer_id' => $customer, 'product_id' => $product, 'version_id' => $version, 'student_id' => $student->id,
            'source' => 'admin', 'enrolled_at' => now(), 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['customer' => $customer, 'student' => $student->id, 'enrollment' => $enrollment, 'activities' => $activities];
    }
}
