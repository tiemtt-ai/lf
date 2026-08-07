<div class="course-cohort-schedule-form course-cohort-schedule-detail course-cohort-schedule-detail--inline">
    <section class="admin-form-standard-section course-cohort-schedule-detail__overview">
        <header class="course-cohort-schedule-detail__header">
            <div>
                <span class="course-cohort-schedule-detail__eyebrow">
                    {{ __('lf.LF_course_cohort_schedule_detail_title') }}
                </span>
                <h2 class="admin-form-section-title">{{ $schedule->name }}</h2>
                <p class="admin-form-section-help">{{ __('lf.LF_course_cohort_schedule_detail_notice') }}</p>
            </div>
            <span @class([
                'badge',
                'course-cohort-schedules__status-badge',
                'badge-success' => $derivedStatus === 'current',
                'is-upcoming' => $derivedStatus === 'upcoming',
                'is-completed' => $derivedStatus === 'completed',
            ])>
                {{ __('lf.LF_course_cohort_schedule_status_'.$derivedStatus) }}
            </span>
        </header>

        <dl class="course-cohort-schedule-detail__facts">
            <div>
                <dt>{{ __('lf.LF_course_cohort_schedule_period') }}</dt>
                <dd>
                    {{ \Illuminate\Support\Carbon::parse($schedule->starts_on)->format('d/m/Y') }}
                    <span aria-hidden="true">→</span>
                    {{ \Illuminate\Support\Carbon::parse($schedule->ends_on)->format('d/m/Y') }}
                </dd>
            </div>
            <div>
                <dt>{{ __('lf.LF_course_cohort_schedule_timezone') }}</dt>
                <dd>{{ $schedule->timezone }}</dd>
            </div>
            <div>
                <dt>{{ __('lf.LF_course_cohort_schedule_expected_count') }}</dt>
                <dd>{{ $preview->count() }}</dd>
            </div>
        </dl>
    </section>

    <section class="admin-form-standard-section course-cohort-schedule-detail__section">
        <header class="course-cohort-schedule-detail__section-header">
            <div>
                <h2 class="admin-form-section-title">{{ __('lf.LF_course_cohort_schedule_slots') }}</h2>
                <p class="admin-form-section-help">{{ __('lf.LF_course_cohort_schedule_slots_help') }}</p>
            </div>
            <span class="course-cohort-schedule-detail__section-count">
                {{ __('lf.LF_course_cohort_schedule_slots_count', ['count' => $slots->count()]) }}
            </span>
        </header>
        <div class="admin-table-wrap course-cohort-schedule-detail__table-wrap">
            <table class="table course-cohort-schedule-detail__table">
                <thead>
                <tr>
                    <th scope="col">{{ __('lf.LF_course_cohort_schedule_weekday') }}</th>
                    <th scope="col" class="course-cohort-schedule-detail__table-col-time">{{ __('lf.LF_course_cohort_schedule_start_time') }}</th>
                    <th scope="col" class="course-cohort-schedule-detail__table-col-time">{{ __('lf.LF_course_cohort_schedule_end_time') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach($slots as $slot)
                    <tr>
                        <td data-label="{{ __('lf.LF_course_cohort_schedule_weekday') }}">
                            <strong>{{ __('lf.LF_course_cohort_schedule_weekday_'.$slot->weekday) }}</strong>
                        </td>
                        <td class="course-cohort-schedule-detail__table-col-time" data-label="{{ __('lf.LF_course_cohort_schedule_start_time') }}">{{ substr($slot->start_time, 0, 5) }}</td>
                        <td class="course-cohort-schedule-detail__table-col-time" data-label="{{ __('lf.LF_course_cohort_schedule_end_time') }}">{{ substr($slot->end_time, 0, 5) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="admin-form-standard-section course-cohort-schedule-detail__section">
        <header class="course-cohort-schedule-detail__section-header">
            <div>
                <h2 class="admin-form-section-title">{{ __('lf.LF_course_cohort_schedule_exclusions') }}</h2>
                <p class="admin-form-section-help">{{ __('lf.LF_course_cohort_schedule_exclusions_help') }}</p>
            </div>
            @if($exclusions->isNotEmpty())
                <span class="course-cohort-schedule-detail__section-count">
                    {{ __('lf.LF_course_cohort_schedule_exclusions_count', ['count' => $exclusions->count()]) }}
                </span>
            @endif
        </header>

        @if($exclusions->isEmpty())
            <div class="course-cohort-schedule-detail__empty">
                {{ __('lf.LF_course_cohort_schedule_no_exclusions') }}
            </div>
        @else
            <div class="admin-table-wrap course-cohort-schedule-detail__table-wrap">
                <table class="table course-cohort-schedule-detail__table course-cohort-schedule-detail__table--exclusions">
                    <thead>
                    <tr>
                        <th scope="col">{{ __('lf.LF_course_cohort_schedule_excluded_on') }}</th>
                        <th scope="col">{{ __('lf.LF_course_cohort_schedule_reason') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($exclusions as $exclusion)
                        <tr>
                            <td data-label="{{ __('lf.LF_course_cohort_schedule_excluded_on') }}">
                                <strong>{{ \Illuminate\Support\Carbon::parse($exclusion->excluded_on)->format('d/m/Y') }}</strong>
                            </td>
                            <td data-label="{{ __('lf.LF_course_cohort_schedule_reason') }}">{{ $exclusion->reason ?: '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="admin-form-standard-section course-cohort-schedule-detail__section course-cohort-schedule-detail__preview">
        <header class="course-cohort-schedule-detail__section-header">
            <div>
                <h2 class="admin-form-section-title">{{ __('lf.LF_course_cohort_schedule_preview') }}</h2>
                <p class="admin-form-section-help">{{ __('lf.LF_course_cohort_schedule_detail_notice') }}</p>
            </div>
            <span class="course-cohort-schedule-preview__count">
                {{ __('lf.LF_course_cohort_schedule_expected_count_value', ['count' => $preview->count()]) }}
            </span>
        </header>

        <div class="course-cohort-schedule-preview__list">
            @forelse($preview as $item)
                <div class="course-cohort-schedule-preview__item">
                    <strong>{{ \Illuminate\Support\Str::ucfirst(\Illuminate\Support\Carbon::parse($item['date'])->locale(app()->getLocale())->translatedFormat('l, d/m/Y')) }}</strong>
                    <span>{{ $item['start_time'] }}–{{ $item['end_time'] }}</span>
                </div>
            @empty
                <p class="course-cohort-schedule-preview__empty">{{ __('lf.LF_course_cohort_schedule_preview_empty') }}</p>
            @endforelse
        </div>
    </section>

    <footer class="admin-form-footer admin-form-footer--sticky course-cohort-schedule-detail__footer" data-actions-align="end">
        <div class="admin-form-footer-primary">
            <button type="button" class="btn btn-secondary" x-on:click="$dispatch('schedule-editor-close')">
                {{ __('lf.LF_common_button_close') }}
            </button>
            @if($canMutate)
                <a class="btn btn-primary" href="{{ route('admin.course-cohorts.show', ['id' => $cohort->id, 'tab' => 'schedules', 'schedule_form' => 'edit', 'schedule_id' => $schedule->id]) }}#cohort-schedule-editor">
                    {{ __('lf.LF_common_button_edit') }}
                </a>
            @endif
        </div>
    </footer>
</div>
