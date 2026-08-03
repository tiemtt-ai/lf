<div class="admin-card admin-form-card admin-form-surface course-cohort-teachers">
    <div class="admin-form-standard">
        <section class="admin-form-standard-section">
            <header class="admin-form-section-header course-cohort-teachers__header">
                <div>
                    <h2 class="admin-form-section-title">{{ __('lf.LF_course_cohort_teacher_title') }}</h2>
                    <p class="admin-form-section-help">{{ __('lf.LF_course_cohort_teacher_help') }}</p>
                </div>
                <span class="course-cohort-teachers__count">
                    {{ trans_choice('lf.LF_course_cohort_teacher_count', $teachers->count(), ['count' => $teachers->count()]) }}
                </span>
            </header>

            @if (in_array($cohort->status, ['draft', 'active'], true))
                <div class="course-cohort-teacher-assignment" x-data="{ open: @js($errors->hasAny(['teacher_id', 'role', 'assigned_from', 'assigned_to'])) }">
                    <button type="button" class="btn admin-primary-outline-action course-cohort-teacher-assignment__toggle"
                            x-on:click="open = !open" x-bind:aria-expanded="open.toString()" aria-controls="cohort-teacher-assignment-form">
                        <span x-text="open ? @js(__('lf.LF_course_cohort_teacher_close_form')) : @js(__('lf.LF_course_cohort_teacher_assign'))"></span>
                        <svg aria-hidden="true" viewBox="0 0 20 20" x-bind:class="{ 'is-open': open }">
                            <path d="m5 7.5 5 5 5-5" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"/>
                        </svg>
                    </button>
                    <form id="cohort-teacher-assignment-form" method="POST" action="{{ route('admin.course-cohorts.teachers.store', $cohort->id) }}"
                          class="course-cohort-teacher-assignment__form" x-show="open" x-cloak x-transition.opacity.duration.150ms>
                        @csrf
                        <div class="lf-form-group course-cohort-teacher-assignment__teacher">
                            <x-form-label for="teacher_id" :value="__('lf.LF_course_cohort_teacher_teacher')" :required="true" />
                            <select id="teacher_id" name="teacher_id" class="lf-form-control" required>
                                <option value="">{{ __('lf.LF_course_cohort_teacher_select') }}</option>
                                @foreach ($availableTeachers as $teacher)
                                    <option value="{{ $teacher->id }}" @selected((string) old('teacher_id') === (string) $teacher->id)>{{ $teacher->name }} · {{ $teacher->email }}</option>
                                @endforeach
                            </select>
                            @error('teacher_id')<p class="lf-form-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                        <div class="lf-form-group">
                            <x-form-label for="teacher_role" :value="__('lf.LF_course_cohort_teacher_role')" :required="true" />
                            <select id="teacher_role" name="role" class="lf-form-control" required>
                                @foreach (['primary_teacher', 'teacher', 'assistant'] as $role)
                                    <option value="{{ $role }}" @selected(old('role', 'primary_teacher') === $role)>{{ __('lf.LF_course_cohort_teacher_role_'.$role) }}</option>
                                @endforeach
                            </select>
                            @error('role')<p class="lf-form-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                        <div class="lf-form-group">
                            <x-form-label for="assigned_from" :value="__('lf.LF_course_cohort_teacher_from')" />
                            <input id="assigned_from" type="date" name="assigned_from" class="lf-form-control" value="{{ old('assigned_from') }}"
                                   @if($cohort->start_date) min="{{ $cohort->start_date }}" @endif @if($cohort->end_date) max="{{ $cohort->end_date }}" @endif>
                        </div>
                        <div class="lf-form-group">
                            <x-form-label for="assigned_to" :value="__('lf.LF_course_cohort_teacher_to')" />
                            <input id="assigned_to" type="date" name="assigned_to" class="lf-form-control" value="{{ old('assigned_to') }}"
                                   @if($cohort->start_date) min="{{ $cohort->start_date }}" @endif @if($cohort->end_date) max="{{ $cohort->end_date }}" @endif>
                        </div>
                        @if($errors->has('assigned_from') || $errors->has('assigned_to'))
                            <div class="course-cohort-teacher-assignment__date-errors" role="alert">
                                @error('assigned_from')<p>{{ $message }}</p>@enderror
                                @error('assigned_to')<p>{{ $message }}</p>@enderror
                            </div>
                        @endif
                        @if($cohort->start_date || $cohort->end_date)
                            <p class="course-cohort-teacher-assignment__date-help">
                                {{ __('lf.LF_course_cohort_teacher_operating_period', [
                                    'from' => $cohort->start_date ? \Illuminate\Support\Carbon::parse($cohort->start_date)->format('d/m/Y') : '—',
                                    'to' => $cohort->end_date ? \Illuminate\Support\Carbon::parse($cohort->end_date)->format('d/m/Y') : '—',
                                ]) }}
                            </p>
                        @endif
                        <div class="admin-form-actions course-cohort-teacher-assignment__actions">
                            <button class="btn btn-primary" type="submit">{{ __('lf.LF_course_cohort_teacher_add') }}</button>
                        </div>
                    </form>
                </div>
            @endif

            <div class="admin-table-wrap course-cohort-teachers__table">
                <table class="table course-cohort-roster-table">
                    <thead><tr>
                        <th>{{ __('lf.LF_course_cohort_teacher_teacher') }}</th>
                        <th>{{ __('lf.LF_course_cohort_teacher_role') }}</th>
                        <th>{{ __('lf.LF_course_cohort_teacher_period') }}</th>
                        <th class="course-cohort-teachers__actions">{{ __('lf.table_actions') }}</th>
                    </tr></thead>
                    <tbody>
                    @forelse ($teachers as $teacher)
                        <tr>
                            <td data-label="{{ __('lf.LF_course_cohort_teacher_teacher') }}">
                                <div class="course-cohort-teacher-identity">
                                    <span class="course-cohort-teacher-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr(trim($teacher->teacher_name), 0, 1)) }}</span>
                                    <span class="course-cohort-teacher-identity__content">
                                        <strong>{{ $teacher->teacher_name }}</strong>
                                        <span class="cohort-student-option-meta">{{ $teacher->teacher_email }}</span>
                                    </span>
                                </div>
                            </td>
                            <td data-label="{{ __('lf.LF_course_cohort_teacher_role') }}"><span @class(['badge', 'course-cohort-teacher-role-badge', 'badge-success' => $teacher->role === 'primary_teacher'])>{{ __('lf.LF_course_cohort_teacher_role_'.$teacher->role) }}</span></td>
                            <td data-label="{{ __('lf.LF_course_cohort_teacher_period') }}">
                                <span class="course-cohort-teachers__period">
                                    <span>{{ $teacher->assigned_from ? \Illuminate\Support\Carbon::parse($teacher->assigned_from)->format('d/m/Y') : '—' }}</span>
                                    <span aria-hidden="true">→</span>
                                    <span>{{ $teacher->assigned_to ? \Illuminate\Support\Carbon::parse($teacher->assigned_to)->format('d/m/Y') : '—' }}</span>
                                </span>
                            </td>
                            <td class="course-cohort-teachers__actions" data-label="{{ __('lf.table_actions') }}">
                                @if (in_array($cohort->status, ['draft', 'active'], true))
                                    <div class="admin-table-actions course-cohort-teacher-action-list">
                                        <form method="POST" action="{{ route('admin.course-cohorts.teachers.destroy', [$cohort->id, $teacher->id]) }}" onsubmit="return confirm(@js(__('lf.LF_course_cohort_teacher_remove_confirm', ['name' => $teacher->teacher_name])))">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="admin-link-button admin-text-action admin-table-action-link">{{ __('lf.LF_common_button_remove') }}</button>
                                        </form>
                                    </div>
                                @else
                                    <span class="lf-secondary-text">{{ __('lf.LF_course_cohort_tab_read_only') }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr class="course-cohort-teachers__empty-row"><td class="course-cohort-teachers__empty-cell" colspan="4"><div class="course-cohort-empty-state">{{ __('lf.LF_course_cohort_teacher_empty') }}</div></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
