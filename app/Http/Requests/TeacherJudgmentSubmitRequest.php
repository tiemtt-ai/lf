<?php

namespace App\Http\Requests;

use App\Rules\TimestampWithOffset;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape of a Teacher Judgment submission. Not its authorization.
 *
 * TeacherJudgmentService enforces seven rules that have no database backstop —
 * teacher role and status, Cohort status and period, assignment coherence and
 * range, membership coherence, Enrollment coherence, basis and Node
 * eligibility, and result validity under the frozen scale. None of them is
 * restated here. Two sources of truth for an authorization rule is worse than
 * one that lives deep, because only one of them gets updated.
 *
 * The role check below and the `role:teacher` route middleware are early denials
 * for the caller's benefit. The service check remains the decision. Removing it
 * because "the route already checks" is a regression, not a cleanup.
 */
class TeacherJudgmentSubmitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'teacher';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'submission_uuid' => ['required', 'string', 'uuid'],
            'cohort_id' => ['required', 'integer', 'min:1'],
            'cohort_teacher_assignment_id' => ['required', 'integer', 'min:1'],
            'cohort_student_membership_id' => ['required', 'integer', 'min:1'],
            'enrollment_id' => ['required', 'integer', 'min:1'],
            'student_id' => ['required', 'integer', 'min:1'],
            'basis_framework_version_id' => ['required', 'integer', 'min:1'],
            'learning_node_id' => ['required', 'integer', 'min:1'],
            'mastery_level_key' => ['required', 'string', 'max:100'],
            'mastery_score' => ['nullable', 'numeric', 'between:0,1'],
            'reason' => ['required', 'string', 'max:5000'],
            'occurred_at' => ['required', 'string', new TimestampWithOffset],
            'supersedes_judgment_id' => ['nullable', 'integer', 'min:1'],

            // "No request field can override tenant, teacher actor or
            // recorded-by identity." Prohibiting them is the only form of that
            // rule a test can fail on; trusting the controller to ignore them
            // is not.
            'customer_id' => ['prohibited'],
            'teacher_id' => ['prohibited'],
            'recorded_by' => ['prohibited'],
            'calculated_by' => ['prohibited'],
            'framework_id' => ['prohibited'],
            'context_snapshot' => ['prohibited'],
            'submitted_at' => ['prohibited'],
            'created_at' => ['prohibited'],
        ];
    }

    /**
     * The command handed to the service, built from validated input and nothing
     * else. The acting teacher is passed separately by the controller from the
     * authenticated session.
     *
     * @return array<string, mixed>
     */
    public function command(): array
    {
        return $this->validated();
    }
}
