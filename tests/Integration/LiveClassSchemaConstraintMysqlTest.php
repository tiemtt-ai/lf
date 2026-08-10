<?php

namespace Tests\Integration;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Database-level safety net for the Cohort/LiveClass module.
 *
 * The CHECK constraints released with the Session, Schedule Slot and Schedule
 * Origin migrations are created only when the driver is `mysql`. The default
 * suite runs on SQLite in memory, so without this class those constraints are
 * never exercised anywhere: an application-validation regression would keep the
 * suite green and only surface as an unhandled QueryException in production.
 *
 * Every test here asserts the constraint itself, not the application path that
 * normally protects it — the point is to prove the second line of defence is
 * actually present in the schema.
 */
class LiveClassSchemaConstraintMysqlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('LiveClass database constraint verification requires the mysql driver.');
        }
    }

    public function test_session_type_binding_check_rejects_curriculum_without_complete_binding(): void
    {
        $context = $this->context();

        foreach ([
            'thiếu cả Lesson và Activity' => ['version_lesson_id' => null, 'version_activity_id' => null],
            'thiếu Activity' => ['version_lesson_id' => $context['lesson_id'], 'version_activity_id' => null],
            'thiếu Lesson' => ['version_lesson_id' => null, 'version_activity_id' => $context['activity_id']],
        ] as $case => $binding) {
            $this->assertConstraintRejects(
                fn () => $this->insertSession($context, array_merge($binding, [
                    'session_type' => 'curriculum',
                ])),
                "chk_lcs_type_binding phải từ chối curriculum Session $case"
            );
        }
    }

    public function test_session_type_binding_check_rejects_operational_with_curriculum_binding(): void
    {
        $context = $this->context();

        foreach ([
            'còn Lesson' => ['version_lesson_id' => $context['lesson_id'], 'version_activity_id' => null],
            'còn Activity' => ['version_lesson_id' => null, 'version_activity_id' => $context['activity_id']],
            'còn cả hai' => [
                'version_lesson_id' => $context['lesson_id'],
                'version_activity_id' => $context['activity_id'],
            ],
        ] as $case => $binding) {
            $this->assertConstraintRejects(
                fn () => $this->insertSession($context, array_merge($binding, [
                    'session_type' => 'operational',
                ])),
                "chk_lcs_type_binding phải từ chối operational Session $case"
            );
        }
    }

    public function test_session_type_binding_check_accepts_both_canonical_shapes(): void
    {
        $context = $this->context();

        $curriculumId = $this->insertSession($context, [
            'session_type' => 'curriculum',
            'version_lesson_id' => $context['lesson_id'],
            'version_activity_id' => $context['activity_id'],
        ]);
        $operationalId = $this->insertSession($context, [
            'session_type' => 'operational',
            'version_lesson_id' => null,
            'version_activity_id' => null,
            'session_no' => 2,
        ]);

        $this->assertDatabaseHas('core_liveclass_sessions', [
            'id' => $curriculumId, 'session_type' => 'curriculum',
        ]);
        $this->assertDatabaseHas('core_liveclass_sessions', [
            'id' => $operationalId, 'session_type' => 'operational',
            'version_lesson_id' => null, 'version_activity_id' => null,
        ]);
    }

    public function test_schedule_slot_checks_reject_invalid_weekday_and_time_order(): void
    {
        $context = $this->context();

        foreach ([0, 8, 255] as $weekday) {
            $this->assertConstraintRejects(
                fn () => $this->insertSlot($context, ['weekday' => $weekday]),
                "chk_lcsslot_weekday phải từ chối weekday $weekday"
            );
        }

        foreach ([
            'end bằng start' => ['start_time' => '09:00:00', 'end_time' => '09:00:00'],
            'end trước start' => ['start_time' => '09:00:00', 'end_time' => '08:00:00'],
        ] as $case => $times) {
            $this->assertConstraintRejects(
                fn () => $this->insertSlot($context, $times),
                "chk_lcsslot_time phải từ chối Slot có $case"
            );
        }
    }

    public function test_schedule_slot_exact_duplicate_is_rejected_by_unique_key(): void
    {
        $context = $this->context();
        $this->insertSlot($context);

        $this->assertConstraintRejects(
            fn () => $this->insertSlot($context),
            'uk_lcsslot_exact phải từ chối Slot trùng (weekday, start_time, end_time)'
        );
    }

    public function test_schedule_exclusion_unique_key_rejects_duplicate_date(): void
    {
        $context = $this->context();
        $this->insertExclusion($context);

        $this->assertConstraintRejects(
            fn () => $this->insertExclusion($context),
            'uk_lcsexclusion_date phải từ chối hai exclusion cùng ngày trong một Schedule'
        );
    }

    public function test_origin_time_checks_reject_non_positive_intervals(): void
    {
        $context = $this->context();
        $slotId = $this->insertSlot($context);
        $sessionId = $this->insertCurriculumSession($context);

        $this->assertConstraintRejects(
            fn () => $this->insertOrigin($context, $sessionId, $slotId, [
                'source_local_start_time' => '19:00:00',
                'source_local_end_time' => '19:00:00',
            ]),
            'chk_lcsso_local_time phải từ chối local end không lớn hơn local start'
        );

        $this->assertConstraintRejects(
            fn () => $this->insertOrigin($context, $sessionId, $slotId, [
                'source_start_at' => '2026-08-03 12:00:00',
                'source_end_at' => '2026-08-03 11:00:00',
            ]),
            'chk_lcsso_absolute_time phải từ chối absolute end trước absolute start'
        );
    }

    public function test_origin_unique_keys_enforce_one_origin_per_session_and_occurrence(): void
    {
        $context = $this->context();
        $slotId = $this->insertSlot($context);
        $firstSessionId = $this->insertCurriculumSession($context);
        $secondSessionId = $this->insertCurriculumSession($context, ['session_no' => 2]);

        $this->insertOrigin($context, $firstSessionId, $slotId);

        // uk_lcsso_occurrence: một occurrence identity chỉ tạo được một Session
        // cho toàn bộ lịch sử, kể cả khi Session đích là một row khác.
        $this->assertConstraintRejects(
            fn () => $this->insertOrigin($context, $secondSessionId, $slotId),
            'uk_lcsso_occurrence phải từ chối occurrence identity đã được tiêu thụ'
        );

        // uk_lcsso_session: một Session chỉ có tối đa một Origin.
        $this->assertConstraintRejects(
            fn () => $this->insertOrigin($context, $firstSessionId, $slotId, [
                'source_local_date' => '2026-08-10',
                'source_start_at' => '2026-08-10 12:00:00',
                'source_end_at' => '2026-08-10 14:00:00',
            ]),
            'uk_lcsso_session phải từ chối Origin thứ hai cho cùng một Session'
        );
    }

    public function test_referenced_schedule_slot_cannot_be_hard_deleted(): void
    {
        $context = $this->context();
        $slotId = $this->insertSlot($context);
        $sessionId = $this->insertCurriculumSession($context);
        $this->insertOrigin($context, $sessionId, $slotId);

        $this->assertConstraintRejects(
            fn () => DB::table('core_liveclass_schedule_slots')->where('id', $slotId)->delete(),
            'fk_lcsso_slot RESTRICT phải chặn hard-delete Slot đang được Origin tham chiếu'
        );

        $this->assertDatabaseHas('core_liveclass_schedule_slots', ['id' => $slotId]);
    }

    public function test_referenced_session_and_schedule_cannot_be_hard_deleted(): void
    {
        $context = $this->context();
        $slotId = $this->insertSlot($context);
        $sessionId = $this->insertCurriculumSession($context);
        $this->insertOrigin($context, $sessionId, $slotId);

        $this->assertConstraintRejects(
            fn () => DB::table('core_liveclass_sessions')->where('id', $sessionId)->delete(),
            'fk_lcsso_session RESTRICT phải chặn hard-delete Session có Origin'
        );

        $this->assertConstraintRejects(
            fn () => DB::table('core_liveclass_schedules')->where('id', $context['schedule_id'])->delete(),
            'fk_lcsso_schedule RESTRICT phải chặn hard-delete Schedule có Origin'
        );
    }

    public function test_cohort_scoped_session_number_is_unique(): void
    {
        $context = $this->context();
        $this->insertCurriculumSession($context);

        $this->assertConstraintRejects(
            fn () => $this->insertCurriculumSession($context),
            'uk_lcs_number phải từ chối session_no trùng trong cùng Cohort'
        );
    }

    private function assertConstraintRejects(callable $write, string $message): void
    {
        try {
            $write();
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail($message);
    }

    /**
     * @return array<string, int|string>
     */
    private function context(): array
    {
        $now = now();
        $customerId = DB::table('saas_customers')->insertGetId([
            'name' => 'Constraint Tenant', 'slug' => 'constraint-tenant',
            'subdomain' => 'constraint-tenant', 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $adminId = DB::table('users')->insertGetId([
            'customer_id' => $customerId, 'name' => 'Constraint Admin',
            'email' => 'constraint-admin@example.test', 'password' => bcrypt('password'),
            'role' => 'customer_admin', 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $productId = DB::table('core_course_products')->insertGetId([
            'customer_id' => $customerId, 'title' => 'Constraint Product',
            'slug' => 'constraint-product', 'product_code' => 'CONSTRAINT-P1',
            'product_type' => 'single_course', 'offering_type' => 'live_class',
            'thumbnail_type' => 'image', 'price' => 0, 'currency' => 'VND',
            'enrollment_type' => 'paid', 'access_duration_days' => 30,
            'status' => 'active', 'visibility' => 'public',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $templateId = DB::table('core_course_templates')->insertGetId([
            'customer_id' => $customerId, 'title' => 'Constraint Template',
            'working_revision' => 1, 'status' => 'active', 'created_by' => $adminId,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $versionId = DB::table('core_course_template_versions')->insertGetId([
            'customer_id' => $customerId, 'template_id' => $templateId,
            'version_number' => 1, 'version_code' => 'CONSTRAINT-V1',
            'title_snapshot' => 'Constraint Version', 'source_working_revision' => 1,
            'status' => 'published', 'published_at' => $now, 'published_by' => $adminId,
            'source_template_updated_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $lessonId = DB::table('core_course_template_version_lessons')->insertGetId([
            'customer_id' => $customerId, 'template_version_id' => $versionId,
            'source_template_lesson_id' => 9001, 'title_snapshot' => 'Constraint Lesson',
            'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $activityId = DB::table('core_course_template_version_activities')->insertGetId([
            'customer_id' => $customerId, 'template_version_id' => $versionId,
            'version_lesson_id' => $lessonId, 'source_template_activity_id' => 9101,
            'title_snapshot' => 'Constraint Live Activity', 'activity_type' => 'live_class',
            'completion_rule' => 'manual', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $cohortId = DB::table('core_course_cohorts')->insertGetId([
            'customer_id' => $customerId, 'product_id' => $productId, 'version_id' => $versionId,
            'name' => 'Constraint Cohort', 'code' => 'CONSTRAINT-COH', 'status' => 'active',
            'start_date' => '2026-08-01', 'end_date' => '2026-08-31',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $scheduleId = DB::table('core_liveclass_schedules')->insertGetId([
            'customer_id' => $customerId, 'cohort_id' => $cohortId,
            'name' => 'Constraint Schedule', 'starts_on' => '2026-08-01',
            'ends_on' => '2026-08-31', 'timezone' => 'Asia/Ho_Chi_Minh',
            'created_by' => $adminId, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return [
            'customer_id' => $customerId, 'admin_id' => $adminId, 'version_id' => $versionId,
            'lesson_id' => $lessonId, 'activity_id' => $activityId,
            'cohort_id' => $cohortId, 'schedule_id' => $scheduleId,
        ];
    }

    private function insertSession(array $context, array $overrides = []): int
    {
        $now = now();

        return DB::table('core_liveclass_sessions')->insertGetId(array_merge([
            'customer_id' => $context['customer_id'], 'cohort_id' => $context['cohort_id'],
            'template_version_id' => $context['version_id'], 'title' => 'Constraint Session',
            'session_no' => 1, 'delivery_mode' => 'online',
            'scheduled_start_at' => '2026-08-03 19:00:00',
            'scheduled_end_at' => '2026-08-03 21:00:00',
            'timezone' => 'Asia/Ho_Chi_Minh', 'status' => 'scheduled',
            'created_by' => $context['admin_id'], 'created_at' => $now, 'updated_at' => $now,
        ], $overrides));
    }

    private function insertCurriculumSession(array $context, array $overrides = []): int
    {
        return $this->insertSession($context, array_merge([
            'session_type' => 'curriculum',
            'version_lesson_id' => $context['lesson_id'],
            'version_activity_id' => $context['activity_id'],
        ], $overrides));
    }

    private function insertSlot(array $context, array $overrides = []): int
    {
        $now = now();

        return DB::table('core_liveclass_schedule_slots')->insertGetId(array_merge([
            'customer_id' => $context['customer_id'], 'schedule_id' => $context['schedule_id'],
            'weekday' => 1, 'start_time' => '19:00:00', 'end_time' => '21:00:00',
            'sort_order' => 1, 'created_by' => $context['admin_id'],
            'created_at' => $now, 'updated_at' => $now,
        ], $overrides));
    }

    private function insertExclusion(array $context, array $overrides = []): int
    {
        $now = now();

        return DB::table('core_liveclass_schedule_exclusions')->insertGetId(array_merge([
            'customer_id' => $context['customer_id'], 'schedule_id' => $context['schedule_id'],
            'excluded_on' => '2026-08-10', 'reason' => null,
            'created_by' => $context['admin_id'], 'created_at' => $now, 'updated_at' => $now,
        ], $overrides));
    }

    private function insertOrigin(array $context, int $sessionId, int $slotId, array $overrides = []): int
    {
        return DB::table('core_liveclass_session_schedule_origins')->insertGetId(array_merge([
            'customer_id' => $context['customer_id'], 'session_id' => $sessionId,
            'schedule_id' => $context['schedule_id'], 'schedule_slot_id' => $slotId,
            'source_local_date' => '2026-08-03',
            'source_local_start_time' => '19:00:00', 'source_local_end_time' => '21:00:00',
            'source_timezone' => 'Asia/Ho_Chi_Minh',
            'source_start_at' => '2026-08-03 12:00:00',
            'source_end_at' => '2026-08-03 14:00:00',
            'created_by' => $context['admin_id'], 'created_at' => now(),
        ], $overrides));
    }
}
