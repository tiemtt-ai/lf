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
 * ADR-0032 schema on MariaDB (SQLite ignores CHECK and some foreign keys): a Quiz Activity carries its frozen quiz
 * version, a course attempt carries its whole course context, and one Enrollment + Activity has one open attempt.
 */
class CourseQuizActivityMariaDbTest extends TestCase
{
    use RefreshDatabase;

    private int $customer;

    private int $otherCustomer;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires a throwaway MariaDB instance.');
        }
        if (DB::connection()->getDatabaseName() === 'learnforge_db') {
            throw new RuntimeException('Refused: run on a throwaway database.');
        }
        $tenant = fn (string $slug): int => DB::table('saas_customers')->insertGetId([
            'name' => $slug, 'slug' => $slug, 'subdomain' => $slug, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->customer = $tenant('tenant-a');
        $this->otherCustomer = $tenant('tenant-b');
        $this->student = User::forceCreate([
            'customer_id' => $this->customer, 'name' => 'Lan', 'email' => 'lan@example.test', 'password' => Hash::make('password123'),
            'role' => 'student', 'status' => 'active', 'email_verified_at' => now(),
        ]);
    }

    public function test_quiz_activities_and_course_attempts_are_enforced_by_the_schema(): void
    {
        $this->assertStringContainsString('MariaDB', DB::selectOne('select version() as v')->v);
        [$quiz, $version] = $this->quiz($this->customer);
        [, $foreignVersion] = $this->quiz($this->otherCustomer);
        [$product, $courseVersion, $lesson] = $this->course();
        $activity = fn (array $values): int => DB::table('core_course_template_version_activities')->insertGetId($values + [
            'customer_id' => $this->customer, 'template_version_id' => $courseVersion, 'version_lesson_id' => $lesson,
            'source_template_activity_id' => random_int(1000, 999999), 'title_snapshot' => 'Quiz', 'activity_type' => 'quiz',
            'sort_order' => 1, 'is_required' => true, 'completion_rule' => 'submit', 'unlock_rule_snapshot' => 'none',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertRejected(fn () => $activity(['assessment_quiz_id_snapshot' => $quiz]), 'chk_cctva_quiz_ref');
        $this->assertRejected(fn () => $activity(['activity_type' => 'document', 'completion_rule' => 'view',
            'assessment_quiz_id_snapshot' => $quiz, 'assessment_quiz_version_id_snapshot' => $version]), 'chk_cctva_quiz_ref');
        $this->assertRejected(fn () => $activity(['assessment_quiz_id_snapshot' => $quiz, 'assessment_quiz_version_id_snapshot' => $foreignVersion]), 'fk_cctva_quiz_version');
        $quizActivity = $activity(['assessment_quiz_id_snapshot' => $quiz, 'assessment_quiz_version_id_snapshot' => $version]);

        $enrollment = DB::table('core_course_enrollments')->insertGetId([
            'customer_id' => $this->customer, 'product_id' => $product, 'version_id' => $courseVersion, 'student_id' => $this->student->id,
            'source' => 'admin', 'enrolled_at' => now(), 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $attempt = fn (array $values): int => DB::table('core_assessment_attempts')->insertGetId($values + [
            'customer_id' => $this->customer, 'quiz_id' => $quiz, 'quiz_version_id' => $version, 'enrollment_id' => $enrollment,
            'product_id' => $product, 'template_version_id' => $courseVersion, 'version_activity_id' => $quizActivity,
            'user_id' => $this->student->id, 'attempt_no' => 1, 'status' => 'in_progress', 'grading_status' => 'not_required',
            'started_at' => now(), 'max_score' => 10, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertRejected(fn () => $attempt(['version_activity_id' => null]), 'chk_asm_att_course_ctx');
        $registration = DB::table('core_assessment_quiz_registrations')->insertGetId([
            'customer_id' => $this->customer, 'quiz_id' => $quiz, 'student_id' => $this->student->id, 'source' => 'self_registered',
            'status' => 'active', 'attempts_used' => 0, 'registered_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertRejected(fn () => $attempt(['quiz_registration_id' => $registration, 'enrollment_id' => null]), 'chk_asm_att_registration_ctx');

        $first = $attempt([]);
        $this->assertRejected(fn () => $attempt(['attempt_no' => 2]), 'uk_asm_att_enrollment_open');
        DB::table('core_assessment_attempts')->where('id', $first)->update(['status' => 'submitted', 'submitted_at' => now()]);
        $this->assertRejected(fn () => $attempt([]), 'uk_asm_att_enrollment_no');
        $this->assertGreaterThan($first, $attempt(['attempt_no' => 2]));

        // A quiz version used by a course cannot go.
        $this->assertRejected(fn () => DB::table('core_assessment_quiz_versions')->where('id', $version)->delete(), 'fk_');
    }

    private function assertRejected(callable $write, string $constraint): void
    {
        try {
            DB::transaction(fn () => $write());
        } catch (QueryException $e) {
            $this->assertStringContainsString($constraint, $e->getMessage());

            return;
        }
        $this->fail("Expected {$constraint} to reject the write.");
    }

    /** @return array{0: int, 1: int} */
    private function quiz(int $customer): array
    {
        $admin = User::forceCreate([
            'customer_id' => $customer, 'name' => 'Admin', 'email' => 'admin-'.uniqid().'@example.test', 'password' => Hash::make('password123'),
            'role' => 'customer_admin', 'status' => 'active', 'email_verified_at' => now(),
        ]);
        $quiz = DB::table('core_assessment_quizzes')->insertGetId([
            'customer_id' => $customer, 'created_by' => $admin->id, 'title' => 'Quiz', 'assessment_type' => 'exam', 'max_attempts' => 2,
            'show_result_mode' => 'immediately', 'status' => 'draft', 'registration_open' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $version = DB::table('core_assessment_quiz_versions')->insertGetId([
            'customer_id' => $customer, 'quiz_id' => $quiz, 'version_no' => 1, 'title' => 'Quiz', 'assessment_type' => 'exam',
            'max_attempts' => 2, 'show_result_mode' => 'immediately', 'total_points' => 10, 'question_count' => 1, 'sections_snapshot' => '[]',
            'published_by' => $admin->id, 'published_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('core_assessment_quizzes')->where('id', $quiz)->update(['status' => 'published', 'current_version_id' => $version]);

        return [$quiz, $version];
    }

    /** @return array{0: int, 1: int, 2: int} product, template version, version lesson */
    private function course(): array
    {
        $product = DB::table('core_course_products')->insertGetId([
            'customer_id' => $this->customer, 'product_code' => 'P-1', 'product_type' => 'single_course', 'title' => 'Course', 'slug' => 'course',
            'thumbnail_type' => 'image', 'price' => 0, 'currency' => 'VND', 'enrollment_type' => 'paid', 'enrollment_count' => 0,
            'is_certificate_enabled' => false, 'is_refundable' => false, 'show_enrollment_count' => true, 'is_featured' => false,
            'sort_order' => 0, 'visibility' => 'public', 'status' => 'active', 'published_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $template = DB::table('core_course_templates')->insertGetId([
            'course_type' => 'self_paced', 'customer_id' => $this->customer, 'title' => 'Course', 'estimated_minutes_per_lesson' => 0,
            'lesson_count' => 0, 'working_revision' => 1, 'status' => 'active', 'created_by' => $this->student->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $version = DB::table('core_course_template_versions')->insertGetId([
            'course_type_snapshot' => 'self_paced', 'customer_id' => $this->customer, 'template_id' => $template, 'version_number' => 1,
            'version_code' => 'V-1', 'is_current' => true, 'title_snapshot' => 'Course', 'estimated_minutes_per_lesson_snapshot' => 0,
            'lesson_count_snapshot' => 1, 'source_working_revision' => 1, 'status' => 'published', 'published_at' => now(),
            'published_by' => $this->student->id, 'source_template_updated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $lesson = DB::table('core_course_template_version_lessons')->insertGetId([
            'customer_id' => $this->customer, 'template_version_id' => $version, 'source_template_lesson_id' => 1,
            'title_snapshot' => 'Lesson', 'sort_order' => 1, 'unlock_rule_snapshot' => 'none', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$product, $version, $lesson];
    }
}
