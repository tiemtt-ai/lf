<?php

namespace Tests\Integration;

use App\Services\TeacherJudgmentService;
use App\Support\TenantContext;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
        $correctionCommand = $this->command($context, [
            'submission_uuid' => '22222222-2222-4222-8222-222222222222',
            'cohort_teacher_assignment_id' => $secondAssignment,
            'mastery_level_key' => 'mastered',
            'mastery_score' => 0.9,
            'reason' => 'Correction by another eligible teacher.',
            'supersedes_judgment_id' => $first->judgment_id,
        ]);
        $correction = $service->submit($secondTeacher, $correctionCommand);

        $this->assertFalse($correction->replayed);

        // The symptom B3 actually produced: replaying a *correction*, not the
        // first submission. While occurred_at was rewritten from the
        // predecessor, this retry compared the caller's own value against the
        // stored one and failed as an idempotency conflict. Replaying the first
        // record never exercised that path, so any reintroduced normalization
        // on the correction branch would pass unnoticed without this.
        $correctionReplay = $service->submit($secondTeacher, $correctionCommand);
        $this->assertTrue($correctionReplay->replayed);
        $this->assertSame($correction->judgment_id, $correctionReplay->judgment_id);
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

    /**
     * Each case asserts its exact code. A prefix match cannot tell which rule
     * rejected the submission, so a guard could silently move or disappear
     * behind an earlier one and the matrix would stay green.
     *
     * Cohort and assignment eligibility now raise distinct codes. Sharing one
     * defeated the purpose: two rules behind a single code prove neither, since
     * either could vanish behind the other and this matrix would stay green.
     */
    public function test_application_owned_authorization_rules_fail_closed(): void
    {
        $expected = [
            'cohort' => 'LF_TEACHER_JUDGMENT_COHORT_DENIED',
            'enrollment' => 'LF_TEACHER_JUDGMENT_ENROLLMENT_DENIED',
            'membership' => 'LF_TEACHER_JUDGMENT_MEMBERSHIP_DENIED',
            'assignment' => 'LF_TEACHER_JUDGMENT_ASSIGNMENT_DENIED',
            'basis' => 'LF_TEACHER_JUDGMENT_BASIS_INVALID',
        ];

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
                $this->assertSame($expected[$case], $exception->getMessage(), "case {$case}");
            }

            $this->assertSame(0, DB::table('core_liveclass_teacher_judgments')
                ->where('customer_id', $context['customer_id'])->count());
        }
    }

    /**
     * Owner decision 4: customer_admin, student, AI, Track and unassigned
     * teachers cannot submit. The role guard is the only thing enforcing it and
     * had no test at all — an admin submitting judgments would have gone
     * unnoticed by the whole suite.
     */
    public function test_only_an_active_teacher_role_may_submit(): void
    {
        $context = $this->context('role');
        TenantContext::set((object) ['id' => $context['customer_id']]);
        $service = app(TeacherJudgmentService::class);

        foreach (['customer_admin', 'student'] as $role) {
            $actor = $this->user($context['customer_id'], "deny-actor-{$role}@example.test", $role);
            try {
                $service->submit($actor, $this->command($context));
                $this->fail("{$role} must not submit a Teacher Judgment.");
            } catch (AuthorizationException $exception) {
                $this->assertSame('LF_TEACHER_JUDGMENT_TEACHER_DENIED', $exception->getMessage());
            }
        }

        $suspended = $this->user($context['customer_id'], 'deny-actor-suspended@example.test', 'teacher');
        DB::table('users')->where('id', $suspended)->update(['status' => 'inactive']);
        try {
            $service->submit($suspended, $this->command($context));
            $this->fail('An inactive teacher must not submit a Teacher Judgment.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('LF_TEACHER_JUDGMENT_TEACHER_DENIED', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('core_liveclass_teacher_judgments')
            ->where('customer_id', $context['customer_id'])->count());
    }

    /**
     * R2. The Cohort submission window was added as an authorization rule in
     * the same change that closed a missing-test finding, and arrived without a
     * test of its own: the code appeared exactly once in the repository, where
     * it is thrown.
     *
     * The occurrence stays inside the Cohort period so the Cohort and assignment
     * guards both pass; only the act of submitting falls outside it.
     */
    public function test_submission_after_the_cohort_end_boundary_fails_closed(): void
    {
        $context = $this->context('window');
        TenantContext::set((object) ['id' => $context['customer_id']]);
        DB::table('core_course_cohorts')->where('id', $context['cohort_id'])
            ->update(['start_date' => '2026-08-01', 'end_date' => '2026-08-10']);

        try {
            app(TeacherJudgmentService::class)->submit($context['teacher_id'], $this->command($context, [
                'occurred_at' => '2026-08-05T09:00:00.000000+07:00',
            ]));
            $this->fail('Submitting after the Cohort end boundary must fail closed.');
        } catch (DomainException $exception) {
            $this->assertSame('LF_TEACHER_JUDGMENT_COHORT_WINDOW_CLOSED', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('core_liveclass_teacher_judgments')
            ->where('customer_id', $context['customer_id'])->count());
    }

    /**
     * Owner decision 4 names unassigned teachers directly, and the assignment
     * date range is part of decision 3. Neither branch had a test: the role test
     * covers who the actor is, not whether they teach this Cohort at that time.
     */
    public function test_assignment_scope_and_range_fail_closed(): void
    {
        $context = $this->context('scope');
        TenantContext::set((object) ['id' => $context['customer_id']]);
        $service = app(TeacherJudgmentService::class);

        // Active teacher, valid role, but the assignment belongs to someone else.
        $outsider = $this->user($context['customer_id'], 'outsider-scope@example.test', 'teacher');
        try {
            $service->submit($outsider, $this->command($context));
            $this->fail('A teacher without an assignment on this Cohort must be denied.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('LF_TEACHER_JUDGMENT_ASSIGNMENT_DENIED', $exception->getMessage());
        }

        // Assigned teacher, occurrence outside the assignment range.
        DB::table('core_course_cohort_teachers')->where('id', $context['assignment_id'])
            ->update(['assigned_to' => '2026-08-10']);
        try {
            $service->submit($context['teacher_id'], $this->command($context));
            $this->fail('An occurrence outside the assignment range must be denied.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('LF_TEACHER_JUDGMENT_ASSIGNMENT_DENIED', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('core_liveclass_teacher_judgments')
            ->where('customer_id', $context['customer_id'])->count());
    }

    /**
     * R4. A two-digit offset is valid ISO-8601 and must be accepted; a string
     * that merely ends in something offset-shaped must fail as a domain error
     * rather than escaping as an InvalidFormatException. The pattern anchors on
     * a time before the offset, which is also what keeps a bare `YYYY-MM-DD`
     * from reading its own `-15` as an offset.
     */
    public function test_offset_contract_accepts_short_offsets_and_rejects_malformed_input(): void
    {
        $context = $this->context('iso');
        TenantContext::set((object) ['id' => $context['customer_id']]);
        $service = app(TeacherJudgmentService::class);

        $accepted = $service->submit($context['teacher_id'], $this->command($context, [
            'occurred_at' => '2026-08-15T09:00:00+07',
        ]));
        $this->assertFalse($accepted->replayed);
        $this->assertSame('2026-08-15 09:00:00.000000', DB::table('core_liveclass_teacher_judgments')
            ->where('id', $accepted->judgment_id)->value('occurred_at'));

        foreach ([
            'not-a-date+07:00' => 'LF_TEACHER_JUDGMENT_OCCURRED_AT_OFFSET_REQUIRED',
            '2026-08-15' => 'LF_TEACHER_JUDGMENT_OCCURRED_AT_OFFSET_REQUIRED',
            '2026-13-45T09:00:00+07:00' => 'LF_TEACHER_JUDGMENT_OCCURRED_AT_INVALID',
        ] as $input => $code) {
            try {
                $service->submit($context['teacher_id'], $this->command($context, [
                    'submission_uuid' => '44444444-4444-4444-8444-144444444444',
                    'occurred_at' => $input,
                ]));
                $this->fail("{$input} must be rejected.");
            } catch (DomainException $exception) {
                $this->assertSame($code, $exception->getMessage(), $input);
            }
        }
    }

    /**
     * Command validation. None of these codes was reachable from any test, so
     * the Required Negative Matrix rows for invalid level/score and a malformed
     * producer UUID were open regardless of what the service does.
     */
    public function test_command_validation_rejects_malformed_input(): void
    {
        $context = $this->context('input');
        TenantContext::set((object) ['id' => $context['customer_id']]);
        $service = app(TeacherJudgmentService::class);

        $cases = [
            'LF_TEACHER_JUDGMENT_REQUIRED:cohort_id' => ['cohort_id' => null],
            'LF_TEACHER_JUDGMENT_UUID_INVALID' => ['submission_uuid' => 'not-a-uuid'],
            'LF_TEACHER_JUDGMENT_SCORE_INVALID' => ['mastery_score' => 1.5],
        ];

        foreach ($cases as $code => $override) {
            $command = $this->command($context, $override);
            if ($override === ['cohort_id' => null]) {
                unset($command['cohort_id']);
            }

            try {
                $service->submit($context['teacher_id'], $command);
                $this->fail("{$code} must be raised.");
            } catch (DomainException $exception) {
                $this->assertSame($code, $exception->getMessage());
            }
        }

        // Empty result fields are rejected before any lookup; a level outside
        // the frozen basis scale is rejected after it, and both use the same
        // code because both mean the stated result cannot be honoured.
        foreach ([['reason' => '   '], ['mastery_level_key' => 'not-in-scale']] as $override) {
            try {
                $service->submit($context['teacher_id'], $this->command($context, $override));
                $this->fail('An unusable result must be rejected.');
            } catch (DomainException $exception) {
                $this->assertSame('LF_TEACHER_JUDGMENT_RESULT_INVALID', $exception->getMessage());
            }
        }

        $this->assertSame(0, DB::table('core_liveclass_teacher_judgments')
            ->where('customer_id', $context['customer_id'])->count());
    }

    public function test_future_occurrence_is_rejected(): void
    {
        $context = $this->context('future');
        TenantContext::set((object) ['id' => $context['customer_id']]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('LF_TEACHER_JUDGMENT_FUTURE_OCCURRENCE');
        app(TeacherJudgmentService::class)->submit($context['teacher_id'], $this->command($context, [
            // Inside the Cohort period and the assignment range, so only the
            // future check can reject it.
            'occurred_at' => '2026-08-30T09:00:00.000000+07:00',
        ]));
    }

    /**
     * Defence in depth. trg_lrn_fw_versions_bi_validate already refuses to store
     * a malformed scale, so this guard cannot be reached through the database
     * and is exercised directly. A test that could not fail would be worse than
     * none; this one fails if the guard is removed.
     */
    public function test_malformed_scale_snapshot_is_rejected_by_the_service_guard(): void
    {
        $service = app(TeacherJudgmentService::class);
        $method = new \ReflectionMethod($service, 'assertScaleResult');

        foreach ([
            '{"levels":[{"key":"only","threshold":0}]}',
            '{"levels":[]}',
            '{}',
        ] as $snapshot) {
            try {
                $method->invoke($service, $snapshot, 'only', null);
                $this->fail('A scale with fewer than two levels must be rejected.');
            } catch (DomainException $exception) {
                $this->assertSame('LF_TEACHER_JUDGMENT_SCALE_INVALID', $exception->getMessage());
            }
        }
    }

    /**
     * A correction locks the predecessor's Evidence and Calculation. A source
     * row without them means the lineage this rule depends on is not there, and
     * the correction must stop rather than build a second chain beside it.
     */
    public function test_correction_without_prior_lineage_is_rejected(): void
    {
        $context = $this->context('lineage');
        TenantContext::set((object) ['id' => $context['customer_id']]);
        $now = now();

        $orphanId = DB::table('core_liveclass_teacher_judgments')->insertGetId([
            'customer_id' => $context['customer_id'],
            'submission_uuid' => '55555555-5555-4555-8555-155555555555',
            'cohort_id' => $context['cohort_id'],
            'cohort_teacher_assignment_id' => $context['assignment_id'],
            'cohort_student_membership_id' => $context['membership_id'],
            'enrollment_id' => $context['enrollment_id'],
            'teacher_id' => $context['teacher_id'],
            'student_id' => $context['student_id'],
            'framework_id' => $context['framework_id'],
            'basis_framework_version_id' => $context['basis_id'],
            'learning_node_id' => $context['node_id'],
            'mastery_level_key' => 'novice',
            'mastery_score' => '0.500000',
            'reason' => 'Source row written without its Learning lineage.',
            'context_snapshot' => '{}',
            'occurred_at' => '2026-08-15 09:00:00.000000',
            'submitted_at' => '2026-08-15 10:00:00.000000',
            'created_at' => $now,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('LF_TEACHER_JUDGMENT_LINEAGE_INCOMPLETE');
        app(TeacherJudgmentService::class)->submit($context['teacher_id'], $this->command($context, [
            'submission_uuid' => '66666666-6666-4666-8666-166666666666',
            'supersedes_judgment_id' => $orphanId,
        ]));
    }

    /**
     * Two separate rules. Cross-tenant rows are unreachable because every lookup
     * is tenant-scoped; rows that are all inside one tenant but do not refer to
     * each other are caught by the explicit cross-row equalities, which no
     * foreign key expresses.
     */
    public function test_cross_tenant_and_cross_inconsistent_rows_fail_closed(): void
    {
        $owner = $this->context('owner');
        $other = $this->context('other');
        $service = app(TeacherJudgmentService::class);

        TenantContext::set((object) ['id' => $other['customer_id']]);
        try {
            $service->submit($other['teacher_id'], $this->command($owner));
            $this->fail('A tenant must not judge against another tenant context.');
        } catch (RecordsNotFoundException) {
            $this->addToAssertionCount(1);
        }

        // Every identifier below belongs to the owner tenant, so tenant scoping
        // passes; the membership simply belongs to a different Cohort.
        TenantContext::set((object) ['id' => $owner['customer_id']]);
        $foreignCohort = $this->context('foreign');
        // core_course_cohort_students is unique on (customer_id, enrollment_id),
        // so the stray membership needs an enrollment of its own.
        $strayEnrollment = DB::table('core_course_enrollments')->insertGetId([
            'customer_id' => $owner['customer_id'], 'product_id' => $owner['product_id'],
            'version_id' => DB::table('core_course_cohorts')->where('id', $owner['cohort_id'])
                ->value('version_id'),
            'student_id' => $owner['student_id'], 'source' => 'admin',
            'enrolled_by' => $owner['admin_id'], 'enrolled_at' => '2026-08-01 00:00:00',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $strayMembership = DB::table('core_course_cohort_students')->insertGetId([
            'customer_id' => $owner['customer_id'], 'cohort_id' => $foreignCohort['cohort_id'],
            'enrollment_id' => $strayEnrollment, 'product_id' => $owner['product_id'],
            'student_id' => $owner['student_id'], 'assigned_by' => $owner['admin_id'],
            'joined_at' => '2026-08-01 00:00:00', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            $service->submit($owner['teacher_id'], $this->command($owner, [
                'cohort_student_membership_id' => $strayMembership,
            ]));
            $this->fail('A membership on another Cohort must be denied.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('LF_TEACHER_JUDGMENT_MEMBERSHIP_DENIED', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('core_liveclass_teacher_judgments')->count());
    }

    /**
     * The service rechecks the producer UUID after taking every lock, so a
     * commit that lands between the first check and the locks becomes a replay.
     * What makes that recheck safe is the physical key underneath it, asserted
     * here directly. In-process parallelism is not exercised: the suite runs a
     * single connection inside one transaction, so a second connection would not
     * observe uncommitted rows and the test would prove nothing.
     */
    public function test_duplicate_producer_uuid_is_rejected_by_the_physical_key(): void
    {
        $context = $this->context('concurrent');
        TenantContext::set((object) ['id' => $context['customer_id']]);
        $first = app(TeacherJudgmentService::class)->submit(
            $context['teacher_id'], $this->command($context)
        );

        $row = (array) DB::table('core_liveclass_teacher_judgments')->find($first->judgment_id);
        unset($row['id']);

        $this->expectException(QueryException::class);
        DB::table('core_liveclass_teacher_judgments')->insert($row);
    }

    /**
     * B1 remediation. Storage stays naive wall-clock like the rest of the
     * database, but an inbound timestamp must state its offset: a naive string
     * is exactly what let a caller's local "now" be read as a moment seven hours
     * away, into a row no UPDATE can ever repair.
     */
    public function test_occurred_at_requires_an_explicit_offset(): void
    {
        $context = $this->context('offset');
        TenantContext::set((object) ['id' => $context['customer_id']]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('LF_TEACHER_JUDGMENT_OCCURRED_AT_OFFSET_REQUIRED');
        app(TeacherJudgmentService::class)->submit($context['teacher_id'], $this->command($context, [
            'occurred_at' => '2026-08-15 09:00:00.000000',
        ]));
    }

    /**
     * B3 remediation. The service used to overwrite the caller's occurred_at
     * with the predecessor's, which satisfied both priorIdentityMatches() and
     * trg_ltj_bi_correction before either could object, and made a valid retry
     * of the correction fail as an idempotency conflict.
     */
    public function test_correction_may_not_move_the_judged_moment(): void
    {
        $context = $this->context('moment');
        TenantContext::set((object) ['id' => $context['customer_id']]);
        $service = app(TeacherJudgmentService::class);
        $first = $service->submit($context['teacher_id'], $this->command($context));

        try {
            $service->submit($context['teacher_id'], $this->command($context, [
                'submission_uuid' => '33333333-3333-4333-8333-133333333333',
                'occurred_at' => '2026-08-16T09:00:00.000000+07:00',
                'mastery_level_key' => 'mastered',
                'mastery_score' => 0.9,
                'supersedes_judgment_id' => $first->judgment_id,
            ]));
            $this->fail('A correction must not re-date the judgment it supersedes.');
        } catch (DomainException $exception) {
            $this->assertSame('LF_TEACHER_JUDGMENT_CORRECTION_INVALID', $exception->getMessage());
        }

        $this->assertSame(1, DB::table('core_liveclass_teacher_judgments')
            ->where('customer_id', $context['customer_id'])->count());
    }

    /**
     * The published-basis rule is the only application-owned rule with no
     * database backstop: trg_lrn_calcs_bi_validate accepts any basis whose
     * scale snapshot matches, whatever its lifecycle. The matrix above only
     * exercises a deprecated basis, so the two remaining denied states are
     * proven here. Version lifecycle is one-way, so each state needs its own
     * context: archived is reached through deprecated, and draft_snapshot can
     * only come from a version that was never published.
     */
    public function test_non_published_basis_lifecycle_fails_closed(): void
    {
        $now = now();

        $archived = $this->context('deny-archived');
        TenantContext::set((object) ['id' => $archived['customer_id']]);
        DB::table('core_learning_framework_versions')->where('id', $archived['basis_id'])->update([
            'status' => 'deprecated', 'deprecated_at' => $now,
            'deprecated_by' => $archived['admin_id'],
        ]);
        DB::table('core_learning_framework_versions')->where('id', $archived['basis_id'])->update([
            'status' => 'archived', 'archived_at' => $now,
            'archived_by' => $archived['admin_id'],
        ]);
        $this->assertBasisRejected($archived, $this->command($archived));

        $draft = $this->context('deny-draft');
        TenantContext::set((object) ['id' => $draft['customer_id']]);
        $draftBasisId = DB::table('core_learning_framework_versions')->insertGetId([
            'customer_id' => $draft['customer_id'], 'framework_id' => $draft['framework_id'],
            'version_number' => 2, 'version_code' => 'v2-deny-draft',
            'title_snapshot' => 'Framework deny-draft', 'mastery_scale_key' => 'direct',
            'mastery_scale_version' => '1', 'mastery_scale_snapshot' => $draft['scale'],
            'status' => 'draft_snapshot', 'published_at' => null, 'published_by' => null,
            'created_by' => $draft['admin_id'], 'updated_by' => $draft['admin_id'],
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $draftNodeId = DB::table('core_learning_nodes')->insertGetId([
            'customer_id' => $draft['customer_id'], 'framework_id' => $draft['framework_id'],
            'framework_version_id' => $draftBasisId, 'node_definition_id' => $draft['definition_id'],
            'code_snapshot' => 'node-deny-draft', 'name_snapshot' => 'Node deny-draft',
            'sequence' => 1, 'status' => 'active',
            'created_by' => $draft['admin_id'], 'updated_by' => $draft['admin_id'],
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->assertBasisRejected($draft, $this->command($draft, [
            'basis_framework_version_id' => $draftBasisId,
            'learning_node_id' => $draftNodeId,
        ]));
    }

    private function assertBasisRejected(array $context, array $command): void
    {
        try {
            app(TeacherJudgmentService::class)->submit($context['teacher_id'], $command);
            $this->fail('A basis outside the published lifecycle must fail closed.');
        } catch (AuthorizationException|DomainException $exception) {
            // Asserting the exact code, not the prefix: a looser assertion would
            // also pass when the submission was rejected by an earlier rule and
            // would prove nothing about the basis lifecycle check.
            $this->assertSame('LF_TEACHER_JUDGMENT_BASIS_INVALID', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('core_liveclass_teacher_judgments')
            ->where('customer_id', $context['customer_id'])->count());
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
            'occurred_at' => '2026-08-15T09:00:00.000000+07:00',
        ], $overrides);
    }
}
