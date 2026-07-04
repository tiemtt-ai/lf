<section class="admin-form-section">
    <h2 class="admin-form-section-title">
        {{ __('lf.LF_course_cohort_student_group_lifecycle') }}
    </h2>

    <div class="lf-form-group">
        <label class="lf-form-label" for="status">
            {{ __('lf.LF_course_cohort_student_common_status') }}
        </label>
        <select id="status" name="status" class="lf-form-control" required>
            @foreach ($statuses as $status)
                <option value="{{ $status }}" @selected(old('status', $membership->status ?? 'active') === $status)>
                    {{ __('lf.LF_course_cohort_student_common_'.$status) }}
                </option>
            @endforeach
        </select>
    </div>

    <div class="lf-form-group">
        <label class="lf-form-label" for="joined_at">
            {{ __('lf.LF_course_cohort_student_common_joined_at') }}
        </label>
        <input id="joined_at"
               type="datetime-local"
               name="joined_at"
               class="lf-form-control"
               value="{{ old('joined_at', optional(($membership?->joined_at ?? null) ? \Illuminate\Support\Carbon::parse($membership->joined_at) : null)->format('Y-m-d\\TH:i')) }}"
               required>
    </div>

    <div class="lf-form-group">
        <label class="lf-form-label" for="left_at">
            {{ __('lf.LF_course_cohort_student_common_left_at') }}
        </label>
        <input id="left_at"
               type="datetime-local"
               name="left_at"
               class="lf-form-control"
               value="{{ old('left_at', optional(($membership?->left_at ?? null) ? \Illuminate\Support\Carbon::parse($membership->left_at) : null)->format('Y-m-d\\TH:i')) }}">
    </div>

    <div class="lf-form-group">
        <label class="lf-form-label" for="transfer_reason">
            {{ __('lf.LF_course_cohort_student_common_transfer_reason') }}
        </label>
        <input id="transfer_reason"
               type="text"
               name="transfer_reason"
               class="lf-form-control"
               maxlength="500"
               value="{{ old('transfer_reason', $membership->transfer_reason ?? '') }}">
    </div>

    <div class="lf-form-group">
        <label class="lf-form-label" for="note">
            {{ __('lf.LF_course_cohort_student_common_note') }}
        </label>
        <textarea id="note"
                  name="note"
                  class="lf-form-control"
                  rows="3">{{ old('note', $membership->note ?? '') }}</textarea>
    </div>

    <div class="lf-form-group">
        <label class="lf-form-label" for="metadata">
            {{ __('lf.LF_course_cohort_student_common_metadata') }}
        </label>
        <textarea id="metadata"
                  name="metadata"
                  class="lf-form-control"
                  rows="3">{{ old('metadata', $membership->metadata ?? '') }}</textarea>
    </div>
</section>
