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
                    <x-backend-icon name="eye" class="ui-icon" />{{ __('lf.LF_attendance_policy_view_full') }}
                </button>
                {{-- The whole policy of this class, read-only: a teacher looks, the administrator edits. --}}
                <dialog x-ref="policyDialog" class="ui-modal" data-columns="1" aria-labelledby="teacher-attendance-policy-title" data-attendance-policy-dialog>
                    <header class="ui-modal__header">
                        <div>
                            <h3 id="teacher-attendance-policy-title">{{ __('lf.LF_attendance_policy_view_title', ['name' => $policy->name]) }}</h3>
                            <p class="ui-caption">{{ __('lf.LF_attendance_policy_view_help') }}</p>
                        </div>
                        <button type="button" class="ui-modal__close cursor-interaction" x-on:click="$refs.policyDialog.close()" aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
                    </header>
                    <div class="ui-modal__body">
                        @php
                            $onOff = fn ($value): string => $value ? __('lf.LF_attendance_policy_on') : __('lf.LF_attendance_policy_off');
                            $days = fn (int $value): string => trans_choice('lf.LF_attendance_policy_days', $value, ['count' => $value]);
                            $policyRows = [
                                'opens_before_minutes' => $minutes((int) $policy->opens_before_minutes),
                                'late_after_minutes' => $minutes((int) $policy->late_after_minutes),
                                'absent_after_minutes' => $minutes($policy->absent_after_minutes === null ? null : (int) $policy->absent_after_minutes),
                                'auto_absent_after_minutes' => $minutes((int) $policy->auto_absent_after_minutes),
                                'count_from_actual_start' => $onOff($policy->count_from_actual_start),
                                'offline_recording' => __('lf.LF_attendance_policy_recording_'.$policy->offline_recording),
                                'online_recording' => __('lf.LF_attendance_policy_recording_'.$policy->online_recording),
                                'teacher_edit_days' => $days((int) $policy->teacher_edit_days),
                            ];
                        @endphp
                        <dl class="ui-attendance-policy-view">
                            @foreach ($policyRows as $field => $value)
                                <div>
                                    <dt>{{ __('lf.LF_attendance_policy_field_'.$field) }}</dt>
                                    <dd>{{ $value }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>
                    <footer class="ui-modal__footer">
                        <span></span>
                        <button type="button" class="ui-button cursor-interaction" x-on:click="$refs.policyDialog.close()">{{ __('lf.LF_common_button_close') }}</button>
                    </footer>
                </dialog>
            </div>
        </div>

        @if ($selected)
            <form method="GET" action="{{ route('teacher.cohorts.attendance', $cohort->id) }}" class="ui-field ui-attendance-session">
                <label class="ui-field__label" for="teacher-attendance-session">{{ __('lf.LF_teacher_attendance_session') }}</label>
                <select id="teacher-attendance-session" name="session_id" data-ui-kit-select onchange="this.form.submit()">
                    @foreach ($sessions as $session)
                        <option value="{{ $session->id }}" @selected((int) $selected->id === (int) $session->id)>
                            #{{ $session->session_no }} · {{ $session->title }} · {{ \Illuminate\Support\Carbon::parse($session->scheduled_start_at)->format('d/m/Y H:i') }}
                        </option>
                    @endforeach
                </select>
            </form>

            @if ($cohort->status !== 'active')
                <div class="ui-banner ui-cohort-tab-notice" role="status"><span class="ui-banner__text">{{ __('lf.LF_teacher_attendance_closed_cohort') }}</span></div>
            @elseif (! $editable)
                <div class="ui-banner ui-cohort-tab-notice" role="status"><span class="ui-banner__text">{{ __('lf.LF_teacher_attendance_closed', ['time' => $closesLabel]) }}</span></div>
            @else
                <p class="ui-caption" data-attendance-closes>{{ __('lf.LF_teacher_attendance_closes', ['time' => $closesLabel]) }}</p>
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
</div>
@endsection
