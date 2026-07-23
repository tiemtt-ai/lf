<section class="admin-form-standard-section" aria-labelledby="cohort-student-edit-lifecycle">
    <header class="admin-form-section-header">
        <h2 id="cohort-student-edit-lifecycle" class="admin-form-section-title">
            {{ __('lf.LF_course_cohort_student_group_lifecycle') }}
        </h2>
        <p class="admin-form-section-help">
            {{ __('lf.LF_course_cohort_student_lifecycle_edit_help') }}
        </p>
    </header>

    <input type="hidden" name="status" value="active">

    <div class="admin-form-field-grid course-cohort-student-edit-fields">
        <div class="lf-form-group admin-form-field course-cohort-student-edit-item">
            <span class="lf-form-label">{{ __('lf.LF_course_cohort_student_common_status') }}</span>
            <div class="cohort-edit-readonly-row">
                <span class="badge badge-success">
                    {{ __('lf.LF_course_cohort_student_common_active') }}
                </span>
            </div>
        </div>

        <div class="lf-form-group admin-form-field course-cohort-student-edit-item">
            <label class="lf-form-label" for="joined_at">
                {{ __('lf.LF_course_cohort_student_common_joined_at') }}
            </label>
            <input id="joined_at"
                   type="datetime-local"
                   name="joined_at"
                   class="lf-form-control"
                   value="{{ old('joined_at', optional(($membership?->joined_at ?? null) ? \Illuminate\Support\Carbon::parse($membership->joined_at) : null)->format('Y-m-d\TH:i')) }}"
                   required>
            @error('joined_at')<p class="lf-form-error" role="alert">{{ $message }}</p>@enderror
        </div>

        <div class="lf-form-group admin-form-field admin-form-field--full">
            <label class="lf-form-label" for="transfer_reason">
                {{ __('lf.LF_course_cohort_student_common_transfer_reason') }}
            </label>
            <input id="transfer_reason"
                   type="text"
                   name="transfer_reason"
                   class="lf-form-control"
                   maxlength="500"
                   value="{{ old('transfer_reason', $membership->transfer_reason ?? '') }}"
                   placeholder="{{ __('lf.LF_course_cohort_student_transfer_reason_placeholder') }}">
            <p class="lf-form-help">{{ __('lf.LF_course_cohort_student_transfer_reason_help') }}</p>
            @error('transfer_reason')<p class="lf-form-error" role="alert">{{ $message }}</p>@enderror
        </div>

        <div class="lf-form-group admin-form-field admin-form-field--full">
            <label class="lf-form-label" for="note">
                {{ __('lf.LF_course_cohort_student_common_note') }}
            </label>
            <textarea id="note"
                      name="note"
                      class="lf-form-control"
                      rows="4"
                      placeholder="{{ __('lf.LF_course_cohort_student_note_placeholder') }}">{{ old('note', $membership->note ?? '') }}</textarea>
            <p class="lf-form-help">{{ __('lf.LF_course_cohort_student_note_help') }}</p>
            @error('note')<p class="lf-form-error" role="alert">{{ $message }}</p>@enderror
        </div>
    </div>
</section>
