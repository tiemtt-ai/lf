{{-- Add or edit a teacher assignment of a Template as a UI Kit popup (the teachers tab). --}}
@php
    $isEdit = isset($assignment) && $assignment !== null;
    $star = '<span class="ui-field__required" aria-hidden="true">*</span>';
    $selectAttributes = 'data-ui-kit-select data-ui-empty-label="'.e(__('lf.LF_course_template_index_no_options')).'" data-ui-clear-label="'.e(__('lf.LF_common_button_clear')).'"';
@endphp
<x-ui-form-dialog
    :action="$isEdit ? route($routePrefix.'.update', [$template->id, $assignment->id]) : route($routePrefix.'.store', $template->id)"
    :method="$isEdit ? 'PUT' : 'POST'"
    :title="$isEdit ? __('lf.LF_course_template_teacher_common_edit_title') : __('lf.LF_course_template_teacher_common_create')"
    :submit-label="$isEdit ? __('lf.LF_common_button_save_changes') : __('lf.LF_course_template_teacher_assign_submit')"
    title-id="teacher-dialog-title">
    <div class="ui-modal__grid">
        <div class="ui-field ui-field--full">
            @if ($isEdit)
                <span class="ui-field__label">{{ __('lf.LF_course_template_teacher_common_teacher') }}</span>
                @php
                    $initials = collect(preg_split('/\s+/u', trim((string) $assignment->teacher_name)) ?: [])
                        ->filter()->map(fn (string $word) => mb_strtoupper(mb_substr($word, 0, 1)))->take(2)->implode('');
                @endphp
                <span class="ui-teacher-identity">
                    <span class="ui-teacher-identity__avatar" aria-hidden="true">{{ $initials ?: '?' }}</span>
                    <span class="ui-teacher-identity__text">
                        <b>{{ $assignment->teacher_name }}</b>
                        <span class="ui-caption">{{ $assignment->teacher_email }}</span>
                    </span>
                </span>
            @else
                <label class="ui-field__label" for="teacher-form-teacher_id">{!! $star !!}{{ __('lf.LF_course_template_teacher_common_teacher') }}</label>
                <select id="teacher-form-teacher_id" name="teacher_id" required {!! $selectAttributes !!}>
                    <option value="">{{ __('lf.LF_course_template_teacher_common_select_teacher') }}</option>
                    @foreach ($teachers as $teacher)
                        <option value="{{ $teacher->id }}">{{ $teacher->name }} ({{ $teacher->email }})</option>
                    @endforeach
                </select>
                @if ($teachers->isEmpty())
                    <span class="ui-field__hint">{{ __('lf.LF_course_template_teacher_no_available') }}</span>
                @else
                    <span class="ui-field__hint">{{ __('lf.LF_course_template_teacher_reassign_hint') }}</span>
                @endif
            @endif
        </div>

        <div class="ui-field ui-field--full">
            <label class="ui-field__label" for="teacher-form-role">{!! $star !!}{{ __('lf.LF_course_template_teacher_common_role') }}</label>
            <select id="teacher-form-role" name="role" required {!! $selectAttributes !!}>
                <option value="">{{ __('lf.LF_course_template_teacher_common_select_role') }}</option>
                @foreach ($assignmentRoles as $role)
                    <option value="{{ $role }}" @selected(($assignment->role ?? '') === $role)>{{ __('lf.LF_course_template_teacher_common_role_'.$role) }}</option>
                @endforeach
            </select>
            <span class="ui-field__hint">{{ __('lf.LF_course_template_teacher_role_help') }}</span>
        </div>
    </div>
</x-ui-form-dialog>
