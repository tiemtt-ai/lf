<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Support\Facades\DB;

final class TeacherJudgmentService
{
    private const RULE_KEY = 'teacher_judgment_direct';

    private const RULE_VERSION = '1';

    public function __construct(
        private readonly LearningRuntimeAccess $access,
        private readonly LearningEvidenceSourceGate $sourceGate,
        private readonly LearningMasteryProfileProjector $projector,
    ) {}

    /**
     * @param  array<string, mixed>  $command
     */
    public function submit(int $teacherId, array $command): object
    {
        $customerId = $this->access->tenantId();
        $this->sourceGate->assertOpen(LearningEvidenceSourceGate::INITIAL_SOURCE);
        $payload = $this->normalizeCommand($command);

        return DB::transaction(function () use ($customerId, $teacherId, $payload): object {
            $existing = $this->findExisting($customerId, $payload['submission_uuid']);
            if ($existing !== null) {
                return $this->replay($existing, $teacherId, $payload);
            }

            $prior = $this->lockPrior($customerId, $payload['supersedes_judgment_id']);
            if ($prior !== null) {
                $payload['occurred_at'] = $this->timestamp($prior->occurred_at);
            }

            $context = $this->lockAndAuthorize($customerId, $teacherId, $payload, $prior);

            // The assignment lock serializes same-context submissions. Recheck
            // after all locks so a concurrent committed UUID becomes a replay.
            $existing = $this->findExisting($customerId, $payload['submission_uuid']);
            if ($existing !== null) {
                return $this->replay($existing, $teacherId, $payload);
            }

            $submittedAt = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
            if ($payload['occurred_at'] > $submittedAt) {
                throw new DomainException('LF_TEACHER_JUDGMENT_FUTURE_OCCURRENCE');
            }

            $priorLineage = $this->lockPriorLineage($customerId, $prior);
            $contextSnapshot = $this->json($this->contextSnapshot($context));
            $judgmentId = DB::table('core_liveclass_teacher_judgments')->insertGetId([
                'customer_id' => $customerId,
                'submission_uuid' => $payload['submission_uuid'],
                'cohort_id' => $context['cohort']->id,
                'cohort_teacher_assignment_id' => $context['assignment']->id,
                'cohort_student_membership_id' => $context['membership']->id,
                'enrollment_id' => $context['enrollment']->id,
                'teacher_id' => $teacherId,
                'student_id' => $context['membership']->student_id,
                'framework_id' => $context['basis']->framework_id,
                'basis_framework_version_id' => $context['basis']->id,
                'learning_node_id' => $context['node']->id,
                'mastery_level_key' => $payload['mastery_level_key'],
                'mastery_score' => $payload['mastery_score'],
                'reason' => $payload['reason'],
                'context_snapshot' => $contextSnapshot,
                'occurred_at' => $payload['occurred_at'],
                'submitted_at' => $submittedAt,
                'supersedes_judgment_id' => $prior?->id,
                'created_at' => $submittedAt,
            ]);

            $qualificationSnapshot = $this->json([
                'rule_key' => self::RULE_KEY,
                'rule_version' => self::RULE_VERSION,
                'source_type' => LearningEvidenceSourceGate::INITIAL_SOURCE,
                'source_id' => $judgmentId,
                'submission_uuid' => $payload['submission_uuid'],
                'context_snapshot' => json_decode($contextSnapshot, true, 512, JSON_THROW_ON_ERROR),
            ]);
            $evidenceId = DB::table('core_learning_evidence')->insertGetId([
                'customer_id' => $customerId,
                'user_id' => $context['membership']->student_id,
                'learning_node_id' => $context['node']->id,
                'evidence_type' => 'expert_judgment',
                'source_type' => LearningEvidenceSourceGate::INITIAL_SOURCE,
                'source_id' => $judgmentId,
                'source_discriminator' => $payload['submission_uuid'],
                'producer_idempotency_key' => $payload['submission_uuid'],
                'source_occurred_at' => $payload['occurred_at'],
                'evaluated_at' => $submittedAt,
                'value_numeric' => $payload['mastery_score'],
                'value_label' => $payload['mastery_level_key'],
                'qualification_rule_key' => self::RULE_KEY,
                'qualification_rule_version' => self::RULE_VERSION,
                'qualification_rule_snapshot' => $qualificationSnapshot,
                'valid_from' => null,
                'valid_until' => null,
                'reassessment_due_at' => null,
                'supersedes_evidence_id' => $priorLineage?->evidence_id,
                'recorded_by' => $teacherId,
                'created_at' => $submittedAt,
            ]);

            $calculationRuleSnapshot = $this->json([
                'rule_key' => self::RULE_KEY,
                'rule_version' => self::RULE_VERSION,
                'source_type' => LearningEvidenceSourceGate::INITIAL_SOURCE,
                'submission_uuid' => $payload['submission_uuid'],
            ]);
            $calculationId = DB::table('core_learning_mastery_calculations')->insertGetId([
                'customer_id' => $customerId,
                'user_id' => $context['membership']->student_id,
                'framework_id' => $context['basis']->framework_id,
                'node_definition_id' => $context['node']->node_definition_id,
                'basis_framework_version_id' => $context['basis']->id,
                'calculation_source' => 'teacher_override',
                'calculation_idempotency_key' => $payload['submission_uuid'],
                'mastery_level_key' => $payload['mastery_level_key'],
                'mastery_score' => $payload['mastery_score'],
                'calculation_rule_key' => self::RULE_KEY,
                'calculation_rule_version' => self::RULE_VERSION,
                'calculation_rule_snapshot' => $calculationRuleSnapshot,
                'mastery_scale_key' => $context['basis']->mastery_scale_key,
                'mastery_scale_version' => $context['basis']->mastery_scale_version,
                'mastery_scale_snapshot' => $context['basis']->mastery_scale_snapshot,
                'continuity_policy_snapshot' => null,
                'source_node_relation_id' => null,
                'source_calculation_id' => $priorLineage?->calculation_id,
                'mastery_status_result' => 'established',
                'reassessment_due_at' => null,
                'reason' => $payload['reason'],
                'calculated_at' => $submittedAt,
                'calculated_by' => $teacherId,
                'created_at' => $submittedAt,
            ]);

            DB::table('core_learning_calculation_evidence')->insert([
                'customer_id' => $customerId,
                'user_id' => $context['membership']->student_id,
                'mastery_calculation_id' => $calculationId,
                'evidence_id' => $evidenceId,
                'evidence_role' => 'included',
                'effective_weight' => '1.000000',
                'contribution' => $payload['mastery_score'],
                'reason_code' => self::RULE_KEY,
                'reason_snapshot' => $this->json([
                    'rule_key' => self::RULE_KEY,
                    'rule_version' => self::RULE_VERSION,
                    'explanation' => 'Direct teacher judgment is the sole included evidence.',
                ]),
                'created_at' => $submittedAt,
            ]);

            $profile = $this->projector->project($calculationId);

            return (object) [
                'judgment_id' => $judgmentId,
                'evidence_id' => $evidenceId,
                'calculation_id' => $calculationId,
                'profile_id' => (int) $profile->id,
                'replayed' => false,
            ];
        }, 3);
    }

