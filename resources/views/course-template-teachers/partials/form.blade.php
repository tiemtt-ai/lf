@php
    $formAssignment = $assignment ?? null;
    $selectedTeacherId = old('teacher_id');
    $selectedRole = old('role', $formAssignment?->role ?? '');
    $isRequired = static fn (string $field): bool => in_array(
        $field,
        $requiredFields,
        true
    );
@endphp

<div class="course-template-teacher-form">
    @if ($formAssignment)
        <div class="lf-form-group">
            <span class="lf-form-label">
                {{ __('lf.LF_course_template_teacher_common_teacher') }}
            </span>
            <strong>
                {{ $formAssignment->teacher_name }}
                ({{ $formAssignment->teacher_email }})
            </strong>
        </div>
    @else
        <div class="lf-form-group">
            <x-form-label for="teacher_id"
                          :value="__('lf.LF_course_template_teacher_common_teacher')"
                          :required="$isRequired('teacher_id')" />
            <select id="teacher_id" name="teacher_id"
                    @class(['lf-form-control', 'lf-select-placeholder' => blank($selectedTeacherId)]) required>
                <option value="" disabled @selected(blank($selectedTeacherId))>
                    {{ __('lf.LF_course_template_teacher_common_select_teacher') }}
                </option>
                @foreach ($teachers as $teacher)
                    <option value="{{ $teacher->id }}"
                            @selected(
                                (string) $selectedTeacherId
                                === (string) $teacher->id
                            )>
                        {{ $teacher->name }} ({{ $teacher->email }})
                    </option>
                @endforeach
            </select>
        </div>
    @endif
    <div class="lf-form-group">
        <x-form-label for="role"
                      :value="__('lf.LF_course_template_teacher_common_role')"
                      :required="$isRequired('role')" />
        <select id="role" name="role" @class(['lf-form-control', 'lf-select-placeholder' => $selectedRole === '']) required>
            <option value="" disabled @selected($selectedRole === '')>{{ __('lf.LF_course_template_teacher_common_select_role') }}</option>
            @foreach ($assignmentRoles as $role)
                <option value="{{ $role }}" @selected($selectedRole === $role)>
                    {{ __('lf.LF_course_template_teacher_common_role_'.$role) }}
                </option>
            @endforeach
        </select>
    </div>
</div>
