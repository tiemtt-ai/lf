@php($hasOperatingPeriod = ! empty($cohort->start_date) && ! empty($cohort->end_date))
<section class="mk-section course-cohort-teachers" aria-labelledby="cohort-teachers-title"
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
            <div class="ui-summary-head">
                <div>
                    <h2 id="cohort-teachers-title">{{ __('lf.LF_course_cohort_teacher_title') }}</h2>
                    <p class="ui-caption">{{ __('lf.LF_course_cohort_teacher_help') }}</p>
                </div>
                <div class="mk-table-top__actions">
                    <span class="ui-caption">{{ trans_choice('lf.LF_course_cohort_teacher_count', $teachers->count(), ['count' => $teachers->count()]) }}</span>
                    @if ($cohort->is_mutable)
                        <button type="button" class="ui-button ui-button--outline cursor-interaction course-cohort-teacher-assignment__open"
                                x-show="!open" x-cloak x-on:click="open = true" aria-controls="cohort-teacher-assignment-form">
                            {{ __('lf.LF_course_cohort_teacher_assign') }}
                        </button>
                    @endif
                </div>
            </div>

            @if ($cohort->is_mutable)
                <form id="cohort-teacher-assignment-form" method="POST" action="{{ route('admin.course-cohorts.teachers.store', $cohort->id) }}"
                      class="course-cohort-teacher-assignment__form ui-cohort-inline-form" x-show="open" x-cloak x-transition.opacity.duration.150ms>
                    @csrf
                    <div class="ui-cohort-form">
                        <div class="ui-field">
                            <label class="ui-field__label" for="teacher_id"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_course_cohort_teacher_teacher') }}</label>
                            <select id="teacher_id" name="teacher_id" data-ui-kit-select data-ui-empty-label="{{ __('lf.LF_course_enrollment_index_no_options') }}" required>
                                <option value="">{{ __('lf.LF_course_cohort_teacher_select') }}</option>
                                @foreach ($availableTeachers as $teacher)
                                    <option value="{{ $teacher->id }}" @selected((string) old('teacher_id') === (string) $teacher->id)>{{ $teacher->name }} · {{ $teacher->email }}</option>
                                @endforeach
                            </select>
                            @error('teacher_id')<p class="ui-field__error" role="alert">{{ $message }}</p>@enderror
                        </div>
                        <div class="ui-field">
                            <label class="ui-field__label" for="teacher_role"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_course_cohort_teacher_role') }}</label>
                            <select id="teacher_role" name="role" x-model="role" x-on:change="syncPrimaryPeriod()" required>
                                @foreach (['primary_teacher', 'teacher', 'assistant'] as $role)
                                    <option value="{{ $role }}" @disabled($role === 'primary_teacher' && ! $hasOperatingPeriod)>{{ __('lf.LF_course_cohort_teacher_role_'.$role) }}</option>
                                @endforeach
                            </select>
                            @error('role')<p class="ui-field__error" role="alert">{{ $message }}</p>@enderror
                        </div>
                        <div class="ui-field">
                            <label class="ui-field__label" for="assigned_from">{{ __('lf.LF_course_cohort_teacher_from') }}</label>
                            <input id="assigned_from" type="date" name="assigned_from" x-model="assignedFrom"
                                   x-bind:readonly="role === 'primary_teacher'"
                                   @if($cohort->start_date) min="{{ $cohort->start_date }}" @endif @if($cohort->end_date) max="{{ $cohort->end_date }}" @endif>
                            @error('assigned_from')<p class="ui-field__error" role="alert">{{ $message }}</p>@enderror
                        </div>
                        <div class="ui-field">
                            <label class="ui-field__label" for="assigned_to">{{ __('lf.LF_course_cohort_teacher_to') }}</label>
                            <input id="assigned_to" type="date" name="assigned_to" x-model="assignedTo"
                                   x-bind:readonly="role === 'primary_teacher'"
                                   @if($cohort->start_date) min="{{ $cohort->start_date }}" @endif @if($cohort->end_date) max="{{ $cohort->end_date }}" @endif>
                            @error('assigned_to')<p class="ui-field__error" role="alert">{{ $message }}</p>@enderror
                        </div>
                        <p class="ui-caption ui-field--full">
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
                    </div>
                    <footer class="ui-cohort-form__actions">
                        <button type="button" class="ui-button cursor-interaction" x-on:click="open = false">{{ __('lf.LF_common_button_cancel') }}</button>
                        <button class="ui-button ui-button--primary cursor-interaction" type="submit">{{ __('lf.LF_course_cohort_teacher_add') }}</button>
                    </footer>
                </form>
            @endif

            <div class="mk-table-scroll course-cohort-teachers__table" role="region" aria-labelledby="cohort-teachers-title" tabindex="0">
                <table>
                    <colgroup><col><col class="ui-cohort-col-role"><col class="ui-cohort-col-period"><col class="mk-col-action"></colgroup>
                    <thead><tr>
                        <th scope="col" class="mk-text-cell">{{ __('lf.LF_course_cohort_teacher_teacher') }}</th>
                        <th scope="col" class="mk-text-cell">{{ __('lf.LF_course_cohort_teacher_role') }}</th>
                        <th scope="col" class="mk-text-cell">{{ __('lf.LF_course_cohort_teacher_period') }}</th>
                        <th scope="col" class="ux-action-cell">{{ __('lf.table_actions') }}</th>
                    </tr></thead>
                    <tbody>
                    @forelse ($teachers as $teacher)
                        @php($removeErrorKey = 'remove_teacher_'.$teacher->id)
                        @php($removeSessionErrorKey = 'remove_teacher_sessions_'.$teacher->id)
                        <tr id="cohort-teacher-row-{{ $teacher->id }}">
                            <td class="mk-text-cell" data-label="{{ __('lf.LF_course_cohort_teacher_teacher') }}">
                                <span class="mk-name-text">{{ $teacher->teacher_name }}</span>
                                <span class="ui-caption ui-framework-description">{{ $teacher->teacher_email }}</span>
                            </td>
                            <td class="mk-text-cell" data-label="{{ __('lf.LF_course_cohort_teacher_role') }}">
                                <span @class(['ui-status', 'ui-status--active' => $teacher->role === 'primary_teacher', 'ui-status--draft' => $teacher->role !== 'primary_teacher'])>{{ __('lf.LF_course_cohort_teacher_role_'.$teacher->role) }}</span>
                            </td>
                            <td class="mk-text-cell ui-enroll-nowrap" data-label="{{ __('lf.LF_course_cohort_teacher_period') }}">
                                {{ $teacher->assigned_from ? \Illuminate\Support\Carbon::parse($teacher->assigned_from)->format('d/m/Y') : '—' }}
                                <span aria-hidden="true">→</span>
                                {{ $teacher->assigned_to ? \Illuminate\Support\Carbon::parse($teacher->assigned_to)->format('d/m/Y') : '—' }}
                            </td>
                            <td class="ux-action-cell" data-label="{{ __('lf.table_actions') }}">
                                @if ($cohort->is_mutable)
                                    <form method="POST" class="ui-cohort-row-form"
                                          action="{{ route('admin.course-cohorts.teachers.destroy', [$cohort->id, $teacher->id]) }}"
                                          data-lf-confirm="{{ __('lf.LF_course_cohort_teacher_remove_confirm', ['name' => $teacher->teacher_name]) }}"
                                          data-lf-confirm-tone="danger"
                                          data-lf-confirm-label="{{ __('lf.LF_common_button_remove') }}">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="ux-row-delete cursor-interaction"
                                                aria-label="{{ __('lf.LF_common_button_remove') }}: {{ $teacher->teacher_name }}"
                                                data-tooltip="{{ __('lf.LF_common_button_remove') }}">
                                            <x-backend-icon name="trash" class="ui-icon" />
                                        </button>
                                    </form>
                                @else
                                    <span class="ui-caption">{{ __('lf.LF_course_cohort_tab_read_only') }}</span>
                                @endif
                            </td>
                        </tr>
                        @if ($errors->has($removeErrorKey))
                            @php($blockedSessions = collect($errors->get($removeSessionErrorKey)))
                            <tr class="course-cohort-teacher-removal-error-row"
                                data-for="cohort-teacher-row-{{ $teacher->id }}"
                                x-data="{ visible: true, expanded: false }" x-show="visible">
                                <td class="mk-text-cell" colspan="4">
                                    <div class="course-cohort-teacher-removal-error" role="alert" aria-live="assertive">
                                        <span class="course-cohort-teacher-removal-error__icon" aria-hidden="true">!</span>
                                        <div class="course-cohort-teacher-removal-error__content">
                                            <div class="course-cohort-teacher-removal-error__heading">
                                                <strong>{{ __('lf.LF_course_cohort_teacher_remove_blocked_title', ['name' => $teacher->teacher_name]) }}</strong>
                                                <button type="button" class="course-cohort-teacher-removal-error__close"
                                                        x-on:click="visible = false"
                                                        aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
                                            </div>
                                            <p>{{ $errors->first($removeErrorKey) }}</p>
                                            <strong class="course-cohort-teacher-removal-error__summary">
                                                {{ trans_choice('lf.LF_course_cohort_teacher_remove_blocked_count', $blockedSessions->count(), ['count' => $blockedSessions->count()]) }}
                                            </strong>
                                            <ul class="course-cohort-teacher-removal-error__sessions"
                                                x-bind:class="{ 'is-expanded': expanded }">
                                                @foreach ($blockedSessions as $session)
                                                    <li @if ($loop->index >= 3) x-show="expanded" x-cloak @endif>{{ $session }}</li>
                                                @endforeach
                                            </ul>
                                            @if ($blockedSessions->count() > 3)
                                                <button type="button" class="course-cohort-teacher-removal-error__more"
                                                        x-on:click="expanded = !expanded"
                                                        x-bind:aria-expanded="expanded">
                                                    <span x-show="!expanded">▸ {{ __('lf.LF_course_cohort_teacher_remove_show_more', ['count' => $blockedSessions->count() - 3]) }}</span>
                                                    <span x-show="expanded" x-cloak>▾ {{ __('lf.LF_course_cohort_teacher_remove_collapse') }}</span>
                                                </button>
                                            @endif
                                            <div class="course-cohort-teacher-removal-error__actions">
                                                <a class="ui-button ui-button--primary ui-button--sm cursor-interaction" href="{{ route('admin.course-cohorts.show', $cohort->id) }}?tab=sessions">
                                                    {{ __('lf.LF_course_cohort_teacher_remove_reassign', ['count' => $blockedSessions->count()]) }}
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr class="mk-empty-row"><td class="mk-empty" colspan="4"><div role="status"><strong>{{ __('lf.LF_course_cohort_teacher_empty') }}</strong></div></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
</section>
