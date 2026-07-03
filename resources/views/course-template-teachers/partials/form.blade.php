@php
    $formAssignment = $assignment ?? null;
    $selectedRole = old('role', $formAssignment?->role ?? 'assistant');
    $selectedStatus = old('status', $formAssignment?->status ?? 'active');
    $isRequired = static fn (string $field): bool => in_array(
        $field,
        $requiredFields,
        true
    );
@endphp

<section class="admin-form-section"
         aria-labelledby="course-template-teacher-information-title">
    <h2 id="course-template-teacher-information-title"
        class="admin-form-section-title">
        {{ __('lf.LF_course_template_teacher_group_information') }}
    </h2>

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
                    class="lf-form-control" required>
                <option value="">
                    {{ __('lf.LF_course_template_teacher_common_select_teacher') }}
                </option>
                @foreach ($teachers as $teacher)
                    <option value="{{ $teacher->id }}"
                            @selected(
                                (string) old('teacher_id')
                                === (string) $teacher->id
                            )>
                        {{ $teacher->name }} ({{ $teacher->email }})
                    </option>
                @endforeach
            </select>
        </div>
    @endif
</section>

<section class="admin-form-section"
         aria-labelledby="course-template-teacher-role-title">
    <h2 id="course-template-teacher-role-title"
        class="admin-form-section-title">
        {{ __('lf.LF_course_template_teacher_group_role') }}
    </h2>

    <div class="lf-form-group">
        <x-form-label for="role"
                      :value="__('lf.LF_course_template_teacher_common_role')"
                      :required="$isRequired('role')" />
        <select id="role" name="role" class="lf-form-control" required>
            @foreach ($assignmentRoles as $role)
                <option value="{{ $role }}" @selected($selectedRole === $role)>
                    {{ __('lf.LF_course_template_teacher_common_role_'.$role) }}
                </option>
            @endforeach
        </select>
    </div>

    <div class="lf-form-group">
        <x-form-label for="sort_order"
                      :value="__('lf.LF_course_template_teacher_common_sort_order')"
                      :required="$isRequired('sort_order')" />
        <input id="sort_order" type="number" min="0" name="sort_order"
               class="lf-form-control"
               value="{{ old('sort_order', $formAssignment?->sort_order ?? 0) }}"
               required>
    </div>
</section>

<section class="admin-form-section"
         aria-labelledby="course-template-teacher-status-title">
    <h2 id="course-template-teacher-status-title"
        class="admin-form-section-title">
        {{ __('lf.LF_course_template_teacher_group_status') }}
    </h2>

    <div class="lf-form-group">
        <x-form-label for="status"
                      :value="__('lf.LF_course_template_teacher_common_status')"
                      :required="$isRequired('status')" />
        <select id="status" name="status" class="lf-form-control" required>
            @foreach ($statuses as $status)
                <option value="{{ $status }}"
                        @selected($selectedStatus === $status)>
                    {{ __('lf.LF_course_template_teacher_common_status_'.$status) }}
                </option>
            @endforeach
        </select>
    </div>
</section>
