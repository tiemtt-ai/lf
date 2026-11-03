{{-- Schedule detail popup: read-only, available whatever the class status. --}}
@php
    $detailTone = match ($derivedStatus) { 'current' => 'active', 'upcoming' => 'draft', default => 'archived' };
@endphp
<dialog class="ui-modal ui-cohort-schedule-detail" data-columns="2" aria-labelledby="schedule_detail_title"
        x-data="{ closed() { history.replaceState(null, '', @js(route('admin.course-cohorts.show', ['id' => $cohort->id, 'tab' => 'schedules']))) } }"
        x-ref="dialog" x-init="$nextTick(() => $refs.dialog.showModal())" x-on:close="closed()">
    <div class="ui-modal__form">
        <header class="ui-modal__header">
            <div>
                <h3 id="schedule_detail_title">{{ $schedule->name }}</h3>
                <p class="ui-caption">{{ __('lf.LF_course_cohort_schedule_detail_notice') }}</p>
            </div>
            <button type="button" class="ui-modal__close cursor-interaction" x-on:click="$refs.dialog.close()" aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
        </header>
        <div class="ui-modal__body">
            <dl class="ui-summary ui-enroll-summary ui-cohort-schedule-facts">
                <div class="ui-summary__item">
                    <dt>{{ __('lf.LF_course_cohort_schedule_status') }}</dt>
                    <dd><span class="ui-status ui-status--{{ $detailTone }}">{{ __('lf.LF_course_cohort_schedule_status_'.$derivedStatus) }}</span></dd>
                </div>
                <div class="ui-summary__item">
                    <dt>{{ __('lf.LF_course_cohort_schedule_period') }}</dt>
                    <dd>
                        {{ \Illuminate\Support\Carbon::parse($schedule->starts_on)->format('d/m/Y') }}
                        <span aria-hidden="true">→</span>
                        {{ \Illuminate\Support\Carbon::parse($schedule->ends_on)->format('d/m/Y') }}
                    </dd>
                </div>
                <div class="ui-summary__item">
                    <dt>{{ __('lf.LF_course_cohort_schedule_timezone') }}</dt>
                    <dd>{{ $schedule->timezone }}</dd>
                </div>
            </dl>

            <div class="ui-cohort-schedule-layout ui-cohort-schedule-layout--even">
                <div class="ui-cohort-schedule-fields">
                    <div class="ui-cohort-schedule-group">
                        <p class="ui-field__label">{{ __('lf.LF_course_cohort_schedule_slots') }} ({{ $slots->count() }})</p>
                        <ul class="ui-cohort-schedule-preview__list">
                            @foreach ($slots as $slot)
                                <li>
                                    <span class="mk-name-text">{{ __('lf.LF_course_cohort_schedule_weekday_'.$slot->weekday) }}</span>
                                    <span class="ui-caption">{{ substr($slot->start_time, 0, 5) }}–{{ substr($slot->end_time, 0, 5) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                    <div class="ui-cohort-schedule-group">
                        <p class="ui-field__label">{{ __('lf.LF_course_cohort_schedule_exclusions') }} ({{ $exclusions->count() }})</p>
                        @if ($exclusions->isEmpty())
                            <p class="ui-caption">{{ __('lf.LF_course_cohort_schedule_no_exclusions') }}</p>
                        @else
                            <ul class="ui-cohort-schedule-preview__list">
                                @foreach ($exclusions as $exclusion)
                                    <li>
                                        <span class="mk-name-text">{{ \Illuminate\Support\Carbon::parse($exclusion->excluded_on)->format('d/m/Y') }}</span>
                                        <span class="ui-caption">{{ $exclusion->reason ?: '—' }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
                <div class="ui-cohort-schedule-group">
                    <p class="ui-field__label">{{ __('lf.LF_course_cohort_schedule_expected_count') }} ({{ $preview->count() }})</p>
                    @if ($preview->isEmpty())
                        <p class="ui-caption">{{ __('lf.LF_course_cohort_schedule_preview_empty') }}</p>
                    @else
                        <ul class="ui-cohort-schedule-preview__list">
                            @foreach ($preview as $item)
                                <li>
                                    <span class="mk-name-text">{{ \Illuminate\Support\Str::ucfirst(\Illuminate\Support\Carbon::parse($item['date'])->locale(app()->getLocale())->translatedFormat('l, d/m/Y')) }}</span>
                                    <span class="ui-caption">{{ $item['start_time'] }}–{{ $item['end_time'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>
        <footer class="ui-modal__footer">
            <span></span>
            <button type="button" class="ui-button cursor-interaction" x-on:click="$refs.dialog.close()">{{ __('lf.LF_common_button_close') }}</button>
            @if ($canMutate)
                <a class="ui-button ui-button--primary cursor-interaction" href="{{ route('admin.course-cohorts.show', ['id' => $cohort->id, 'tab' => 'schedules', 'schedule_form' => 'edit', 'schedule_id' => $schedule->id]) }}">
                    <x-backend-icon name="square-pen" class="ui-icon" />{{ __('lf.LF_common_button_edit') }}
                </a>
            @endif
        </footer>
    </div>
</dialog>