    /** @param array<string, mixed> $command */
    private function normalizeCommand(array $command): array
    {
        foreach ([
            'submission_uuid', 'cohort_id', 'cohort_teacher_assignment_id',
            'cohort_student_membership_id', 'enrollment_id', 'student_id',
            'basis_framework_version_id', 'learning_node_id',
            'mastery_level_key', 'reason', 'occurred_at',
        ] as $required) {
            if (! array_key_exists($required, $command)) {
                throw new DomainException("LF_TEACHER_JUDGMENT_REQUIRED:{$required}");
            }
        }

        $uuid = strtolower(trim((string) $command['submission_uuid']));
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid) !== 1) {
            throw new DomainException('LF_TEACHER_JUDGMENT_UUID_INVALID');
        }

        $reason = trim((string) $command['reason']);
        $level = trim((string) $command['mastery_level_key']);
        if ($reason === '' || $level === '') {
            throw new DomainException('LF_TEACHER_JUDGMENT_RESULT_INVALID');
        }

        $score = $command['mastery_score'] ?? null;
        if ($score !== null && (! is_numeric($score) || (float) $score < 0 || (float) $score > 1)) {
            throw new DomainException('LF_TEACHER_JUDGMENT_SCORE_INVALID');
        }

        return [
            'submission_uuid' => $uuid,
            'cohort_id' => (int) $command['cohort_id'],
            'cohort_teacher_assignment_id' => (int) $command['cohort_teacher_assignment_id'],
            'cohort_student_membership_id' => (int) $command['cohort_student_membership_id'],
            'enrollment_id' => (int) $command['enrollment_id'],
            'student_id' => (int) $command['student_id'],
            'basis_framework_version_id' => (int) $command['basis_framework_version_id'],
            'learning_node_id' => (int) $command['learning_node_id'],
            'mastery_level_key' => $level,
            'mastery_score' => $score === null ? null : number_format((float) $score, 6, '.', ''),
            'reason' => $reason,
            'occurred_at' => CarbonImmutable::parse((string) $command['occurred_at'], 'UTC')
                ->utc()->format('Y-m-d H:i:s.u'),
            'supersedes_judgment_id' => isset($command['supersedes_judgment_id'])
                ? (int) $command['supersedes_judgment_id'] : null,
        ];
    }

    private function lockPrior(int $customerId, ?int $priorId): ?object
    {
        if ($priorId === null) {
            return null;
        }

        $prior = DB::table('core_liveclass_teacher_judgments')
            ->where('customer_id', $customerId)->where('id', $priorId)
            ->lockForUpdate()->first();

        if ($prior === null) {
            throw new RecordsNotFoundException('Teacher Judgment predecessor not found in tenant.');
        }

        return $prior;
    }

    /** @param array<string, mixed> $payload */
    private function lockAndAuthorize(int $customerId, int $teacherId, array $payload, ?object $prior): array
    {
        $teacher = $this->lockRow('users', $customerId, $teacherId);
        if ($teacher->role !== 'teacher' || $teacher->status !== 'active') {
            throw new AuthorizationException('LF_TEACHER_JUDGMENT_TEACHER_DENIED');
        }

        $cohort = $this->lockRow('core_course_cohorts', $customerId, $payload['cohort_id']);
        $assignment = $this->lockRow(
            'core_course_cohort_teachers', $customerId, $payload['cohort_teacher_assignment_id']
        );
        $membership = $this->lockRow(
            'core_course_cohort_students', $customerId, $payload['cohort_student_membership_id']
        );
        $enrollment = $this->lockRow('core_course_enrollments', $customerId, $payload['enrollment_id']);
        $basis = $this->lockRow(
            'core_learning_framework_versions', $customerId, $payload['basis_framework_version_id']
        );
        $node = $this->lockRow('core_learning_nodes', $customerId, $payload['learning_node_id']);
        $definition = $this->lockRow(
            'core_learning_node_definitions', $customerId, $node->node_definition_id
        );

        $occurredDate = substr($payload['occurred_at'], 0, 10);
        if ($cohort->status !== 'active'
            || $cohort->start_date === null || $cohort->end_date === null
            || $occurredDate < $cohort->start_date || $occurredDate > $cohort->end_date
            || (int) $assignment->cohort_id !== (int) $cohort->id
            || (int) $assignment->teacher_id !== $teacherId
            || $assignment->status !== 'active'
            || ($assignment->assigned_from !== null && $occurredDate < $assignment->assigned_from)
            || ($assignment->assigned_to !== null && $occurredDate > $assignment->assigned_to)) {
            throw new AuthorizationException('LF_TEACHER_JUDGMENT_ASSIGNMENT_DENIED');
        }

        if ($membership->status !== 'active'
            || (int) $membership->cohort_id !== (int) $cohort->id
            || (int) $membership->enrollment_id !== (int) $enrollment->id
            || (int) $membership->student_id !== $payload['student_id']
            || $membership->left_at !== null
            || $this->timestamp($membership->joined_at) > $payload['occurred_at']) {
            throw new AuthorizationException('LF_TEACHER_JUDGMENT_MEMBERSHIP_DENIED');
        }

        if ($enrollment->status !== 'active'
            || (int) $enrollment->student_id !== (int) $membership->student_id
            || (int) $enrollment->product_id !== (int) $membership->product_id
            || (int) $cohort->product_id !== (int) $membership->product_id
            || (int) $enrollment->version_id !== (int) $cohort->version_id) {
            throw new AuthorizationException('LF_TEACHER_JUDGMENT_ENROLLMENT_DENIED');
        }

        if ($basis->status !== 'published'
            || (int) $node->framework_id !== (int) $basis->framework_id
            || (int) $node->framework_version_id !== (int) $basis->id
            || $node->status !== 'active' || $definition->status !== 'active') {
            throw new DomainException('LF_TEACHER_JUDGMENT_BASIS_INVALID');
        }

        if ($prior !== null && ! $this->priorIdentityMatches($prior, $payload, $membership, $basis, $node)) {
            throw new DomainException('LF_TEACHER_JUDGMENT_CORRECTION_INVALID');
        }

        $this->assertScaleResult(
            $basis->mastery_scale_snapshot,
            $payload['mastery_level_key'],
            $payload['mastery_score'],
        );

        return compact('teacher', 'cohort', 'assignment', 'membership', 'enrollment', 'basis', 'node', 'definition');
    }

    private function lockRow(string $table, int $customerId, int $id): object
    {
        $row = DB::table($table)->where('customer_id', $customerId)->where('id', $id)
            ->lockForUpdate()->first();

        if ($row === null) {
            throw new RecordsNotFoundException("{$table} row not found in tenant.");
        }

        return $row;
    }

    private function priorIdentityMatches(
        object $prior,
        array $payload,
        object $membership,
        object $basis,
        object $node,
    ): bool {
        return (int) $prior->cohort_id === $payload['cohort_id']
            && (int) $prior->cohort_student_membership_id === (int) $membership->id
            && (int) $prior->enrollment_id === $payload['enrollment_id']
            && (int) $prior->student_id === $payload['student_id']
            && (int) $prior->framework_id === (int) $basis->framework_id
            && (int) $prior->basis_framework_version_id === (int) $basis->id
            && (int) $prior->learning_node_id === (int) $node->id
            && $this->timestamp($prior->occurred_at) === $payload['occurred_at'];
    }

    private function lockPriorLineage(int $customerId, ?object $prior): ?object
    {
        if ($prior === null) {
            return null;
        }

        $evidence = DB::table('core_learning_evidence')
            ->where('customer_id', $customerId)
            ->where('source_type', LearningEvidenceSourceGate::INITIAL_SOURCE)
            ->where('source_id', $prior->id)->lockForUpdate()->first();
        $calculation = DB::table('core_learning_mastery_calculations')
            ->where('customer_id', $customerId)
            ->where('calculation_source', 'teacher_override')
            ->where('calculation_idempotency_key', $prior->submission_uuid)
            ->lockForUpdate()->first();

        if ($evidence === null || $calculation === null) {
            throw new DomainException('LF_TEACHER_JUDGMENT_LINEAGE_INCOMPLETE');
        }

        return (object) ['evidence_id' => $evidence->id, 'calculation_id' => $calculation->id];
    }

    private function findExisting(int $customerId, string $uuid): ?object
    {
        return DB::table('core_liveclass_teacher_judgments')
            ->where('customer_id', $customerId)->where('submission_uuid', $uuid)
            ->lockForUpdate()->first();
    }

    /** @param array<string, mixed> $payload */
    private function replay(object $judgment, int $teacherId, array $payload): object
    {
        $matches = (int) $judgment->teacher_id === $teacherId
            && (int) $judgment->cohort_id === $payload['cohort_id']
            && (int) $judgment->cohort_teacher_assignment_id === $payload['cohort_teacher_assignment_id']
            && (int) $judgment->cohort_student_membership_id === $payload['cohort_student_membership_id']
            && (int) $judgment->enrollment_id === $payload['enrollment_id']
            && (int) $judgment->student_id === $payload['student_id']
            && (int) $judgment->basis_framework_version_id === $payload['basis_framework_version_id']
            && (int) $judgment->learning_node_id === $payload['learning_node_id']
            && $judgment->mastery_level_key === $payload['mastery_level_key']
            && $this->decimal($judgment->mastery_score) === $this->decimal($payload['mastery_score'])
            && $judgment->reason === $payload['reason']
            && $this->timestamp($judgment->occurred_at) === $payload['occurred_at']
            && (int) ($judgment->supersedes_judgment_id ?? 0) === (int) ($payload['supersedes_judgment_id'] ?? 0);

        if (! $matches) {
            throw new DomainException('LF_TEACHER_JUDGMENT_IDEMPOTENCY_CONFLICT');
        }

        $evidence = DB::table('core_learning_evidence')
            ->where('customer_id', $judgment->customer_id)
            ->where('source_type', LearningEvidenceSourceGate::INITIAL_SOURCE)
            ->where('source_id', $judgment->id)->lockForUpdate()->first();
        $calculation = DB::table('core_learning_mastery_calculations')
            ->where('customer_id', $judgment->customer_id)
            ->where('calculation_source', 'teacher_override')
            ->where('calculation_idempotency_key', $judgment->submission_uuid)
            ->lockForUpdate()->first();

        if ($evidence === null || $calculation === null) {
            throw new DomainException('LF_TEACHER_JUDGMENT_LINEAGE_INCOMPLETE');
        }

        $profile = $this->projector->project((int) $calculation->id);

        return (object) [
            'judgment_id' => (int) $judgment->id,
            'evidence_id' => (int) $evidence->id,
            'calculation_id' => (int) $calculation->id,
            'profile_id' => (int) $profile->id,
            'replayed' => true,
        ];
    }

    private function assertScaleResult(string $snapshot, string $level, ?string $score): void
    {
        $scale = json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR);
        $levels = $scale['levels'] ?? null;
        if (! is_array($levels) || count($levels) < 2) {
            throw new DomainException('LF_TEACHER_JUDGMENT_SCALE_INVALID');
        }

        $keys = [];
        $previous = null;
        $selected = null;
        foreach ($levels as $item) {
            $key = is_array($item) ? trim((string) ($item['key'] ?? '')) : '';
            $threshold = is_array($item) && is_numeric($item['threshold'] ?? null)
                ? (float) $item['threshold'] : null;
            if ($key === '' || $threshold === null || isset($keys[$key])
                || ($previous !== null && $threshold <= $previous)) {
                throw new DomainException('LF_TEACHER_JUDGMENT_SCALE_INVALID');
            }
            $keys[$key] = $threshold;
            $previous = $threshold;
            if ($score !== null && (float) $score >= $threshold) {
                $selected = $key;
            }
        }

        if (! array_key_exists($level, $keys) || ($score !== null && $selected !== $level)) {
            throw new DomainException('LF_TEACHER_JUDGMENT_RESULT_INVALID');
        }
    }

    private function contextSnapshot(array $context): array
    {
        return [
            'schema' => 'teacher_judgment_context',
            'version' => 1,
            'cohort' => $this->pick($context['cohort'], ['id', 'status', 'product_id', 'version_id', 'start_date', 'end_date']),
            'assignment' => $this->pick($context['assignment'], ['id', 'cohort_id', 'teacher_id', 'role', 'status', 'assigned_from', 'assigned_to']),
            'membership' => $this->pick($context['membership'], ['id', 'cohort_id', 'enrollment_id', 'product_id', 'student_id', 'status', 'joined_at', 'left_at']),
            'enrollment' => $this->pick($context['enrollment'], ['id', 'product_id', 'version_id', 'student_id', 'status']),
            'basis' => $this->pick($context['basis'], ['id', 'framework_id', 'version_code', 'status', 'mastery_scale_key', 'mastery_scale_version']),
            'node' => $this->pick($context['node'], ['id', 'framework_id', 'framework_version_id', 'node_definition_id', 'status']),
            'rule' => ['key' => self::RULE_KEY, 'version' => self::RULE_VERSION],
        ];
    }

    private function pick(object $row, array $keys): array
    {
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = $row->{$key};
        }

        return $values;
    }

    private function timestamp(mixed $value): string
    {
        return CarbonImmutable::parse($value, 'UTC')->utc()->format('Y-m-d H:i:s.u');
    }

    private function decimal(mixed $value): ?string
    {
        return $value === null ? null : number_format((float) $value, 6, '.', '');
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
