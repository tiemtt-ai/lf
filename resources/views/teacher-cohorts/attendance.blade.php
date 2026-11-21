@extends('layouts.backend')

@section('title', __('lf.LF_teacher_attendance_title'))
@section('page_title', __('lf.LF_teacher_attendance_title'))

@section('page_title_hidden', '1')

@section('page_back')
    <x-page-back-link :href="route('teacher.cohorts.index')" :label="__('lf.LF_teacher_attendance_back')" />
@endsection

@section('vite')
    @parent
    @vite(['resources/css/admin/admin-ui-kit.css', 'resources/js/admin-ui-kit-controls.js'])
@endsection

@section('content')
    @php
        $minutes = fn (?int $value): string => $value === null
            ? __('lf.LF_attendance_policy_unlimited')
            : trans_choice('lf.LF_attendance_policy_minutes', $value, ['count' => $value]);
        $closesLabel = $closesAt?->setTimezone(config('app.timezone'))->format('d/m/Y H:i');
    @endphp
<div class="lf-admin-ui lf-admin-ui--list ui-teacher-attendance">
    <x-ui-list-toasts />

    <x-ui-list-head :title="__('lf.LF_teacher_attendance_title')" :text="__('lf.LF_teacher_attendance_description', ['name' => $cohort->name])" />

    <section class="mk-section" aria-labelledby="teacher-attendance-title">
        <div class="ui-summary-head">
            <div>
                <h2 id="teacher-attendance-title">{{ $cohort->name }}</h2>
                <p class="ui-caption" data-attendance-policy>
                    {{ __('lf.LF_attendance_policy_in_use', ['name' => $policy->name]) }}
                    {{ __('lf.LF_attendance_policy_summary_opens', ['value' => $minutes((int) $policy->opens_before_minutes)]) }}
                    · {{ __('lf.LF_attendance_policy_summary_late', ['value' => $minutes((int) $policy->late_after_minutes)]) }}
                    · {{ __('lf.LF_attendance_policy_summary_absent', ['value' => $minutes($policy->absent_after_minutes === null ? null : (int) $policy->absent_after_minutes)]) }}.
                </p>
            </div>
            <div class="mk-table-top__actions" x-data>
                <button type="button" class="ui-button ui-button--outline cursor-interaction" aria-haspopup="dialog"
                        x-on:click="$refs.policyDialog.showModal()" data-attendance-policy-open>
                    <x-backend-icon name="eye" class="ui-icon" />{{ __('lf.LF_teacher_attendance_rules_open') }}
                </button>
                {{-- The class's attendance policy in the teacher's words, read-only: a teacher looks, the administrator edits. --}}
                <dialog x-ref="policyDialog" class="ui-modal ui-teacher-attendance-rules" data-columns="1" aria-labelledby="teacher-attendance-policy-title" data-attendance-policy-dialog>
                    <header class="ui-modal__header">
                        <div>
                            <h3 id="teacher-attendance-policy-title">{{ __('lf.LF_teacher_attendance_rules_title') }}</h3>
                            <p class="ui-caption">{{ __('lf.LF_teacher_attendance_rules_help', ['name' => $policy->name]) }}</p>
                        </div>
                        <button type="button" class="ui-modal__close cursor-interaction" x-on:click="$refs.policyDialog.close()" aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
                    </header>
                    <div class="ui-modal__body">
                        @php
                            $after = fn (int $value, string $key): string => __('lf.LF_teacher_attendance_rules_'.$key, ['value' => $minutes($value)]);
                            $recording = fn (string $mode, string $value): string => __('lf.LF_teacher_attendance_rules_'.$mode.'_'.$value);
                            $ruleGroups = [
                                'group_time' => [
                                    'opens' => (int) $policy->opens_before_minutes === 0
                                        ? __('lf.LF_teacher_attendance_rules_opens_on_time')
                                        : $after((int) $policy->opens_before_minutes, 'opens_value'),
                                    'late' => $after((int) $policy->late_after_minutes, 'from_start_value'),
                                    'absent' => $policy->absent_after_minutes === null
                                        ? __('lf.LF_teacher_attendance_rules_absent_never')
                                        : $after((int) $policy->absent_after_minutes, 'from_start_value'),
                                    'start' => __($policy->count_from_actual_start
                                        ? 'lf.LF_teacher_attendance_rules_start_actual'
                                        : 'lf.LF_teacher_attendance_rules_start_schedule'),
                                    'auto_absent' => $after((int) $policy->auto_absent_after_minutes, 'auto_absent_value'),
                                ],
                                'group_recording' => [
                                    'offline' => $recording('offline', (string) $policy->offline_recording),
                                    'online' => $recording('online', (string) $policy->online_recording),
                                ],
                                'group_edit' => [
                                    'edit' => __('lf.LF_teacher_attendance_rules_edit_value', [
                                        'value' => trans_choice('lf.LF_attendance_policy_days', (int) $policy->teacher_edit_days, ['count' => (int) $policy->teacher_edit_days]),
                                    ]),
                                ],
                            ];
                        @endphp
                        @foreach ($ruleGroups as $group => $rows)
                            <section class="ui-teacher-attendance-rules__group">
                                <h4>{{ __('lf.LF_teacher_attendance_rules_'.$group) }}</h4>
                                <dl class="ui-attendance-policy-view">
                                    @foreach ($rows as $row => $value)
                                        <div>
                                            <dt>{{ __('lf.LF_teacher_attendance_rules_'.$row) }}</dt>
                                            <dd>{{ $value }}</dd>
                                        </div>
                                    @endforeach
                                </dl>
                            </section>
                        @endforeach
                    </div>
                    <footer class="ui-modal__footer">
                        <span></span>
                        <button type="button" class="ui-button cursor-interaction" x-on:click="$refs.policyDialog.close()">{{ __('lf.LF_common_button_close') }}</button>
                    </footer>
                </dialog>
            </div>
        </div>

        @if ($selected)
            <div class="mk-tablebar mk-tablebar--filters ui-attendance-filterbar">
                <form method="GET" action="{{ route('teacher.cohorts.attendance', $cohort->id) }}" class="mk-filter-form ui-attendance-session">
                    <div class="ui-field mk-search">
                        <label class="ui-field__label ui-sr-only" for="teacher-attendance-session">{{ __('lf.LF_teacher_attendance_session') }}</label>
                        <select id="teacher-attendance-session" name="session_id" data-ui-kit-select onchange="this.form.submit()">
                            @foreach ($sessions as $session)
                                <option value="{{ $session->id }}" @selected((int) $selected->id === (int) $session->id)>
                                    {{ \Illuminate\Support\Carbon::parse($session->scheduled_start_at)->format('d/m/Y H:i') }} · #{{ $session->session_no }} · {{ $session->title }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </form>
            </div>

            @if (! $mayOperate)
                <div class="ui-banner ui-cohort-tab-notice" role="status"><span class="ui-banner__text">{{ __('lf.LF_teacher_attendance_no_permission') }}</span></div>
            @elseif (! in_array($cohort->status, \App\Services\LiveClassAttendanceRecorder::MANUAL_COHORT_STATUSES, true))
                <div class="ui-banner ui-cohort-tab-notice" role="status"><span class="ui-banner__text">{{ __('lf.LF_teacher_attendance_closed_cohort') }}</span></div>
            @elseif (! $editable)
                <div class="ui-banner ui-cohort-tab-notice" role="status"><span class="ui-banner__text">{{ __('lf.LF_teacher_attendance_closed', ['time' => $closesLabel]) }}</span></div>
            @else
                <div class="ui-banner ui-banner--info ui-cohort-tab-notice" role="status" data-attendance-closes>
                    <span class="ui-banner__text">{{ __('lf.LF_teacher_attendance_closes', ['time' => $closesLabel]) }}</span>
                </div>
                @if ($cohort->status === 'completed')
                    <div class="ui-banner ui-cohort-tab-notice" role="status" data-attendance-completed-note>
                        <span class="ui-banner__text">{{ __('lf.LF_attendance_completed_class_note') }}</span>
                    </div>
                @endif
            @endif

            @include('liveclass-attendance.partials.roster', [
                'attendance' => $attendance,
                'history' => $history,
                'attendanceTool' => $attendanceTool,
                'action' => $editable ? route('teacher.cohorts.attendance.save', [$cohort->id, $selected->id]) : null,
            ])
        @else
            <div class="mk-empty" role="status">
                <strong>{{ __('lf.LF_teacher_attendance_empty') }}</strong>
                <p class="ui-caption">{{ __('lf.LF_teacher_attendance_empty_help') }}</p>
            </div>
        @endif
    </section>

    @if ($selected)
        @include('liveclass-attendance.partials.history', ['history' => $history])
    @endif
</div>
@endsection
