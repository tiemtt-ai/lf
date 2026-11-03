@php($inlineScheduleMode = in_array($scheduleFormMode, ['view', 'edit'], true) ? $scheduleFormMode : 'create')
<section class="mk-section course-cohort-schedules" aria-labelledby="cohort-schedules-title"
     x-data="{
         formOpen: @js($scheduleFormMode !== null),
         openForm() {
             this.formOpen = true
             this.$nextTick(() => this.$refs.editor?.scrollIntoView({ behavior: 'smooth', block: 'start' }))
         }
     }"
     x-init="if (formOpen) $nextTick(() => $refs.editor?.scrollIntoView({ behavior: 'smooth', block: 'start' }))"
     x-on:schedule-editor-close.window="formOpen = false">
            <div class="ui-summary-head">
                <div>
                    <h2 id="cohort-schedules-title">{{ __('lf.LF_course_cohort_schedule_list_title') }}</h2>
                    <p class="ui-caption">{{ __('lf.LF_course_cohort_schedule_list_help') }}</p>
                    @if($cohort->start_date && $cohort->end_date)
                        <p class="ui-caption course-cohort-schedules__operation-window">
                            {{ __('lf.LF_course_cohort_create_group_dates') }}:
                            {{ \Illuminate\Support\Carbon::parse($cohort->start_date)->format('d/m/Y') }} → {{ \Illuminate\Support\Carbon::parse($cohort->end_date)->format('d/m/Y') }}
                        </p>
                    @endif
                </div>
                <div class="mk-table-top__actions">
                    <span class="ui-caption">{{ trans_choice('lf.LF_course_cohort_schedule_count', $schedules->total(), ['count' => $schedules->total()]) }}</span>
                    @if ($cohort->is_mutable && $cohort->start_date && $cohort->end_date)
                        <button type="button" class="ui-button ui-button--outline cursor-interaction course-cohort-schedules__create"
                                x-show="!formOpen" x-cloak
                                x-on:click="openForm()" x-bind:aria-expanded="formOpen.toString()"
                                aria-controls="cohort-schedule-editor">
                            {{ __('lf.LF_course_cohort_schedule_add') }}
                        </button>
                    @endif
                </div>
            </div>

            @if($cohort->is_mutable && (! $cohort->start_date || ! $cohort->end_date))
                <div class="admin-alert admin-alert-warning course-cohort-schedules__operation-required" role="status">
                    <span>{{ __('lf.LF_course_cohort_schedule_operation_required') }}</span>
                    <a class="admin-text-action" href="{{ route('admin.course-cohorts.edit', $cohort->id) }}">{{ __('lf.LF_course_cohort_schedule_operation_action') }}</a>
                </div>
            @endif

            @if($cohort->is_mutable && $cohort->start_date && $cohort->end_date)
                <div id="cohort-schedule-editor" class="course-cohort-schedules__inline-editor" tabindex="-1"
                     x-ref="editor" x-show="formOpen" x-cloak x-transition.opacity.duration.150ms>
                    @if($inlineScheduleMode === 'view')
                        @include('course-cohort-schedules.partials.detail', [
                            'schedule' => $scheduleFormSchedule,
                            'slots' => $scheduleFormSlots,
                            'exclusions' => $scheduleFormExclusions,
                            'preview' => $scheduleFormPreview,
                            'derivedStatus' => $scheduleFormDerivedStatus,
                            'canMutate' => $cohort->is_mutable,
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

            <div class="mk-table-scroll course-cohort-schedules__table-wrap" role="region" aria-labelledby="cohort-schedules-title" tabindex="0">
                <table>
                    <colgroup>
                        <col class="mk-col-sequence">
                        <col>
                        <col class="ui-cohort-col-slots">
                        <col class="ui-cohort-col-period">
                        <col class="ui-cohort-col-count">
                        <col class="mk-col-status">
                        <col class="mk-col-action">
                    </colgroup>
                    <thead><tr>
                        <th scope="col" class="mk-sequence">{{ __('lf.table_no') }}</th>
                        <th scope="col" class="mk-text-cell">{{ __('lf.LF_course_cohort_schedule_list_name') }}</th>
                        <th scope="col" class="mk-text-cell">{{ __('lf.LF_course_cohort_schedule_days_and_times') }}</th>
                        <th scope="col" class="mk-text-cell">{{ __('lf.LF_course_cohort_schedule_period') }}</th>
                        <th scope="col" class="ux-action-cell">{{ __('lf.LF_course_cohort_schedule_expected_count') }}</th>
                        <th scope="col" class="ux-action-cell">{{ __('lf.LF_course_cohort_schedule_status') }}</th>
                        <th scope="col" class="ux-action-cell">{{ __('lf.table_actions') }}</th>
                    </tr></thead>
                    <tbody>
                    @forelse ($schedules as $schedule)
                        @php($scheduleTone = match ($schedule->derived_status) { 'current' => 'active', 'upcoming' => 'draft', 'completed' => 'completed', default => 'archived' })
                        <tr>
                            <td class="mk-sequence" data-label="{{ __('lf.table_no') }}">{{ $schedules->firstItem() + $loop->index }}</td>
                            <td class="mk-text-cell" data-label="{{ __('lf.LF_course_cohort_schedule_list_name') }}">
                                <span class="mk-name-text">{{ $schedule->name }}</span>
                                <span class="ui-caption ui-framework-description">{{ $schedule->timezone }}</span>
                            </td>
                            <td class="mk-text-cell" data-label="{{ __('lf.LF_course_cohort_schedule_days_and_times') }}">
                                @foreach ($schedule->slots->take(3) as $slot)
                                    <span class="ui-cohort-line">{{ __('lf.LF_course_cohort_schedule_weekday_'.$slot->weekday) }} · {{ substr($slot->start_time, 0, 5) }}–{{ substr($slot->end_time, 0, 5) }}</span>
                                @endforeach
                                @if ($schedule->slots->count() > 3)
                                    <span class="ui-caption ui-framework-description">{{ __('lf.LF_course_cohort_schedule_more_slots', ['count' => $schedule->slots->count() - 3]) }}</span>
                                @endif
                            </td>
                            <td class="mk-text-cell ui-enroll-nowrap" data-label="{{ __('lf.LF_course_cohort_schedule_period') }}">
                                {{ \Illuminate\Support\Carbon::parse($schedule->starts_on)->format('d/m/Y') }}
                                <span aria-hidden="true">→</span>
                                {{ \Illuminate\Support\Carbon::parse($schedule->ends_on)->format('d/m/Y') }}
                            </td>
                            <td class="ux-action-cell" data-label="{{ __('lf.LF_course_cohort_schedule_expected_count') }}">{{ $schedule->preview_count }}</td>
                            <td class="ux-action-cell" data-label="{{ __('lf.LF_course_cohort_schedule_status') }}">
                                <span class="ui-status ui-status--{{ $scheduleTone }}">{{ __('lf.LF_course_cohort_schedule_status_'.$schedule->derived_status) }}</span>
                            </td>
                            <td class="ux-action-cell" data-label="{{ __('lf.table_actions') }}">
                                <a class="ux-row-edit cursor-interaction"
                                   href="{{ route('admin.course-cohorts.show', ['id' => $cohort->id, 'tab' => 'schedules', 'schedule_form' => 'view', 'schedule_id' => $schedule->id]) }}#cohort-schedule-editor"
                                   aria-label="{{ __('lf.LF_common_button_view') }}: {{ $schedule->name }}" data-tooltip="{{ __('lf.LF_common_button_view') }}">
                                    <x-backend-icon name="eye" class="ui-icon" />
                                </a>
                                @if ($cohort->is_mutable)
                                    <a class="ux-row-edit cursor-interaction"
                                       href="{{ route('admin.course-cohorts.show', ['id' => $cohort->id, 'tab' => 'schedules', 'schedule_form' => 'edit', 'schedule_id' => $schedule->id]) }}#cohort-schedule-editor"
                                       aria-label="{{ __('lf.LF_common_button_edit') }}: {{ $schedule->name }}" data-tooltip="{{ __('lf.LF_common_button_edit') }}">
                                        <x-backend-icon name="square-pen" class="ui-icon" />
                                    </a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr class="mk-empty-row"><td class="mk-empty" colspan="7">
                            <div role="status">
                                <strong>{{ __('lf.LF_course_cohort_schedule_empty') }}</strong>
                                <p class="ui-caption">{{ __('lf.LF_course_cohort_schedule_empty_help') }}</p>
                                @if ($cohort->is_mutable && $cohort->start_date && $cohort->end_date)
                                    <button type="button" class="ui-button ui-button--outline cursor-interaction" x-show="!formOpen" x-cloak x-on:click="openForm()">{{ __('lf.LF_course_cohort_schedule_add') }}</button>
                                @endif
                            </div>
                        </td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if ($schedules->hasPages())
                <x-ui-list-foot :paginator="$schedules"
                    :label="__('lf.LF_course_cohort_schedule_list_title')"
                    :page-size-label="__('lf.LF_course_template_index_page_size')"
                    :page-size-option="__('lf.LF_course_template_index_page_size_option', ['count' => $schedules->perPage()])" />
            @endif
</section>
