<?php

namespace Tests\Integration;

use App\Services\LearningFrameworkAuthoringService;
use App\Services\TeacherJudgmentService;
use App\Support\TenantContext;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LearningFrameworkAuthoringMariaDbTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Learning Framework authoring verification requires MariaDB.');
        }

        TenantContext::set(null);
        // The suite pins its own clock. The fixture Cohort runs 2026-08-01 to
        // 2026-08-31, and the Cohort submission boundary compares submitted_at
        // against that end date, so on real wall-clock every happy path in this
        // file would start failing on 2026-09-01 — and the future-occurrence
        // test a day earlier. A suite that goes red for reasons unrelated to any
        // change invites the cheapest possible repair, and the cheapest repair
        // here is loosening either the fixture or the rule that bounds writes
        // into an immutable table. Pinning removes the temptation.
        Carbon::setTestNow('2026-08-20 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        TenantContext::set(null);
        parent::tearDown();
    }

    /**
     * The point of this gate: a Node authored here must be judgeable. Asserting
     * that rows were inserted would prove the service writes, not that it
     * unblocks Teacher Judgment.
     */
    public function test_authored_framework_produces_a_node_a_teacher_can_judge(): void
    {
        $fixture = $this->courseFixture('e2e');
        TenantContext::set((object) ['id' => $fixture['customer_id']]);
        $authoring = app(LearningFrameworkAuthoringService::class);

        $framework = $authoring->createFramework($fixture['admin_id'], $this->frameworkCommand('e2e'));
        $version = $authoring->createDraftVersion($fixture['admin_id'], [
            'framework_id' => $framework->id, 'version_code' => 'v1-e2e', 'title' => 'Framework e2e',
        ]);
        $definition = $authoring->createDefinition($fixture['admin_id'], [
            'framework_id' => $framework->id, 'code' => 'node-e2e',
            'node_type' => 'competency', 'canonical_name' => 'Speaking accuracy',
        ]);
        $node = $authoring->createNode($fixture['admin_id'], [
            'framework_version_id' => $version->id, 'node_definition_id' => $definition->id,
        ]);

        $this->assertSame('draft_snapshot', $version->status);
        $this->assertSame(1, (int) $version->version_number);
        $this->assertSame('node-e2e', $node->code_snapshot);
        $this->assertSame(1, (int) $node->sequence);

        $published = $authoring->publishVersion($fixture['admin_id'], $version->id);
        $this->assertSame('published', $published->status);
        $this->assertNotNull($published->published_at);

        $judgment = app(TeacherJudgmentService::class)->submit($fixture['teacher_id'], [
            'submission_uuid' => '22222222-2222-4222-8222-222222222222',
            'cohort_id' => $fixture['cohort_id'],
            'cohort_teacher_assignment_id' => $fixture['assignment_id'],
            'cohort_student_membership_id' => $fixture['membership_id'],
            'enrollment_id' => $fixture['enrollment_id'],
            'student_id' => $fixture['student_id'],
            'basis_framework_version_id' => $published->id,
            'learning_node_id' => $node->id,
            'mastery_level_key' => 'novice',
            'mastery_score' => 0.5,
            'reason' => 'Observed during the assigned teaching window.',
            'occurred_at' => '2026-08-15T09:00:00.000000+07:00',
        ]);

        $this->assertFalse($judgment->replayed);

        $stored = DB::table('core_liveclass_teacher_judgments')->find($judgment->judgment_id);
        $this->assertSame((int) $node->id, (int) $stored->learning_node_id);
        $this->assertSame((int) $published->id, (int) $stored->basis_framework_version_id);

        // The whole chain must exist against the authored Node, not just the
        // source row: that is what "a teacher can judge it" means.
        $this->assertSame((int) $node->id, (int) DB::table('core_learning_evidence')
            ->find($judgment->evidence_id)->learning_node_id);
        $this->assertSame((int) $definition->id, (int) DB::table('core_learning_mastery_calculations')
            ->find($judgment->calculation_id)->node_definition_id);
        $this->assertSame(1, DB::table('core_learning_calculation_evidence')
            ->where('mastery_calculation_id', $judgment->calculation_id)->count());
        $this->assertSame((int) $judgment->calculation_id, (int) DB::table('core_learning_mastery_profiles')
            ->find($judgment->profile_id)->current_calculation_id);
    }

    /**
     * core_learning_nodes has no before-insert trigger, so the engine accepts a
     * Node added to an already published version. Only the service stops it.
     */
    public function test_node_cannot_be_added_after_the_version_is_published(): void
    {
        $fixture = $this->courseFixture('frozen');
        TenantContext::set((object) ['id' => $fixture['customer_id']]);
        $authoring = app(LearningFrameworkAuthoringService::class);

        $framework = $authoring->createFramework($fixture['admin_id'], $this->frameworkCommand('frozen'));
        $version = $authoring->createDraftVersion($fixture['admin_id'], [
            'framework_id' => $framework->id, 'version_code' => 'v1-frozen', 'title' => 'Framework frozen',
        ]);
        $late = $authoring->createDefinition($fixture['admin_id'], [
            'framework_id' => $framework->id, 'code' => 'node-late',
            'node_type' => 'objective', 'canonical_name' => 'Added too late',
        ]);
        $authoring->publishVersion($fixture['admin_id'], $version->id);

        try {
            $authoring->createNode($fixture['admin_id'], [
                'framework_version_id' => $version->id, 'node_definition_id' => $late->id,
            ]);
            $this->fail('A published version must not accept a new Node.');
        } catch (DomainException $exception) {
            $this->assertSame('LF_FRAMEWORK_AUTHORING_VERSION_NOT_DRAFT', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('core_learning_nodes')
            ->where('customer_id', $fixture['customer_id'])->count());
    }

    public function test_publish_is_one_way_and_rejects_a_non_draft_version(): void
    {
        $fixture = $this->courseFixture('oneway');
        TenantContext::set((object) ['id' => $fixture['customer_id']]);
        $authoring = app(LearningFrameworkAuthoringService::class);

        $framework = $authoring->createFramework($fixture['admin_id'], $this->frameworkCommand('oneway'));
        $version = $authoring->createDraftVersion($fixture['admin_id'], [
            'framework_id' => $framework->id, 'version_code' => 'v1-oneway', 'title' => 'Framework oneway',
        ]);
        $authoring->publishVersion($fixture['admin_id'], $version->id);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('LF_FRAMEWORK_AUTHORING_VERSION_NOT_DRAFT');
        $authoring->publishVersion($fixture['admin_id'], $version->id);
    }

    public function test_invalid_scale_fails_as_a_domain_error_before_reaching_the_trigger(): void
    {
        $fixture = $this->courseFixture('scale');
        TenantContext::set((object) ['id' => $fixture['customer_id']]);
        $authoring = app(LearningFrameworkAuthoringService::class);

        foreach ([
            'single level' => ['levels' => [['key' => 'only', 'threshold' => 0]]],
            'unordered thresholds' => ['levels' => [
                ['key' => 'high', 'threshold' => 0.8], ['key' => 'low', 'threshold' => 0.2],
            ]],
            'duplicate keys' => ['levels' => [
                ['key' => 'same', 'threshold' => 0], ['key' => 'same', 'threshold' => 0.5],
            ]],
        ] as $case => $scale) {
            try {
                $authoring->createFramework($fixture['admin_id'], [
                    'code' => "bad-{$fixture['customer_id']}", 'name' => 'Bad scale',
                    'mastery_scale_key' => 'direct', 'mastery_scale_version' => '1',
                    'mastery_scale' => $scale,
                ]);
                $this->fail("{$case} must be rejected.");
            } catch (DomainException $exception) {
                $this->assertSame('LF_FRAMEWORK_AUTHORING_SCALE_INVALID', $exception->getMessage());
            }
        }

        $this->assertSame(0, DB::table('core_learning_frameworks')
            ->where('customer_id', $fixture['customer_id'])->count());
    }

    public function test_authoring_is_tenant_scoped_and_requires_a_tenant_context(): void
    {
        $owner = $this->courseFixture('owner');
        $other = $this->courseFixture('other');

        TenantContext::set((object) ['id' => $owner['customer_id']]);
        $authoring = app(LearningFrameworkAuthoringService::class);
        $framework = $authoring->createFramework($owner['admin_id'], $this->frameworkCommand('owner'));

        TenantContext::set((object) ['id' => $other['customer_id']]);
        try {
            $authoring->createDraftVersion($other['admin_id'], [
                'framework_id' => $framework->id, 'version_code' => 'v1-steal', 'title' => 'Cross tenant',
            ]);
            $this->fail('A tenant must not author into another tenant Framework.');
        } catch (RecordsNotFoundException) {
            $this->addToAssertionCount(1);
        }

        TenantContext::set((object) ['id' => $other['customer_id']]);
        try {
            $authoring->createFramework($owner['admin_id'], $this->frameworkCommand('actor'));
            $this->fail('An actor outside the tenant must be denied.');
        } catch (DomainException $exception) {
            $this->assertSame('LF_FRAMEWORK_AUTHORING_ACTOR_DENIED', $exception->getMessage());
        }

        TenantContext::set(null);
        $this->expectException(AuthorizationException::class);
        $authoring->createFramework($owner['admin_id'], $this->frameworkCommand('no-tenant'));
    }

    /**
     * @return array<string, mixed>
     */
    private function frameworkCommand(string $suffix): array
    {
        return [
            'code' => "framework-{$suffix}",
            'name' => "Framework {$suffix}",
            'mastery_scale_key' => 'direct',
            'mastery_scale_version' => '1',
            'mastery_scale' => ['levels' => [
                ['key' => 'novice', 'threshold' => 0],
                ['key' => 'mastered', 'threshold' => 0.8],
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function courseFixture(string $suffix): array
    {
        $now = now();
        $customerId = DB::table('saas_customers')->insertGetId([
            'name' => "Authoring {$suffix}", 'slug' => "authoring-{$suffix}",
            'subdomain' => "authoring-{$suffix}", 'status' => 'active',
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
            'source_working_revision' => 1, 'status' => 'published',
            'published_at' => $now, 'published_by' => $adminId,
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
        $assignmentId = DB::table('core_course_cohort_teachers')->insertGetId([
            'customer_id' => $customerId, 'cohort_id' => $cohortId, 'teacher_id' => $teacherId,
            'role' => 'teacher', 'assigned_from' => '2026-08-01', 'assigned_to' => '2026-08-31',
            'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);
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

        return compact('customerId', 'adminId', 'teacherId', 'studentId', 'productId')
            + ['customer_id' => $customerId, 'admin_id' => $adminId, 'teacher_id' => $teacherId,
                'student_id' => $studentId, 'cohort_id' => $cohortId, 'assignment_id' => $assignmentId,
                'enrollment_id' => $enrollmentId, 'membership_id' => $membershipId];
    }

    private function user(int $customerId, string $email, string $role): int
    {
        return DB::table('users')->insertGetId([
            'customer_id' => $customerId, 'name' => $email, 'email' => $email,
            'password' => bcrypt('password'), 'role' => $role, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
