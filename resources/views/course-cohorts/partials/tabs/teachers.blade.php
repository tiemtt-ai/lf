<div class="admin-card admin-form-card admin-form-surface">
    <div class="admin-form-standard">
        <section class="admin-form-standard-section">
            <header class="admin-form-section-header">
                <h2 class="admin-form-section-title">{{ __('lf.LF_course_cohort_teacher_title') }}</h2>
                <p class="admin-form-section-help">{{ __('lf.LF_course_cohort_teacher_help') }}</p>
            </header>

            @if (in_array($cohort->status, ['draft', 'active'], true))
                <form method="POST" action="{{ route('admin.course-cohorts.teachers.store', $cohort->id) }}" class="admin-form-field-grid">
                    @csrf
                    <div class="lf-form-group">
                        <x-form-label for="teacher_id" :value="__('lf.LF_course_cohort_teacher_teacher')" :required="true" />
                        <select id="teacher_id" name="teacher_id" class="lf-form-control" required>
                            <option value="">{{ __('lf.LF_course_cohort_teacher_select') }}</option>
                            @foreach ($availableTeachers as $teacher)
                                <option value="{{ $teacher->id }}">{{ $teacher->name }} · {{ $teacher->email }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="lf-form-group">
                        <x-form-label for="teacher_role" :value="__('lf.LF_course_cohort_teacher_role')" :required="true" />
                        <select id="teacher_role" name="role" class="lf-form-control" required>
                            @foreach (['primary_teacher', 'teacher', 'assistant'] as $role)
                                <option value="{{ $role }}">{{ __('lf.LF_course_cohort_teacher_role_'.$role) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="lf-form-group">
                        <x-form-label for="assigned_from" :value="__('lf.LF_course_cohort_teacher_from')" />
                        <input id="assigned_from" type="date" name="assigned_from" class="lf-form-control">
                    </div>
                    <div class="lf-form-group">
                        <x-form-label for="assigned_to" :value="__('lf.LF_course_cohort_teacher_to')" />
                        <input id="assigned_to" type="date" name="assigned_to" class="lf-form-control">
                    </div>
                    <div class="admin-form-actions admin-form-field--full">
                        <button class="btn btn-primary" type="submit">{{ __('lf.LF_course_cohort_teacher_add') }}</button>
                    </div>
                </form>
            @endif
        </section>

        <section class="admin-form-standard-section">
            <div class="admin-table-wrap">
                <table class="table">
                    <thead><tr>
                        <th>{{ __('lf.LF_course_cohort_teacher_teacher') }}</th>
                        <th>{{ __('lf.LF_course_cohort_teacher_role') }}</th>
                        <th>{{ __('lf.LF_course_cohort_teacher_period') }}</th>
                        <th>{{ __('lf.table_actions') }}</th>
                    </tr></thead>
                    <tbody>
                    @forelse ($teachers as $teacher)
                        <tr>
                            <td><strong>{{ $teacher->teacher_name }}</strong><br><span class="lf-secondary-text">{{ $teacher->teacher_email }}</span></td>
                            <td>{{ __('lf.LF_course_cohort_teacher_role_'.$teacher->role) }}</td>
                            <td>{{ $teacher->assigned_from ?: '—' }} → {{ $teacher->assigned_to ?: '—' }}</td>
                            <td>
                                @if (in_array($cohort->status, ['draft', 'active'], true))
                                    <form method="POST" action="{{ route('admin.course-cohorts.teachers.destroy', [$cohort->id, $teacher->id]) }}">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="admin-text-action admin-danger-text-action">{{ __('lf.LF_common_button_remove') }}</button>
                                    </form>
                                @else
                                    {{ __('lf.LF_course_cohort_tab_read_only') }}
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4"><div class="course-cohort-empty-state">{{ __('lf.LF_course_cohort_teacher_empty') }}</div></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
