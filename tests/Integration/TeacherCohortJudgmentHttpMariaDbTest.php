<?php

namespace Tests\Integration;

use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Teacher Judgment surface over HTTP (Course Scope Amendment, 2026-10-11),
 * with browser-shaped payloads: strings throughout, a datetime-local value and
 * a score in percent.
 */
class TeacherCohortJudgmentHttpMariaDbTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Teacher Judgment over HTTP requires MariaDB.');
        }
        $this->withoutVite();
        TenantContext::set(null);
        // The fixture cohort runs through August 2026; pin the clock inside it.
        Carbon::setTestNow('2026-08-20 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        TenantContext::set(null);
        parent::tearDown();
    }

    public function test_my_classes_lists_only_the_teachers_cohorts_and_explains_blocked_ones(): void
    {
        $f = $this->fixture('list');
        $unmapped = $this->cohort($f, 'Unmapped cohort', mapped: false);
        $foreign = $this->cohort($f, 'Foreign cohort', mapped: true, teacherId: $f['other_teacher_id']);

        $html = $this->as($f['teacher_id'])->get($f['host'].'/teacher/cohorts')->assertOk()->getContent();

        $this->assertStringContainsString('data-teacher-cohort="'.$f['cohort_id'].'"', $html);
        $this->assertStringContainsString('href="'.$f['host'].'/teacher/cohorts/'.$f['cohort_id'].'/judgments"', $html);
        $this->assertStringContainsString('data-teacher-cohort="'.$unmapped.'"', $html);
        $this->assertStringContainsString('Khoá học của lớp chưa gắn năng lực khi xuất bản', $html);
        $this->assertStringContainsString('Chấm năng lực', $html);
        $this->assertStringNotContainsString('href="'.$f['host'].'/teacher/cohorts/'.$unmapped.'/judgments"', $html, 'A class without competencies has nothing to open.');
        $this->assertStringNotContainsString('data-teacher-cohort="'.$foreign.'"', $html, 'Another teacher’s cohort is not listed.');
        $this->assertStringContainsString('role="tooltip"', $html);
    }

    public function test_the_judgment_page_lists_mapped_competencies_first_with_their_lessons(): void
    {
        $f = $this->fixture('page');

        $html = $this->as($f['teacher_id'])->get($f['host']."/teacher/cohorts/{$f['cohort_id']}/judgments")->assertOk()->getContent();

        $mappedAt = strpos($html, 'data-teacher-judgment-skill="'.$f['mapped_node_id'].'"');
        $otherAt = strpos($html, 'data-teacher-judgment-skill="'.$f['other_node_id'].'"');
        $this->assertNotFalse($mappedAt);
        $this->assertNotFalse($otherAt);
        $this->assertLessThan($otherAt, $mappedAt, 'Option A: mapped competencies come first.');
        $this->assertStringContainsString('Bài nghe nói 1', $html, 'The mapping snapshot labels the lesson.');
        $this->assertStringContainsString('data-teacher-judgment-student="'.$f['membership_id'].'"', $html);
        $this->assertStringContainsString('name="score_percent"', $html);
        $this->assertGreaterThanOrEqual(4, substr_count($html, 'role="tooltip"'));
    }

    public function test_a_percent_score_is_saved_as_a_fraction_with_an_explicit_offset(): void
    {
        $f = $this->fixture('save');

        $this->as($f['teacher_id'])->post($f['host']."/teacher/cohorts/{$f['cohort_id']}/judgments", $this->payload($f, [
            'level' => 'mastered', 'score_percent' => '85',
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $row = DB::table('core_liveclass_teacher_judgments')->where('customer_id', $f['customer_id'])->firstOrFail();
        $this->assertSame('mastered', $row->mastery_level_key);
        $this->assertSame('0.850000', (string) $row->mastery_score);
        $this->assertSame($f['teacher_id'], (int) $row->teacher_id);
        $this->assertSame($f['assignment_id'], (int) $row->cohort_teacher_assignment_id, 'The assignment comes from the teacher’s own cohort.');
        $this->assertStringStartsWith('2026-08-20 09:30:00', (string) $row->occurred_at);
        $this->assertSame(1, DB::table('core_learning_mastery_profiles')->where('customer_id', $f['customer_id'])->count());

        $html = $this->as($f['teacher_id'])->get($f['host']."/teacher/cohorts/{$f['cohort_id']}/judgments?node={$f['mapped_node_id']}")->getContent();
        $this->assertStringContainsString('85%', $html);
        $this->assertStringContainsString('Chấm lại', $html);
    }

    public function test_a_level_that_contradicts_the_score_is_refused_readably(): void
    {
        $f = $this->fixture('mismatch');

        $this->as($f['teacher_id'])->post($f['host']."/teacher/cohorts/{$f['cohort_id']}/judgments", $this->payload($f, [
            'level' => 'novice', 'score_percent' => '90',
        ]))->assertSessionHasErrors(['judgment' => 'Mức không khớp với điểm đã nhập, hoặc mức không thuộc thang đánh giá.']);

        $this->assertSame(0, DB::table('core_liveclass_teacher_judgments')->where('customer_id', $f['customer_id'])->count());
    }

    public function test_a_competency_outside_the_course_framework_version_is_refused(): void
    {
        $f = $this->fixture('scope');
        $outside = $this->unmappedPublishedVersion($f);

        $this->as($f['teacher_id'])->post($f['host']."/teacher/cohorts/{$f['cohort_id']}/judgments", $this->payload($f, [
            'basis_id' => (string) $outside['basis_id'], 'node_id' => (string) $outside['node_id'],
        ]))->assertSessionHasErrors(['judgment' => 'Năng lực này không thuộc bộ chuẩn mà khoá học của lớp đã gắn.']);

        $this->assertSame(0, DB::table('core_liveclass_teacher_judgments')->where('customer_id', $f['customer_id'])->count());
    }

    public function test_only_the_assigned_teacher_reaches_the_cohort(): void
    {
        $f = $this->fixture('deny');
        $url = $f['host']."/teacher/cohorts/{$f['cohort_id']}/judgments";

        $this->as($f['other_teacher_id'])->get($url)->assertNotFound();
        $this->as($f['other_teacher_id'])->post($url, $this->payload($f))->assertNotFound();
        $this->as($f['admin_id'])->get($url)->assertForbidden();
        $this->as($f['student_id'])->get($url)->assertForbidden();
        $this->assertSame(0, DB::table('core_liveclass_teacher_judgments')->count());
    }

    public function test_a_student_from_another_cohort_cannot_be_judged_here(): void
    {
        $f = $this->fixture('member');
        $otherCohort = $this->cohort($f, 'Second cohort', mapped: true);
        $otherMembership = (int) DB::table('core_course_cohort_students')->where('cohort_id', $otherCohort)->value('id');

        $this->as($f['teacher_id'])->post($f['host']."/teacher/cohorts/{$f['cohort_id']}/judgments", $this->payload($f, [
            'membership_id' => (string) $otherMembership,
        ]))->assertSessionHasErrors('membership_id');

        $this->assertSame(0, DB::table('core_liveclass_teacher_judgments')->count());
    }

    public function test_counts_ignore_students_who_left_and_the_form_starts_at_joining(): void
    {
        $f = $this->fixture('counts');
        DB::table('core_course_cohort_students')->where('id', $f['membership_id'])->update(['joined_at' => '2026-08-05 08:30:00']);
        $this->as($f['teacher_id'])->post($f['host']."/teacher/cohorts/{$f['cohort_id']}/judgments", $this->payload($f, [
            'level' => 'mastered', 'score_percent' => '90',
        ]))->assertSessionHasNoErrors();

        $html = $this->as($f['teacher_id'])->get($f['host']."/teacher/cohorts/{$f['cohort_id']}/judgments")->getContent();
        $this->assertStringContainsString('1/1 học viên đã chấm', $html);
        $this->assertStringContainsString('min="2026-08-05T08:30"', $html, 'The earliest observation is the student’s joining, not the class start.');

        // The student leaves: the judgment stays in history but no longer counts for the class.
        DB::table('core_course_cohort_students')->where('id', $f['membership_id'])->update(['left_at' => '2026-08-19 10:00:00', 'status' => 'removed']);
        $html = $this->as($f['teacher_id'])->get($f['host']."/teacher/cohorts/{$f['cohort_id']}/judgments")->getContent();
        $this->assertStringNotContainsString('học viên đã chấm', $html);
        $this->assertSame(1, DB::table('core_liveclass_teacher_judgments')->where('customer_id', $f['customer_id'])->count());
    }

    // ------------------------------------------------------------------ Fixtures

    private function as(int $userId): self
    {
        $this->actingAs(User::findOrFail($userId));

        return $this;
    }

    /** @param array<string, mixed> $f */
    private function payload(array $f, array $override = []): array
    {
        return array_merge([
            'submission_uuid' => (string) Str::uuid(),
            'membership_id' => (string) $f['membership_id'],
            'basis_id' => (string) $f['basis_id'],
            'node_id' => (string) $f['mapped_node_id'],
            'level' => 'novice',
            'score_percent' => '',
            'reason' => 'Observed during the speaking exercise.',
            'occurred_at' => '2026-08-20T09:30',
        ], $override);
    }

    /** @return array<string, mixed> */
    private function fixture(string $slug): array
    {
        $now = now();
        $slug = 'tjh-'.$slug;
        $customerId = DB::table('saas_customers')->insertGetId([
            'name' => $slug, 'slug' => $slug, 'subdomain' => $slug, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $f = ['customer_id' => $customerId, 'host' => "https://{$slug}.localhost", 'slug' => $slug];
        foreach (['admin' => 'customer_admin', 'teacher' => 'teacher', 'other_teacher' => 'teacher', 'student' => 'student'] as $key => $role) {
            $f[$key.'_id'] = (int) DB::table('users')->insertGetId([
                'customer_id' => $customerId, 'name' => ucfirst(str_replace('_', ' ', $key)), 'email' => "{$key}@{$slug}.test",
                'password' => bcrypt('password'), 'role' => $role, 'status' => 'active', 'email_verified_at' => $now,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $scale = json_encode(['levels' => [['key' => 'novice', 'threshold' => 0], ['key' => 'mastered', 'threshold' => 0.8]]], JSON_THROW_ON_ERROR);
        $f['scale'] = $scale;
        $f['framework_id'] = (int) DB::table('core_learning_frameworks')->insertGetId([
            'customer_id' => $customerId, 'code' => "fw-{$slug}", 'name' => 'Korean', 'default_mastery_scale_key' => 'direct',
            'default_mastery_scale_version' => '1', 'default_mastery_scale' => $scale, 'status' => 'active',
            'created_by' => $f['admin_id'], 'updated_by' => $f['admin_id'], 'created_at' => $now, 'updated_at' => $now,
        ]);
        $version = $this->publishedVersion($f, 1, ['MAPPED' => 'Giao tiếp sơ cấp', 'OTHER' => 'Đọc viết sơ cấp']);
        $f['basis_id'] = $version['basis_id'];
        $f['mapped_node_id'] = $version['nodes']['MAPPED'];
        $f['other_node_id'] = $version['nodes']['OTHER'];

        $f['cohort_id'] = $this->cohort($f, 'Sơ cấp 1-1', mapped: true);
        $f['assignment_id'] = (int) DB::table('core_course_cohort_teachers')->where('cohort_id', $f['cohort_id'])->where('teacher_id', $f['teacher_id'])->value('id');
        $f['membership_id'] = (int) DB::table('core_course_cohort_students')->where('cohort_id', $f['cohort_id'])->value('id');

        return $f;
    }

    /**
     * A published Course Version, product, active cohort taught by the teacher and one enrolled student.
     * When mapped, the Course Version maps the fixture's MAPPED Node to a lesson at publication.
     *
     * @param  array<string, mixed>  $f
     */
    private function cohort(array $f, string $name, bool $mapped, ?int $teacherId = null): int
    {
        $now = now();
        $key = substr(md5($name.microtime()), 0, 8);
        $templateId = DB::table('core_course_templates')->insertGetId([
            'customer_id' => $f['customer_id'], 'title' => "Template {$name}", 'lesson_count' => 0, 'sort_order' => 1,
            'working_revision' => 1, 'status' => 'published', 'created_by' => $f['admin_id'], 'created_at' => $now, 'updated_at' => $now,
        ]);
        $versionId = DB::table('core_course_template_versions')->insertGetId([
            'customer_id' => $f['customer_id'], 'template_id' => $templateId, 'version_number' => 1, 'version_code' => "v1-{$key}",
            'is_current' => true, 'title_snapshot' => "Template {$name}", 'lesson_count_snapshot' => 0, 'source_working_revision' => 1,
            'status' => 'published', 'published_at' => $now, 'published_by' => $f['admin_id'], 'source_template_updated_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $productId = DB::table('core_course_products')->insertGetId([
            'customer_id' => $f['customer_id'], 'product_code' => "P-{$key}", 'product_type' => 'course', 'title' => "Tiếng Hàn {$name}",
            'slug' => "product-{$key}", 'thumbnail_type' => 'none', 'price' => 0, 'currency' => 'VND', 'enrollment_type' => 'manual',
            'enrollment_count' => 0, 'is_certificate_enabled' => false, 'is_refundable' => false, 'show_enrollment_count' => false,
            'is_featured' => false, 'sort_order' => 0, 'visibility' => 'private', 'status' => 'active', 'created_by' => $f['admin_id'],
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $teacher = $teacherId ?? $f['teacher_id'];
        $cohortId = DB::table('core_course_cohorts')->insertGetId([
            'customer_id' => $f['customer_id'], 'product_id' => $productId, 'version_id' => $versionId, 'teacher_id' => $teacher,
            'name' => $name, 'code' => "C-{$key}", 'status' => 'active', 'start_date' => '2026-08-01', 'end_date' => '2026-08-31',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('core_course_cohort_teachers')->insert([
            'customer_id' => $f['customer_id'], 'cohort_id' => $cohortId, 'teacher_id' => $teacher, 'role' => 'teacher',
            'assigned_from' => '2026-08-01', 'assigned_to' => '2026-08-31', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $enrollmentId = DB::table('core_course_enrollments')->insertGetId([
            'customer_id' => $f['customer_id'], 'product_id' => $productId, 'version_id' => $versionId, 'student_id' => $f['student_id'],
            'source' => 'admin', 'enrolled_by' => $f['admin_id'], 'enrolled_at' => '2026-08-01 00:00:00', 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('core_course_cohort_students')->insert([
            'customer_id' => $f['customer_id'], 'cohort_id' => $cohortId, 'enrollment_id' => $enrollmentId, 'product_id' => $productId,
            'student_id' => $f['student_id'], 'assigned_by' => $f['admin_id'], 'joined_at' => '2026-08-01 00:00:00', 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        if ($mapped) {
            DB::table('core_learning_node_mappings')->insert([
                'customer_id' => $f['customer_id'], 'learning_node_id' => $f['mapped_node_id'], 'source_type' => 'course_version_lesson',
                'source_id' => 1, 'source_discriminator' => (string) $versionId, 'mapping_role' => 'teaches', 'weight' => null,
                'source_snapshot' => json_encode(['label' => 'Bài nghe nói 1', 'source_type' => 'course_version_lesson',
                    'course_version_id' => $versionId, 'source_version_identity' => (string) $versionId], JSON_THROW_ON_ERROR),
                'created_by' => $f['admin_id'], 'created_at' => $now,
            ]);
        }

        return (int) $cohortId;
    }

    /**
     * @param  array<string, mixed>  $f
     * @param  array<string, string>  $nodes  code => name
     * @return array{basis_id:int, nodes:array<string,int>}
     */
    private function publishedVersion(array $f, int $number, array $nodes): array
    {
        $now = now();
        $basisId = (int) DB::table('core_learning_framework_versions')->insertGetId([
            'customer_id' => $f['customer_id'], 'framework_id' => $f['framework_id'], 'version_number' => $number,
            'version_code' => "v{$number}", 'title_snapshot' => "V{$number}", 'mastery_scale_key' => 'direct',
            'mastery_scale_version' => '1', 'mastery_scale_snapshot' => $f['scale'], 'status' => 'draft_snapshot',
            'created_by' => $f['admin_id'], 'updated_by' => $f['admin_id'], 'created_at' => $now, 'updated_at' => $now,
        ]);
        $ids = [];
        $sequence = 0;
        foreach ($nodes as $code => $name) {
            $definitionId = DB::table('core_learning_node_definitions')->where('framework_id', $f['framework_id'])->where('code', $code)->value('id')
                ?? DB::table('core_learning_node_definitions')->insertGetId([
                    'customer_id' => $f['customer_id'], 'framework_id' => $f['framework_id'], 'code' => $code, 'node_type' => 'competency',
                    'canonical_name' => $name, 'status' => 'active', 'created_by' => $f['admin_id'], 'updated_by' => $f['admin_id'],
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            $ids[$code] = (int) DB::table('core_learning_nodes')->insertGetId([
                'customer_id' => $f['customer_id'], 'framework_id' => $f['framework_id'], 'framework_version_id' => $basisId,
                'node_definition_id' => $definitionId, 'code_snapshot' => $code, 'name_snapshot' => $name, 'sequence' => ++$sequence,
                'status' => 'active', 'created_by' => $f['admin_id'], 'updated_by' => $f['admin_id'], 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        DB::table('core_learning_framework_versions')->where('id', $basisId)->update([
            'status' => 'published', 'published_at' => $now, 'published_by' => $f['admin_id'],
        ]);

        return ['basis_id' => $basisId, 'nodes' => $ids];
    }

    /** @param array<string, mixed> $f */
    private function unmappedPublishedVersion(array $f): array
    {
        $version = $this->publishedVersion($f, 2, ['MAPPED' => 'Giao tiếp sơ cấp']);

        return ['basis_id' => $version['basis_id'], 'node_id' => $version['nodes']['MAPPED']];
    }
}
