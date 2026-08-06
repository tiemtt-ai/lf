@php($inlineScheduleMode = in_array($scheduleFormMode, ['view', 'edit'], true) ? $scheduleFormMode : 'create')
<div class="admin-card admin-form-card admin-form-surface course-cohort-schedules"
     x-data="{
         formOpen: @js($scheduleFormMode !== null),
         openForm() {
             this.formOpen = true
             this.$nextTick(() => this.$refs.editor?.scrollIntoView({ behavior: 'smooth', block: 'start' }))
         }
     }"
     x-init="if (formOpen) $nextTick(() => $refs.editor?.scrollIntoView({ behavior: 'smooth', block: 'start' }))"
     x-on:schedule-editor-close.window="formOpen = false">
    <div class="admin-form-standard">
        <section class="admin-form-standard-section">
            <header class="admin-form-section-header course-cohort-schedules__header">
                <div>
                    <h2 class="admin-form-section-title">{{ __('lf.LF_course_cohort_schedule_list_title') }}</h2>
                    <p class="admin-form-section-help">{{ __('lf.LF_course_cohort_schedule_list_help') }}</p>
                    @if($cohort->start_date && $cohort->end_date)
                        <span class="course-cohort-schedules__operation-window">
                            {{ __('lf.LF_course_cohort_create_group_dates') }}:
                            <strong>{{ \Illuminate\Support\Carbon::parse($cohort->start_date)->format('d/m/Y') }} → {{ \Illuminate\Support\Carbon::parse($cohort->end_date)->format('d/m/Y') }}</strong>
                        </span>
                    @endif
                </div>
                <div class="course-cohort-schedules__toolbar">
                    <span class="course-cohort-schedules__count">{{ trans_choice('lf.LF_course_cohort_schedule_count', $schedules->total(), ['count' => $schedules->total()]) }}</span>
                    @if (in_array($cohort->status, ['draft', 'active'], true) && $cohort->start_date && $cohort->end_date)
                        <button type="button" class="btn btn-primary course-cohort-schedules__create"
                                x-show="!formOpen" x-cloak
                                x-on:click="openForm()" x-bind:aria-expanded="formOpen.toString()"
                                aria-controls="cohort-schedule-editor">
                            {{ __('lf.LF_course_cohort_schedule_add') }}
                        </button>
                    @endif
                </div>
            </header>

            @if(in_array($cohort->status, ['draft', 'active'], true) && (! $cohort->start_date || ! $cohort->end_date))
                <div class="admin-alert admin-alert-warning course-cohort-schedules__operation-required" role="status">
                    <span>{{ __('lf.LF_course_cohort_schedule_operation_required') }}</span>
                    <a class="admin-text-action" href="{{ route('admin.course-cohorts.edit', $cohort->id) }}">{{ __('lf.LF_course_cohort_schedule_operation_action') }}</a>
                </div>
            @endif

            @if(in_array($cohort->status, ['draft', 'active'], true) && $cohort->start_date && $cohort->end_date)
                <div id="cohort-schedule-editor" class="course-cohort-schedules__inline-editor" tabindex="-1"
                     x-ref="editor" x-show="formOpen" x-cloak x-transition.opacity.duration.150ms>
                    @if($inlineScheduleMode === 'view')
                        @include('course-cohort-schedules.partials.detail', [
                            'schedule' => $scheduleFormSchedule,
                            'slots' => $scheduleFormSlots,
                            'exclusions' => $scheduleFormExclusions,
                            'preview' => $scheduleFormPreview,
                            'derivedStatus' => $scheduleFormDerivedStatus,
                            'canMutate' => in_array($cohort->status, ['draft', 'active'], true),
                        ])
                    @else
                    @include('course-cohort-schedules.partials.errors')
                    <form method="POST"
                          action="{{ $inlineScheduleMode === 'edit'
                              ? route('admin.course-cohorts.schedules.update', [$cohort->id, $scheduleFormSchedule->id])
                              : route('admin.course-cohorts.schedules.store', $cohort->id) }}"
                          class="admin-form-standard course-cohort-schedules__inline-form">
                        @csrf
                        @if($inlineScheduleMode === 'edit') @method('PUT') @endif
                        @include('course-cohort-schedules.partials.form', [
                            'editing' => $inlineScheduleMode === 'edit',
                            'inline' => true,
                            'schedule' => $scheduleFormSchedule,
                            'slots' => $scheduleFormSlots,
                            'exclusions' => $scheduleFormExclusions,
                            'timezones' => $scheduleTimezones,
                        ])
                    </form>
                    @endif
                </div>
            @endif

            <div class="admin-table-wrap course-cohort-schedules__table-wrap">
                <table class="table course-cohort-schedules__table admin-table-has-actions">
                    <colgroup>
                        <col class="course-cohort-schedules__col-sequence">
                        <col class="course-cohort-schedules__col-name">
                        <col class="course-cohort-schedules__col-slots">
                        <col class="course-cohort-schedules__col-period">
                        <col class="course-cohort-schedules__col-count">
                        <col class="course-cohort-schedules__col-status">
                        <col class="course-cohort-schedules__col-actions">
                    </colgroup>
                    <thead><tr>
                        <th class="admin-table-sequence">{{ __('lf.table_no') }}</th>
                        <th>{{ __('lf.LF_course_cohort_schedule_list_name') }}</th>
                        <th>{{ __('lf.LF_course_cohort_schedule_days_and_times') }}</th>
                        <th>{{ __('lf.LF_course_cohort_schedule_period') }}</th>
                        <th>{{ __('lf.LF_course_cohort_schedule_expected_count') }}</th>
                        <th>{{ __('lf.LF_course_cohort_schedule_status') }}</th>
                        <th class="course-cohort-schedules__actions">{{ __('lf.table_actions') }}</th>
                    </tr></thead>
                    <tbody>
                    @forelse ($schedules as $schedule)
                        <tr>
                            <td class="admin-table-sequence" data-label="{{ __('lf.table_no') }}">{{ $schedules->firstItem() + $loop->index }}</td>
                            <td data-label="{{ __('lf.LF_course_cohort_schedule_list_name') }}">
                                <strong class="course-cohort-schedules__primary">{{ $schedule->name }}</strong>
                                <span class="course-cohort-schedules__meta">{{ $schedule->timezone }}</span>
                            </td>
                            <td data-label="{{ __('lf.LF_course_cohort_schedule_days_and_times') }}">
                                <div class="course-cohort-schedules__slot-list">
                                    @foreach ($schedule->slots->take(3) as $slot)
                                        <span>{{ __('lf.LF_course_cohort_schedule_weekday_'.$slot->weekday) }} · {{ substr($slot->start_time, 0, 5) }}–{{ substr($slot->end_time, 0, 5) }}</span>
                                    @endforeach
                                    @if ($schedule->slots->count() > 3)
                                        <span class="course-cohort-schedules__more-slots">
                                            {{ __('lf.LF_course_cohort_schedule_more_slots', ['count' => $schedule->slots->count() - 3]) }}
                                        </span>
                                    @endif
                                </div>
                            </td>
                            <td data-label="{{ __('lf.LF_course_cohort_schedule_period') }}">
                                <span class="course-cohort-schedules__period">
                                    <span>{{ \Illuminate\Support\Carbon::parse($schedule->starts_on)->format('d/m/Y') }}</span>
                                    <span class="course-cohort-schedules__period-arrow" aria-hidden="true">→</span>
                                    <span>{{ \Illuminate\Support\Carbon::parse($schedule->ends_on)->format('d/m/Y') }}</span>
                                </span>
                            </td>
                            <td class="course-cohort-schedules__expected-count" data-label="{{ __('lf.LF_course_cohort_schedule_expected_count') }}">
                                <strong>{{ $schedule->preview_count }}</strong>
                            </td>
                            <td class="course-cohort-schedules__status" data-label="{{ __('lf.LF_course_cohort_schedule_status') }}">
                                <span @class(['badge', 'course-cohort-schedules__status-badge', 'badge-success' => $schedule->derived_status === 'current', 'is-upcoming' => $schedule->derived_status === 'upcoming', 'is-completed' => $schedule->derived_status === 'completed'])>{{ __('lf.LF_course_cohort_schedule_status_'.$schedule->derived_status) }}</span>
                            </td>
                            <td class="course-cohort-schedules__actions" data-label="{{ __('lf.table_actions') }}">
                                <x-admin-action-menu :label="__('lf.table_actions').': '.$schedule->name">
                                        <a role="menuitem" class="admin-text-action admin-table-action-link"
                                           href="{{ route('admin.course-cohorts.show', ['id' => $cohort->id, 'tab' => 'schedules', 'schedule_form' => 'view', 'schedule_id' => $schedule->id]) }}#cohort-schedule-editor">
                                            <svg class="course-cohort-session-action-menu__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z" /><circle cx="12" cy="12" r="2.5" /></svg>
                                            {{ __('lf.LF_common_button_view') }}
                                        </a>
                                        @if (in_array($cohort->status, ['draft', 'active'], true))
                                            <a role="menuitem" class="admin-text-action admin-table-action-link"
                                               href="{{ route('admin.course-cohorts.show', ['id' => $cohort->id, 'tab' => 'schedules', 'schedule_form' => 'edit', 'schedule_id' => $schedule->id]) }}#cohort-schedule-editor">
                                                <svg class="course-cohort-session-action-menu__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m4 16.5-.8 4.3 4.3-.8L19 8.5 15.5 5 4 16.5Z" /><path d="m13.8 6.7 3.5 3.5" /></svg>
                                                {{ __('lf.LF_common_button_edit') }}
                                            </a>
                                        @endif
                                </x-admin-action-menu>
                            </td>
                        </tr>
                    @empty
                        <tr class="course-cohort-schedules__empty-row"><td colspan="7" class="course-cohort-schedules__empty-cell">
                            <div class="course-cohort-empty-state" role="status">
                                <strong>{{ __('lf.LF_course_cohort_schedule_empty') }}</strong>
                                <span>{{ __('lf.LF_course_cohort_schedule_empty_help') }}</span>
                                @if (in_array($cohort->status, ['draft', 'active'], true) && $cohort->start_date && $cohort->end_date)
                                    <button type="button" class="btn btn-primary" x-show="!formOpen" x-cloak x-on:click="openForm()">{{ __('lf.LF_course_cohort_schedule_add') }}</button>
                                @endif
                            </div>
                        </td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if ($schedules->hasPages())
                <div class="admin-pagination">{{ $schedules->links() }}</div>
            @endif

        </section>
    </div>
</div>
