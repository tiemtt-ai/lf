<?php

namespace Tests\Integration;

use App\Services\TeacherJudgmentService;
use App\Support\TenantContext;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TeacherJudgmentRuntimeMariaDbTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Teacher Judgment physical verification requires MariaDB.');
        }

        TenantContext::set(null);
    }

    protected function tearDown(): void
    {
        TenantContext::set(null);
        parent::tearDown();
    }

    public function test_submit_replay_and_correction_are_atomic_and_append_only(): void
    {
        $context = $this->context('flow');
        TenantContext::set((object) ['id' => $context['customer_id']]);
        $service = app(TeacherJudgmentService::class);

        $first = $service->submit($context['teacher_id'], $this->command($context));
        $replay = $service->submit($context['teacher_id'], $this->command($context));

        $this->assertFalse($first->replayed);
        $this->assertTrue($replay->replayed);
        $this->assertSame($first->judgment_id, $replay->judgment_id);
        $this->assertSame(1, DB::table('core_liveclass_teacher_judgments')->count());
        $this->assertSame(1, DB::table('core_learning_evidence')->count());
        $this->assertSame(1, DB::table('core_learning_mastery_calculations')->count());
        $this->assertSame(1, DB::table('core_learning_calculation_evidence')->count());
        $this->assertSame(1, DB::table('core_learning_mastery_profiles')->count());

        $secondTeacher = $this->user(
            $context['customer_id'],
            'teacher-correction-flow@example.test',
            'teacher',
        );
        $secondAssignment = $this->assignment($context, $secondTeacher);
        $correction = $service->submit($secondTeacher, $this->command($context, [
            'submission_uuid' => '22222222-2222-4222-8222-222222222222',
            'cohort_teacher_assignment_id' => $secondAssignment,
            'mastery_level_key' => 'mastered',
            'mastery_score' => 0.9,
            'reason' => 'Correction by another eligible teacher.',
            'supersedes_judgment_id' => $first->judgment_id,
        ]));

        $this->assertFalse($correction->replayed);
        $this->assertSame(2, DB::table('core_liveclass_teacher_judgments')->count());
        $this->assertSame($first->evidence_id, (int) DB::table('core_learning_evidence')
            ->where('id', $correction->evidence_id)->value('supersedes_evidence_id'));
        $this->assertSame($first->calculation_id, (int) DB::table('core_learning_mastery_calculations')
            ->where('id', $correction->calculation_id)->value('source_calculation_id'));
        $this->assertSame($correction->calculation_id, (int) DB::table('core_learning_mastery_profiles')
            ->value('current_calculation_id'));
    }

    public function test_same_uuid_with_changed_payload_is_rejected_without_new_rows(): void
    {
        $context = $this->context('idempotency');
        TenantContext::set((object) ['id' => $context['customer_id']]);
        $service = app(TeacherJudgmentService::class);
        $service->submit($context['teacher_id'], $this->command($context));

        try {
            $service->submit($context['teacher_id'], $this->command($context, [
                'reason' => 'Changed payload under the same producer UUID.',
            ]));
            $this->fail('Changed payload must not be accepted as an idempotent replay.');
        } catch (DomainException $exception) {
            $this->assertSame('LF_TEACHER_JUDGMENT_IDEMPOTENCY_CONFLICT', $exception->getMessage());
        }

        $this->assertSame(1, DB::table('core_liveclass_teacher_judgments')->count());
        $this->assertSame(1, DB::table('core_learning_evidence')->count());
        $this->assertSame(1, DB::table('core_learning_mastery_calculations')->count());
    }

    public function test_application_owned_authorization_rules_fail_closed(): void
    {
        foreach (['cohort', 'enrollment', 'membership', 'assignment', 'basis'] as $case) {
            $context = $this->context("deny-{$case}");
            TenantContext::set((object) ['id' => $context['customer_id']]);

            match ($case) {
                'cohort' => DB::table('core_course_cohorts')->where('id', $context['cohort_id'])
                    ->update(['status' => 'closed']),
                'enrollment' => DB::table('core_course_enrollments')->where('id', $context['enrollment_id'])
                    ->update(['status' => 'cancelled']),
                'membership' => DB::table('core_course_cohort_students')->where('id', $context['membership_id'])
                    ->update(['status' => 'inactive']),
                'assignment' => DB::table('core_course_cohort_teachers')->where('id', $context['assignment_id'])
                    ->update(['status' => 'inactive']),
                'basis' => DB::table('core_learning_framework_versions')->where('id', $context['basis_id'])
                    ->update([
                        'status' => 'deprecated', 'deprecated_at' => now(),
                        'deprecated_by' => $context['admin_id'],
                    ]),
            };

            try {
                app(TeacherJudgmentService::class)->submit(
                    $context['teacher_id'],
                    $this->command($context),
                );
                $this->fail("{$case} must fail closed.");
            } catch (AuthorizationException|DomainException $exception) {
                $this->assertStringStartsWith('LF_TEACHER_JUDGMENT_', $exception->getMessage());
            }

            $this->assertSame(0, DB::table('core_liveclass_teacher_judgments')
                ->where('customer_id', $context['customer_id'])->count());
        }
    }

    public function test_late_calculation_failure_rolls_back_source_and_evidence_rows(): void
    {
        $context = $this->context('rollback');
        TenantContext::set((object) ['id' => $context['customer_id']]);
        DB::table('core_learning_mastery_calculations')->insert([
            'customer_id' => $context['customer_id'], 'user_id' => $context['student_id'],
            'framework_id' => $context['framework_id'],
            'node_definition_id' => $context['definition_id'],
            'basis_framework_version_id' => $context['basis_id'],
            'calculation_source' => 'teacher_override',
            'calculation_idempotency_key' => '11111111-1111-4111-8111-111111111111',
            'mastery_level_key' => 'novice', 'mastery_score' => '0.500000',
            'calculation_rule_key' => 'test-conflict', 'calculation_rule_version' => '1',
            'calculation_rule_snapshot' => json_encode([
                'rule_key' => 'test-conflict', 'rule_version' => '1',
                'source_type' => 'teacher_judgment',
            ], JSON_THROW_ON_ERROR),
            'mastery_scale_key' => 'direct', 'mastery_scale_version' => '1',
            'mastery_scale_snapshot' => $context['scale'],
            'mastery_status_result' => 'established',
            'reason' => 'Seeded conflict', 'calculated_by' => $context['admin_id'],
            'calculated_at' => '2026-08-15 08:00:00.000000', 'created_at' => now(),
        ]);

        try {
            app(TeacherJudgmentService::class)->submit(
                $context['teacher_id'],
                $this->command($context),
            );
            $this->fail('A late Calculation uniqueness failure must abort the transaction.');
        } catch (QueryException $exception) {
            $this->assertSame('23000', $exception->errorInfo[0] ?? null);
        }

        $this->assertSame(0, DB::table('core_liveclass_teacher_judgments')->count());
        $this->assertSame(0, DB::table('core_learning_evidence')->count());
        $this->assertSame(1, DB::table('core_learning_mastery_calculations')->count());
        $this->assertSame(0, DB::table('core_learning_calculation_evidence')->count());
        $this->assertSame(0, DB::table('core_learning_mastery_profiles')->count());
    }

    private function context(string $suffix): array
    {
        $now = now();
        $customerId = DB::table('saas_customers')->insertGetId([
            'name' => "Teacher Judgment {$suffix}", 'slug' => "tj-{$suffix}",
            'subdomain' => "tj-{$suffix}", 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $adminId = $this->user($customerId, "admin-{$suffix}@example.test", 'customer_admin');
        $teacherId = $this->user($customerId, "teacher-{$suffix}@example.test", 'teacher');
        $studentId = $this->user($customerId, "student-{$suffix}@example.test", 'student');
        $templateId = DB::table('core_course_templates')->insertGetId([
            'customer_id' => $customerId, 'title' => "Template {$suffix}",
            'lesson_count' => 0, 'sort_order' => 1, 'working_revision' => 1,
            'status' => 'published', 'created_by' => $adminId,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $versionId = DB::table('core_course_template_versions')->insertGetId([
            'customer_id' => $customerId, 'template_id' => $templateId,
            'version_number' => 1, 'version_code' => "v1-{$suffix}", 'is_current' => true,
            'title_snapshot' => "Template {$suffix}", 'lesson_count_snapshot' => 0,
            'source_working_revision' => 1,
            'status' => 'published', 'published_at' => $now, 'published_by' => $adminId,
            'source_template_updated_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $productId = DB::table('core_course_products')->insertGetId([
            'customer_id' => $customerId, 'product_code' => "P-{$suffix}",
            'product_type' => 'course', 'title' => "Product {$suffix}", 'slug' => "product-{$suffix}",
            'thumbnail_type' => 'none', 'price' => 0, 'currency' => 'VND',
            'enrollment_type' => 'manual', 'enrollment_count' => 0,
            'is_certificate_enabled' => false, 'is_refundable' => false,
            'show_enrollment_count' => false, 'is_featured' => false, 'sort_order' => 0,
            'visibility' => 'private', 'status' => 'active', 'created_by' => $adminId,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $cohortId = DB::table('core_course_cohorts')->insertGetId([
            'customer_id' => $customerId, 'product_id' => $productId, 'version_id' => $versionId,
            'teacher_id' => $teacherId, 'name' => "Cohort {$suffix}", 'code' => "C-{$suffix}",
            'status' => 'active', 'start_date' => '2026-08-01', 'end_date' => '2026-08-31',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $assignmentId = $this->assignment(compact('customerId', 'cohortId'), $teacherId);
        $enrollmentId = DB::table('core_course_enrollments')->insertGetId([
            'customer_id' => $customerId, 'product_id' => $productId, 'version_id' => $versionId,
            'student_id' => $studentId, 'source' => 'admin', 'enrolled_by' => $adminId,
            'enrolled_at' => '2026-08-01 00:00:00', 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $membershipId = DB::table('core_course_cohort_students')->insertGetId([
            'customer_id' => $customerId, 'cohort_id' => $cohortId,
            'enrollment_id' => $enrollmentId, 'product_id' => $productId,
            'student_id' => $studentId, 'assigned_by' => $adminId,
            'joined_at' => '2026-08-01 00:00:00', 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $scale = json_encode(['levels' => [
            ['key' => 'novice', 'threshold' => 0],
            ['key' => 'mastered', 'threshold' => 0.8],
        ]], JSON_THROW_ON_ERROR);
        $frameworkId = DB::table('core_learning_frameworks')->insertGetId([
            'customer_id' => $customerId, 'code' => "framework-{$suffix}",
            'name' => "Framework {$suffix}", 'default_mastery_scale_key' => 'direct',
            'default_mastery_scale_version' => '1', 'default_mastery_scale' => $scale,
            'status' => 'active', 'created_by' => $adminId, 'updated_by' => $adminId,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $basisId = DB::table('core_learning_framework_versions')->insertGetId([
            'customer_id' => $customerId, 'framework_id' => $frameworkId,
            'version_number' => 1, 'version_code' => "v1-{$suffix}",
            'title_snapshot' => "Framework {$suffix}", 'mastery_scale_key' => 'direct',
            'mastery_scale_version' => '1', 'mastery_scale_snapshot' => $scale,
            'status' => 'draft_snapshot', 'published_at' => null, 'published_by' => null,
            'created_by' => $adminId, 'updated_by' => $adminId,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('core_learning_framework_versions')->where('id', $basisId)->update([
            'status' => 'published', 'published_at' => $now, 'published_by' => $adminId,
            'updated_by' => $adminId, 'updated_at' => $now,
        ]);
        $definitionId = DB::table('core_learning_node_definitions')->insertGetId([
            'customer_id' => $customerId, 'framework_id' => $frameworkId,
            'code' => "node-{$suffix}", 'node_type' => 'competency',
            'canonical_name' => "Node {$suffix}", 'status' => 'active',
            'created_by' => $adminId, 'updated_by' => $adminId,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $nodeId = DB::table('core_learning_nodes')->insertGetId([
            'customer_id' => $customerId, 'framework_id' => $frameworkId,
            'framework_version_id' => $basisId, 'node_definition_id' => $definitionId,
            'code_snapshot' => "node-{$suffix}", 'name_snapshot' => "Node {$suffix}",
            'sequence' => 1, 'status' => 'active', 'created_by' => $adminId,
            'updated_by' => $adminId, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return [
            'customer_id' => $customerId, 'admin_id' => $adminId, 'teacher_id' => $teacherId,
            'student_id' => $studentId, 'product_id' => $productId,
            'cohort_id' => $cohortId, 'assignment_id' => $assignmentId,
            'enrollment_id' => $enrollmentId, 'membership_id' => $membershipId,
            'framework_id' => $frameworkId, 'basis_id' => $basisId,
            'definition_id' => $definitionId, 'node_id' => $nodeId, 'scale' => $scale,
        ];
    }

    private function assignment(array $context, int $teacherId): int
    {
        $customerId = $context['customer_id'] ?? $context['customerId'];
        $cohortId = $context['cohort_id'] ?? $context['cohortId'];

        return DB::table('core_course_cohort_teachers')->insertGetId([
            'customer_id' => $customerId, 'cohort_id' => $cohortId,
            'teacher_id' => $teacherId, 'role' => 'teacher',
            'assigned_from' => '2026-08-01', 'assigned_to' => '2026-08-31',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function user(int $customerId, string $email, string $role): int
    {
        return DB::table('users')->insertGetId([
            'customer_id' => $customerId, 'name' => $email, 'email' => $email,
            'password' => bcrypt('password'), 'role' => $role, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function command(array $context, array $overrides = []): array
    {
        return array_merge([
            'submission_uuid' => '11111111-1111-4111-8111-111111111111',
            'cohort_id' => $context['cohort_id'],
            'cohort_teacher_assignment_id' => $context['assignment_id'],
            'cohort_student_membership_id' => $context['membership_id'],
            'enrollment_id' => $context['enrollment_id'],
            'student_id' => $context['student_id'],
            'basis_framework_version_id' => $context['basis_id'],
            'learning_node_id' => $context['node_id'],
            'mastery_level_key' => 'novice', 'mastery_score' => 0.5,
            'reason' => 'Direct observation during the assigned teaching window.',
            'occurred_at' => '2026-08-15 09:00:00.000000',
        ], $overrides);
    }
}
