{{-- Schedule detail popup: read-only, available whatever the class status. A schedule stays a plan: nothing here creates a Session. --}}
@php
    $detailTone = match ($derivedStatus) { 'current' => 'active', 'upcoming' => 'draft', default => 'archived' };
    $detailDate = fn (string $date, string $format = 'd/m/Y'): string => \Illuminate\Support\Carbon::parse($date)->format($format);
    $detailDuration = function (int $minutes): string {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return match (true) {
            $hours > 0 && $rest > 0 => __('lf.LF_course_cohort_schedule_detail_duration_hours_minutes', ['hours' => $hours, 'minutes' => $rest]),
            $hours > 0 => __('lf.LF_course_cohort_schedule_detail_duration_hours', ['hours' => $hours]),
            default => __('lf.LF_course_cohort_schedule_detail_duration_minutes', ['minutes' => $rest]),
        };
    };
    $detailSlotMinutes = fn (object $slot): int => (int) round((strtotime($slot->end_time) - strtotime($slot->start_time)) / 60);
    $detailWeek = $slots->groupBy(fn (object $slot): int => (int) $slot->weekday);
    $detailWeekdayKeys = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
    $detailShowProgress = $derivedStatus === 'current';
    $detailMonthLabels = collect($plan['months'])->map(fn (array $month): string => __('lf.LF_course_cohort_schedule_detail_month', ['month' => $month['month'], 'year' => $month['year']]))->all();
    $detailMonthCounts = collect($plan['months'])->map(fn (array $month): string => trans_choice('lf.LF_course_cohort_schedule_detail_month_count', $month['count'], ['count' => $month['count']]))->all();
