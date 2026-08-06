@php($hasOperatingPeriod = ! empty($cohort->start_date) && ! empty($cohort->end_date))
<div class="admin-card admin-form-card admin-form-surface course-cohort-teachers"
     x-data="{
         open: @js($errors->hasAny(['teacher_id', 'role', 'assigned_from', 'assigned_to'])),
         role: @js(old('role', $hasOperatingPeriod ? 'primary_teacher' : 'teacher')),
         assignedFrom: @js(old('assigned_from', $hasOperatingPeriod ? $cohort->start_date : '')),
         assignedTo: @js(old('assigned_to', $hasOperatingPeriod ? $cohort->end_date : '')),
         classStart: @js($cohort->start_date ?? ''), classEnd: @js($cohort->end_date ?? ''),
         syncPrimaryPeriod() {
             if (this.role === 'primary_teacher') {
                 this.assignedFrom = this.classStart
                 this.assignedTo = this.classEnd
             }
         }
     }" x-init="syncPrimaryPeriod()">
    <div class="admin-form-standard">
        <section class="admin-form-standard-section">
            <header class="admin-form-section-header course-cohort-teachers__header">
                <div>
                    <h2 class="admin-form-section-title">{{ __('lf.LF_course_cohort_teacher_title') }}</h2>
                    <p class="admin-form-section-help">{{ __('lf.LF_course_cohort_teacher_help') }}</p>
                </div>
                <div class="course-cohort-teachers__toolbar">
                    <span class="course-cohort-teachers__count">
                        {{ trans_choice('lf.LF_course_cohort_teacher_count', $teachers->count(), ['count' => $teachers->count()]) }}
                    </span>
                    @if (in_array($cohort->status, ['draft', 'active'], true))
                        <button type="button" class="btn btn-primary course-cohort-teacher-assignment__open"
                                x-show="!open" x-cloak x-on:click="open = true" aria-controls="cohort-teacher-assignment-form">
                            {{ __('lf.LF_course_cohort_teacher_assign') }}
                        </button>
                    @endif
                </div>
            </header>

            @if (in_array($cohort->status, ['draft', 'active'], true))
                <div class="course-cohort-teacher-assignment">
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
                            <select id="teacher_role" name="role" class="lf-form-control" x-model="role" x-on:change="syncPrimaryPeriod()" required>
                                @foreach (['primary_teacher', 'teacher', 'assistant'] as $role)
                                    <option value="{{ $role }}" @disabled($role === 'primary_teacher' && ! $hasOperatingPeriod)>{{ __('lf.LF_course_cohort_teacher_role_'.$role) }}</option>
                                @endforeach
                            </select>
                            @error('role')<p class="lf-form-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                        <div class="lf-form-group">
                            <x-form-label for="assigned_from" :value="__('lf.LF_course_cohort_teacher_from')" />
                            <input id="assigned_from" type="date" name="assigned_from" class="lf-form-control" x-model="assignedFrom"
                                   x-bind:readonly="role === 'primary_teacher'"
                                   @if($cohort->start_date) min="{{ $cohort->start_date }}" @endif @if($cohort->end_date) max="{{ $cohort->end_date }}" @endif>
                        </div>
                        <div class="lf-form-group">
                            <x-form-label for="assigned_to" :value="__('lf.LF_course_cohort_teacher_to')" />
                            <input id="assigned_to" type="date" name="assigned_to" class="lf-form-control" x-model="assignedTo"
                                   x-bind:readonly="role === 'primary_teacher'"
                                   @if($cohort->start_date) min="{{ $cohort->start_date }}" @endif @if($cohort->end_date) max="{{ $cohort->end_date }}" @endif>
                        </div>
                        @if($errors->has('assigned_from') || $errors->has('assigned_to'))
                            <div class="course-cohort-teacher-assignment__date-errors" role="alert">
                                @error('assigned_from')<p>{{ $message }}</p>@enderror
                                @error('assigned_to')<p>{{ $message }}</p>@enderror
                            </div>
                        @endif
                        <p class="course-cohort-teacher-assignment__date-help">
                            <span x-show="role !== 'primary_teacher'">
                                {{ __('lf.LF_course_cohort_teacher_operating_period', [
                                    'from' => $cohort->start_date ? \Illuminate\Support\Carbon::parse($cohort->start_date)->format('d/m/Y') : '—',
                                    'to' => $cohort->end_date ? \Illuminate\Support\Carbon::parse($cohort->end_date)->format('d/m/Y') : '—',
                                ]) }}
                            </span>
                            <span x-show="role === 'primary_teacher'" x-cloak>
                                {{ __('lf.LF_course_cohort_teacher_primary_period_summary', [
                                    'from' => $cohort->start_date ? \Illuminate\Support\Carbon::parse($cohort->start_date)->format('d/m/Y') : '—',
                                    'to' => $cohort->end_date ? \Illuminate\Support\Carbon::parse($cohort->end_date)->format('d/m/Y') : '—',
                                ]) }}
                            </span>
                        </p>
                        <footer class="admin-form-footer course-cohort-teacher-assignment__footer">
                            <strong class="course-cohort-session-form__footer-context">{{ __('lf.LF_course_cohort_teacher_assign') }}</strong>
                            <div class="admin-form-footer-primary">
                                <button type="button" class="btn btn-secondary" x-on:click="open = false">{{ __('lf.LF_common_button_cancel') }}</button>
                                <button class="btn btn-primary" type="submit">{{ __('lf.LF_course_cohort_teacher_add') }}</button>
                            </div>
                        </footer>
                    </form>
                </div>
            @endif

            <div class="admin-table-wrap course-cohort-teachers__table">
                <table class="table course-cohort-roster-table admin-table-has-actions">
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
                                    <x-admin-action-menu :label="__('lf.table_actions').': '.$teacher->teacher_name">
                                        <form method="POST"
                                              action="{{ route('admin.course-cohorts.teachers.destroy', [$cohort->id, $teacher->id]) }}"
                                              data-lf-confirm="{{ __('lf.LF_course_cohort_teacher_remove_confirm', ['name' => $teacher->teacher_name]) }}"
                                              data-lf-confirm-tone="danger"
                                              data-lf-confirm-label="{{ __('lf.LF_common_button_remove') }}">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="admin-link-button admin-text-action admin-table-action-link">
                                                <x-admin-action-icon name="remove" />
                                                {{ __('lf.LF_common_button_remove') }}
                                            </button>
                                        </form>
                                    </x-admin-action-menu>
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
