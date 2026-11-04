@php
    $hasOperatingPeriod = $cohort->start_date && $cohort->end_date;
    $canAddSchedule = $cohort->is_mutable && $hasOperatingPeriod && $scheduleAcceptsNew;
@endphp
<section class="mk-section course-cohort-schedules" aria-labelledby="cohort-schedules-title">
            <div class="ui-summary-head">
                <div>
                    <h2 id="cohort-schedules-title">{{ __('lf.LF_course_cohort_schedule_list_title') }}</h2>
                    <p class="ui-caption">
                        {{ __('lf.LF_course_cohort_schedule_list_help') }}
                        @if ($hasOperatingPeriod)
                            {{ __('lf.LF_course_cohort_create_group_dates') }}:
                            <span class="ui-enroll-nowrap">{{ \Illuminate\Support\Carbon::parse($cohort->start_date)->format('d/m/Y') }} → {{ \Illuminate\Support\Carbon::parse($cohort->end_date)->format('d/m/Y') }}</span>.
                        @endif
                    </p>
                </div>
                <div class="mk-table-top__actions">
                    <span class="ui-caption ui-enroll-nowrap">{{ trans_choice('lf.LF_course_cohort_schedule_count', $schedules->total(), ['count' => $schedules->total()]) }}</span>
                    @if ($canAddSchedule)
                        <button type="button" class="ui-button ui-button--outline cursor-interaction course-cohort-schedules__create"
                                aria-haspopup="dialog" x-on:click="$dispatch('schedule-create-open')">
                            {{ __('lf.LF_course_cohort_schedule_add') }}
                        </button>
                    @endif
                </div>
            </div>

            @if ($cohort->is_mutable && ! $hasOperatingPeriod)
                <div class="ui-banner ui-cohort-tab-notice" role="status">
                    <span class="ui-banner__text">{{ __('lf.LF_course_cohort_schedule_operation_required') }}</span>
                    <a class="ui-button ui-button--sm ui-button--outline cursor-interaction" href="{{ route('admin.course-cohorts.edit', $cohort->id) }}">{{ __('lf.LF_course_cohort_schedule_operation_action') }}</a>
                </div>
            @elseif ($cohort->is_mutable && ! $scheduleAcceptsNew && $schedules->total() > 0)
                <div class="ui-banner ui-cohort-tab-notice" role="status">
                    <span class="ui-banner__text">{{ __('lf.LF_course_type_cohort_legacy_self_paced') }}</span>
                </div>
            @endif

            @if ($canAddSchedule)
                @include('course-cohort-schedules.partials.form', [
                    'mode' => 'create',
                    'action' => route('admin.course-cohorts.schedules.store', $cohort->id),
                    'schedule' => null,
                    'slots' => collect(),
                    'exclusions' => collect(),
                    'timezones' => $scheduleTimezones,
                    'autoOpen' => $scheduleFormMode === 'create',
                ])
            @endif
            @if ($scheduleFormMode === 'edit' && $cohort->is_mutable && $hasOperatingPeriod)
                @include('course-cohort-schedules.partials.form', [
                    'mode' => 'edit',
                    'action' => route('admin.course-cohorts.schedules.update', [$cohort->id, $scheduleFormSchedule->id]),
                    'schedule' => $scheduleFormSchedule,
                    'slots' => $scheduleFormSlots,
                    'exclusions' => $scheduleFormExclusions,
                    'timezones' => $scheduleTimezones,
                    'autoOpen' => true,
                ])
            @endif
            @if ($scheduleFormMode === 'view')
                @include('course-cohort-schedules.partials.detail', [
                    'schedule' => $scheduleFormSchedule,
                    'slots' => $scheduleFormSlots,
                    'exclusions' => $scheduleFormExclusions,
                    'plan' => $scheduleFormPlan,
                    'derivedStatus' => $scheduleFormDerivedStatus,
                    'canMutate' => $cohort->is_mutable,
                ])
            @endif

            <div class="mk-table-scroll" role="region" aria-labelledby="cohort-schedules-title" tabindex="0">
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
                        <th scope="col" class="ux-action-cell">{{ __('lf.LF_course_cohort_schedule_list_sessions') }}</th>
                        <th scope="col" class="ux-action-cell">{{ __('lf.LF_course_cohort_schedule_status') }}</th>
                        <th scope="col" class="ux-action-cell">{{ __('lf.table_actions') }}</th>
                    </tr></thead>
                    <tbody>
                    @forelse ($schedules as $schedule)
                        <tr>
                            <td class="mk-sequence" data-label="{{ __('lf.table_no') }}">{{ $schedules->firstItem() + $loop->index }}</td>
                            <td class="mk-text-cell" data-label="{{ __('lf.LF_course_cohort_schedule_list_name') }}">
                                <a class="ui-cohort-schedule-name cursor-interaction" href="{{ route('admin.course-cohorts.show', ['id' => $cohort->id, 'tab' => 'schedules', 'schedule_form' => 'view', 'schedule_id' => $schedule->id]) }}">{{ $schedule->name }}</a>
                                @if ($schedule->timezone !== config('app.timezone'))
                                    <span class="ui-caption ui-framework-description">{{ $schedule->timezone }}</span>
                                @endif
                            </td>
                            @php
                                $scheduleTone = match ($schedule->derived_status) { 'current' => 'active', 'upcoming' => 'draft', 'completed' => 'completed', default => 'archived' };
                                $scheduleDays = $schedule->slots->groupBy(fn ($slot) => (int) $slot->weekday)->sortKeys();
                                $scheduleVisibleDays = $scheduleDays->take(4);
                                $scheduleHiddenSlots = $scheduleDays->skip(4)->flatten(1)->count();
                            @endphp
                            <td class="mk-text-cell" data-label="{{ __('lf.LF_course_cohort_schedule_days_and_times') }}">
                                @foreach ($scheduleVisibleDays as $weekday => $daySlots)
                                    <span class="ui-cohort-schedule-line">
                                        <span class="ui-cohort-schedule-line__day" title="{{ __('lf.LF_course_cohort_schedule_weekday_'.$weekday) }}">{{ __('lf.LF_common_weekday_'.['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'][$weekday - 1]) }}</span>
                                        <span class="ui-cohort-schedule-line__times">
                                            @foreach ($daySlots->sortBy('start_time') as $slot)
                                                <span class="ui-enroll-nowrap">{{ substr($slot->start_time, 0, 5) }}–{{ substr($slot->end_time, 0, 5) }}@unless ($loop->last),@endunless</span>
                                            @endforeach
                                        </span>
                                    </span>
                                @endforeach
                                @if ($scheduleHiddenSlots > 0)
                                    <span class="ui-caption ui-framework-description">{{ __('lf.LF_course_cohort_schedule_more_slots', ['count' => $scheduleHiddenSlots]) }}</span>
                                @endif
                            </td>
                            <td class="mk-text-cell ui-enroll-nowrap" data-label="{{ __('lf.LF_course_cohort_schedule_period') }}">
                                {{ \Illuminate\Support\Carbon::parse($schedule->starts_on)->format('d/m/Y') }}
                                <span aria-hidden="true">→</span>
                                {{ \Illuminate\Support\Carbon::parse($schedule->ends_on)->format('d/m/Y') }}
                            </td>
                            <td class="ux-action-cell" data-label="{{ __('lf.LF_course_cohort_schedule_list_sessions') }}" title="{{ __('lf.LF_course_cohort_schedule_list_sessions_progress', ['created' => $schedule->created_session_count, 'planned' => $schedule->preview_count]) }}">
                                <span class="ui-sr-only">{{ __('lf.LF_course_cohort_schedule_list_sessions_progress', ['created' => $schedule->created_session_count, 'planned' => $schedule->preview_count]) }}</span>
                                <span aria-hidden="true">{{ $schedule->created_session_count }}/{{ $schedule->preview_count }}</span>
                            </td>
                            <td class="ux-action-cell" data-label="{{ __('lf.LF_course_cohort_schedule_status') }}">
                                <span class="ui-status ui-status--{{ $scheduleTone }}">{{ __('lf.LF_course_cohort_schedule_status_'.$schedule->derived_status) }}</span>
                            </td>
                            <td class="ux-action-cell" data-label="{{ __('lf.table_actions') }}">
                                <a class="ux-row-edit cursor-interaction"
                                   href="{{ route('admin.course-cohorts.show', ['id' => $cohort->id, 'tab' => 'schedules', 'schedule_form' => 'view', 'schedule_id' => $schedule->id]) }}"
                                   aria-label="{{ __('lf.LF_common_button_view') }}: {{ $schedule->name }}" data-tooltip="{{ __('lf.LF_common_button_view') }}">
                                    <x-backend-icon name="eye" class="ui-icon" />
                                </a>
                                @if ($cohort->is_mutable)
                                    <a class="ux-row-edit cursor-interaction"
                                       href="{{ route('admin.course-cohorts.show', ['id' => $cohort->id, 'tab' => 'schedules', 'schedule_form' => 'edit', 'schedule_id' => $schedule->id]) }}"
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
                                <p class="ui-caption">{{ $cohort->is_mutable && ! $scheduleAcceptsNew ? __('lf.LF_course_type_cohort_legacy_self_paced') : __('lf.LF_course_cohort_schedule_empty_help') }}</p>
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