@endphp
<dialog class="ui-modal ui-cohort-schedule-detail" data-columns="3" aria-labelledby="schedule_detail_title" aria-describedby="schedule_detail_notice" tabindex="-1"
        x-data="{
            view: 'calendar', month: @js($plan['initial_month']),
            monthLabels: @js($detailMonthLabels), monthCounts: @js($detailMonthCounts),
            closed() { history.replaceState(null, '', @js(route('admin.course-cohorts.show', ['id' => $cohort->id, 'tab' => 'schedules']))) },
            // The list is the readable view on a phone, where a month grid only fits colour marks.
            init() {
                if (window.matchMedia('(max-width: 680px)').matches) this.view = 'list'
                this.$nextTick(() => { this.$refs.dialog.showModal(); this.$refs.dialog.focus(); this.scrollToNext() })
            },
            setView(view) { this.view = view; this.$nextTick(() => this.scrollToNext()) },
            scrollToNext() {
                const list = this.$refs.sessionList, next = list?.querySelector('.is-next')
                if (this.view === 'list' && next) list.scrollTop = next.offsetTop - list.offsetTop - 40
            }
        }"
        x-ref="dialog" x-on:close="closed()">
    <div class="ui-modal__form">
        <header class="ui-modal__header">
            <div>
                <h3 id="schedule_detail_title" class="ui-cohort-schedule-detail__title">{{ $schedule->name }} <span class="ui-status ui-status--{{ $detailTone }}">{{ __('lf.LF_course_cohort_schedule_status_'.$derivedStatus) }}</span></h3>
                <p id="schedule_detail_notice" class="ui-caption ui-cohort-schedule-detail__notice"><x-backend-icon name="info" class="ui-icon" />{{ __('lf.LF_course_cohort_schedule_detail_notice') }}</p>
            </div>
            <button type="button" class="ui-modal__close cursor-interaction" x-on:click="$refs.dialog.close()" aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
        </header>
        <div class="ui-modal__body">
            <dl class="ui-summary ui-cohort-schedule-detail__summary">
                <div class="ui-summary__item">
                    <dt>{{ __('lf.LF_course_cohort_schedule_period') }}</dt>
                    <dd>{{ $detailDate($schedule->starts_on) }} <span aria-hidden="true">→</span> {{ $detailDate($schedule->ends_on) }}</dd>
                    <span class="ui-caption ui-framework-description">{{ trans_choice('lf.LF_course_cohort_schedule_detail_days', $plan['days'], ['count' => $plan['days']]) }}</span>
                </div>
                <div class="ui-summary__item">
                    <dt>{{ __('lf.LF_course_cohort_schedule_expected_count') }}</dt>
                    <dd>{{ trans_choice('lf.LF_course_cohort_schedule_detail_sessions', $plan['session_count'], ['count' => $plan['session_count']]) }}</dd>
                    @if ($detailShowProgress)
                        <span class="ui-caption ui-framework-description">{{ __('lf.LF_course_cohort_schedule_detail_progress', ['past' => $plan['past_count'], 'remaining' => $plan['session_count'] - $plan['past_count']]) }}</span>
                    @endif
                    @if ($plan['next'])
                        <span class="ui-caption ui-framework-description">{{ __('lf.LF_course_cohort_schedule_detail_next_at', ['date' => $detailDate($plan['next']['date'], 'd/m'), 'time' => $plan['next']['start_time']]) }}</span>
                    @endif
                </div>
                <div class="ui-summary__item">
                    <dt>{{ __('lf.LF_course_cohort_schedule_detail_total_duration') }}</dt>
                    <dd>{{ $detailDuration($plan['total_minutes']) }}</dd>
                    <span class="ui-caption ui-framework-description">{{ __('lf.LF_course_cohort_schedule_detail_per_week', ['duration' => $detailDuration($slots->sum($detailSlotMinutes))]) }}</span>
                </div>
                <div class="ui-summary__item">
                    <dt>{{ __('lf.LF_course_cohort_schedule_timezone') }}</dt>
                    <dd>{{ $schedule->timezone }}</dd>
                    <span class="ui-caption ui-framework-description">{{ $plan['timezone_offset'] }}</span>
                </div>
            </dl>

            <section class="ui-cohort-schedule-detail__block" aria-labelledby="schedule_detail_week">
                <div class="ui-cohort-schedule-detail__head">
                    <h4 id="schedule_detail_week">{{ __('lf.LF_course_cohort_schedule_detail_weekly') }} <span class="ui-caption">· {{ trans_choice('lf.LF_course_cohort_schedule_detail_slot_count', $slots->count(), ['count' => $slots->count()]) }}</span></h4>
                </div>
                <ul class="ui-cohort-schedule-detail__week">
                    @foreach (range(1, 7) as $weekday)
                        <li @class(['is-on' => $detailWeek->has($weekday)])>
                            <span class="ui-cohort-schedule-detail__weekday">{{ __('lf.LF_course_cohort_schedule_weekday_'.$weekday) }}</span>
                            @forelse ($detailWeek->get($weekday, collect()) as $slot)
                                <span class="ui-cohort-schedule-detail__weektime">{{ substr($slot->start_time, 0, 5) }}–{{ substr($slot->end_time, 0, 5) }} <span class="ui-caption">{{ $detailDuration($detailSlotMinutes($slot)) }}</span></span>
                            @empty
                                <span class="ui-caption">{{ __('lf.LF_course_cohort_schedule_detail_no_class') }}</span>
                            @endforelse
                        </li>
                    @endforeach
                </ul>
            </section>

            <section class="ui-cohort-schedule-detail__block" aria-labelledby="schedule_detail_plan">
                <div class="ui-cohort-schedule-detail__head">
                    <h4 id="schedule_detail_plan">{{ __('lf.LF_course_cohort_schedule_detail_plan') }}</h4>
                    <div class="ui-cohort-schedule-detail__views" role="group" aria-label="{{ __('lf.LF_course_cohort_schedule_detail_view') }}">
                        <button type="button" class="cursor-interaction" x-bind:aria-pressed="(view === 'calendar').toString()" x-on:click="setView('calendar')"><x-backend-icon name="calendar" class="ui-icon" />{{ __('lf.LF_course_cohort_schedule_detail_view_calendar') }}</button>
                        <button type="button" class="cursor-interaction" x-bind:aria-pressed="(view === 'list').toString()" x-on:click="setView('list')"><x-backend-icon name="list" class="ui-icon" />{{ __('lf.LF_course_cohort_schedule_detail_view_list') }}</button>
                    </div>
                </div>

                <div x-show="view === 'calendar'">
                    <div class="ui-cohort-schedule-detail__monthbar">
                        <button type="button" class="ux-row-edit cursor-interaction" x-on:click="month--" x-bind:disabled="month === 0" aria-label="{{ __('lf.LF_common_date_prev_month') }}"><x-backend-icon name="chevron-left" class="ui-icon" /></button>
                        <strong aria-live="polite" x-text="monthLabels[month]">{{ $detailMonthLabels[$plan['initial_month']] ?? '' }}</strong>
                        <button type="button" class="ux-row-edit cursor-interaction" x-on:click="month++" x-bind:disabled="month === monthLabels.length - 1" aria-label="{{ __('lf.LF_common_date_next_month') }}"><x-backend-icon name="chevron-right" class="ui-icon" /></button>
                        <span class="ui-caption" x-text="monthCounts[month]"></span>
                    </div>
                    @foreach ($plan['months'] as $monthIndex => $planMonth)
                        <div class="ui-cohort-schedule-detail__calendar" role="list" aria-label="{{ $detailMonthLabels[$monthIndex] }}" x-show="month === {{ $monthIndex }}" @if ($monthIndex !== $plan['initial_month']) x-cloak @endif>
                            @foreach ($detailWeekdayKeys as $weekdayKey)
                                <div class="ui-cohort-schedule-detail__dow" aria-hidden="true">{{ __('lf.LF_common_weekday_'.$weekdayKey) }}</div>
                            @endforeach
                            @foreach ($planMonth['cells'] as $cell)
                                @if ($cell === null)
                                    <div class="ui-cohort-schedule-detail__day is-blank" aria-hidden="true"></div>
                                @else
                                    <div role="listitem" @class(['ui-cohort-schedule-detail__day', 'is-out' => $cell['out'], 'is-today' => $cell['today']])>
                                        <span class="ui-cohort-schedule-detail__daynum">{{ $cell['day'] }}</span>
                                        @foreach ($cell['items'] as $item)
                                            @if ($item['excluded'])
                                                <span class="ui-cohort-schedule-detail__mark is-excluded" title="{{ $item['reason'] ?: __('lf.LF_course_cohort_schedule_detail_legend_excluded') }}"><s>{{ $item['start_time'] }}–{{ $item['end_time'] }}</s> {{ $item['reason'] ?: __('lf.LF_course_cohort_schedule_detail_day_off') }}</span>
                                            @else
                                                <span @class(['ui-cohort-schedule-detail__mark', 'is-past' => $item['past'], 'is-next' => $item['next']])>{{ $item['start_time'] }}–{{ $item['end_time'] }}</span>
                                            @endif
                                        @endforeach
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    @endforeach
                    <div class="ui-cohort-schedule-detail__legend" aria-hidden="true">
                        <span><i class="ui-cohort-schedule-detail__swatch"></i>{{ __('lf.LF_course_cohort_schedule_detail_legend_planned') }}</span>
                        @if ($detailShowProgress)
                            <span><i class="ui-cohort-schedule-detail__swatch is-next"></i>{{ __('lf.LF_course_cohort_schedule_detail_next') }}</span>
                            <span><i class="ui-cohort-schedule-detail__swatch is-past"></i>{{ __('lf.LF_course_cohort_schedule_detail_legend_past') }}</span>
                        @endif
                        <span><i class="ui-cohort-schedule-detail__swatch is-excluded"></i>{{ __('lf.LF_course_cohort_schedule_detail_legend_excluded') }}</span>
                        <span><i class="ui-cohort-schedule-detail__swatch is-out"></i>{{ __('lf.LF_course_cohort_schedule_detail_legend_out') }}</span>
                    </div>
                </div>

                <div x-show="view === 'list'" x-cloak>
                    @if ($plan['weeks'] === [])
                        <p class="ui-caption">{{ __('lf.LF_course_cohort_schedule_preview_empty') }}</p>
                    @else
                        <ul class="ui-cohort-schedule-detail__list" x-ref="sessionList">
                            @foreach ($plan['weeks'] as $planWeek)
                                <li class="ui-cohort-schedule-detail__listweek">
                                    <span>{{ __('lf.LF_course_cohort_schedule_detail_week_range', ['from' => $detailDate($planWeek['from'], 'd/m'), 'to' => $detailDate($planWeek['to'])]) }}</span>
                                    <span>{{ trans_choice('lf.LF_course_cohort_schedule_detail_sessions', $planWeek['count'], ['count' => $planWeek['count']]) }}</span>
                                </li>
                                @foreach ($planWeek['items'] as $item)
                                    <li @class(['ui-cohort-schedule-detail__listrow', 'is-excluded' => $item['excluded'], 'is-past' => $item['past'], 'is-next' => $item['next']])>
                                        <span class="ui-caption">{{ $item['number'] }}</span>
                                        <span class="ui-cohort-schedule-detail__listdate">{{ __('lf.LF_course_cohort_schedule_weekday_'.$item['weekday']) }}, {{ $detailDate($item['date']) }}</span>
                                        <span>{{ $item['start_time'] }}–{{ $item['end_time'] }} <span class="ui-caption">· {{ $detailDuration($item['minutes']) }}</span></span>
                                        @if ($item['excluded'])
                                            <span class="ui-status ui-status--inactive">{{ __('lf.LF_course_cohort_schedule_detail_legend_excluded') }}@if ($item['reason']) · {{ $item['reason'] }}@endif</span>
                                        @elseif ($item['next'])
                                            <span class="ui-status ui-status--active">{{ __('lf.LF_course_cohort_schedule_detail_next') }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            @endforeach
                        </ul>
                    @endif
                </div>
            </section>

            <section class="ui-cohort-schedule-detail__block" aria-labelledby="schedule_detail_exclusions">
                <div class="ui-cohort-schedule-detail__head">
                    <h4 id="schedule_detail_exclusions">{{ __('lf.LF_course_cohort_schedule_exclusions') }} <span class="ui-caption">· {{ count($plan['exclusions']) }}</span></h4>
                </div>
                @if ($plan['exclusions'] === [])
                    <p class="ui-caption">{{ __('lf.LF_course_cohort_schedule_no_exclusions') }}</p>
                @else
                    <ul class="ui-cohort-schedule-detail__exclusions">
                        @foreach ($plan['exclusions'] as $exclusion)
                            <li>
                                <span class="ui-cohort-schedule-detail__listdate">{{ __('lf.LF_course_cohort_schedule_weekday_'.$exclusion['weekday']) }}, {{ $detailDate($exclusion['date']) }}</span>
                                @if ($exclusion['reason'])<span>{{ $exclusion['reason'] }}</span>@endif
                                <span class="ui-caption">{{ $exclusion['hits'] > 0 ? trans_choice('lf.LF_course_cohort_schedule_detail_exclusion_hits', $exclusion['hits'], ['count' => $exclusion['hits']]) : __('lf.LF_course_cohort_schedule_exclusion_no_session') }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
        <footer class="ui-modal__footer">
            <span class="ui-caption">@if ($canMutate){{ __('lf.LF_course_cohort_schedule_detail_edit_note') }}@endif</span>
            <button type="button" class="ui-button cursor-interaction" x-on:click="$refs.dialog.close()">{{ __('lf.LF_common_button_close') }}</button>
            @if ($canMutate)
                <a class="ui-button ui-button--primary cursor-interaction" href="{{ route('admin.course-cohorts.show', ['id' => $cohort->id, 'tab' => 'schedules', 'schedule_form' => 'edit', 'schedule_id' => $schedule->id]) }}">
                    <x-backend-icon name="square-pen" class="ui-icon" />{{ __('lf.LF_course_cohort_schedule_detail_edit') }}
                </a>
            @endif
        </footer>
    </div>
</dialog>
