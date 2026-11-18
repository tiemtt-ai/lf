@extends('layouts.backend')

@section('title', __('lf.LF_attendance_policy_title'))
@section('page_title', __('lf.LF_attendance_policy_title'))

@section('page_title_hidden', '1')

@section('vite')
    @parent
    @vite(['resources/css/admin/admin-ui-kit.css', 'resources/js/admin-ui-kit-controls.js'])
@endsection

@section('content')
    @php
        $indexUrl = route('admin.attendance-policies.index');
        $minutes = fn (?int $value): string => $value === null
            ? __('lf.LF_attendance_policy_unlimited')
            : trans_choice('lf.LF_attendance_policy_minutes', $value, ['count' => $value]);
        $createRetry = $errors->getBag('policyCreate')->isNotEmpty();
    @endphp
<div class="lf-admin-ui lf-admin-ui--list ui-attendance-policies" x-data>
    <x-ui-list-toasts />

    @if ($errors->has('policy'))
        <div class="admin-alert admin-alert-danger" role="alert">{{ $errors->first('policy') }}</div>
    @endif

    <x-ui-list-head :title="__('lf.LF_attendance_policy_title')" :text="__('lf.LF_attendance_policy_description')" />

    <section class="mk-section" aria-labelledby="attendance-policies-title">
        <div class="ui-summary-head">
            <div>
                <h2 id="attendance-policies-title">{{ __('lf.LF_attendance_policy_list_title') }}</h2>
                <p class="ui-caption">{{ __('lf.LF_attendance_policy_list_help') }}</p>
            </div>
            <div class="mk-table-top__actions">
                <span class="ui-caption ui-enroll-nowrap">{{ trans_choice('lf.LF_attendance_policy_count', $policies->count(), ['count' => $policies->count()]) }}</span>
                <button type="button" class="ui-button ui-button--outline cursor-interaction" aria-haspopup="dialog"
                        x-on:click="document.getElementById('attendance-policy-create').showModal()">
                    <svg class="ui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
                    {{ __('lf.LF_attendance_policy_add') }}
                </button>
            </div>
        </div>

        <div class="mk-table-scroll" role="region" aria-labelledby="attendance-policies-title" tabindex="0">
            <table>
                <colgroup>
                    <col class="mk-col-sequence">
                    <col>
                    <col>
                    <col>
                    <col class="ui-attendance-policy-col-count">
                    <col class="ui-attendance-policy-col-action">
                </colgroup>
                <thead><tr>
                    <th scope="col" class="mk-sequence">{{ __('lf.table_no') }}</th>
                    <th scope="col" class="mk-text-cell">{{ __('lf.LF_attendance_policy_field_name') }}</th>
                    <th scope="col" class="mk-text-cell">{{ __('lf.LF_attendance_policy_col_thresholds') }}</th>
                    <th scope="col" class="mk-text-cell">{{ __('lf.LF_attendance_policy_col_recording') }}</th>
                    <th scope="col" class="ux-action-cell">{{ __('lf.LF_attendance_policy_col_cohorts') }}</th>
                    <th scope="col" class="ux-action-cell">{{ __('lf.table_actions') }}</th>
                </tr></thead>
                <tbody>
                @foreach ($policies as $policy)
                    <tr>
                        <td class="mk-sequence" data-label="{{ __('lf.table_no') }}">{{ $loop->iteration }}</td>
                        <td class="mk-text-cell" data-label="{{ __('lf.LF_attendance_policy_field_name') }}">
                            <span class="mk-name-text">{{ $policy->name }}</span>
                            @if ($policy->is_default)
                                <span class="ui-status ui-status--active">{{ __('lf.LF_attendance_policy_default_badge') }}</span>
                            @endif
                        </td>
                        <td class="mk-text-cell" data-label="{{ __('lf.LF_attendance_policy_col_thresholds') }}">
                            {{-- The three thresholds weigh the same: one line each, one style. --}}
                            <span class="ui-attendance-policy-line">{{ __('lf.LF_attendance_policy_summary_opens', ['value' => $minutes((int) $policy->opens_before_minutes)]) }}</span>
                            <span class="ui-attendance-policy-line">{{ __('lf.LF_attendance_policy_summary_late', ['value' => $minutes((int) $policy->late_after_minutes)]) }}</span>
                            <span class="ui-attendance-policy-line">{{ __('lf.LF_attendance_policy_summary_absent', ['value' => $minutes($policy->absent_after_minutes === null ? null : (int) $policy->absent_after_minutes)]) }}</span>
                        </td>
                        <td class="mk-text-cell" data-label="{{ __('lf.LF_attendance_policy_col_recording') }}">
                            <span class="ui-attendance-policy-line">{{ __('lf.LF_attendance_policy_summary_offline', ['value' => __('lf.LF_attendance_policy_recording_'.$policy->offline_recording)]) }}</span>
                            <span class="ui-attendance-policy-line">{{ __('lf.LF_attendance_policy_summary_online', ['value' => __('lf.LF_attendance_policy_recording_'.$policy->online_recording)]) }}</span>
                        </td>
                        <td class="ux-action-cell" data-label="{{ __('lf.LF_attendance_policy_col_cohorts') }}">{{ $policy->cohort_count }}</td>
                        <td class="ux-action-cell" data-label="{{ __('lf.table_actions') }}">
                            <a class="ux-row-edit cursor-interaction" href="{{ $indexUrl }}?policy_form=apply&policy_id={{ $policy->id }}"
                               aria-label="{{ __('lf.LF_attendance_policy_apply') }}: {{ $policy->name }}" data-tooltip="{{ __('lf.LF_attendance_policy_apply') }}">
                                <x-backend-icon name="users" class="ui-icon" />
                            </a>
                            <a class="ux-row-edit cursor-interaction" href="{{ $indexUrl }}?policy_form=edit&policy_id={{ $policy->id }}"
                               aria-label="{{ __('lf.LF_common_button_edit') }}: {{ $policy->name }}" data-tooltip="{{ __('lf.LF_common_button_edit') }}">
                                <x-backend-icon name="square-pen" class="ui-icon" />
                            </a>
                            @if ($policy->is_default || $policy->assigned_count > 0)
                                <span class="ux-row-delete is-disabled" tabindex="0" role="img"
                                      aria-label="{{ $policy->is_default ? __('lf.LF_attendance_policy_delete_default') : __('lf.LF_attendance_policy_delete_assigned') }}"
                                      data-tooltip="{{ $policy->is_default ? __('lf.LF_attendance_policy_delete_default') : __('lf.LF_attendance_policy_delete_assigned') }}" data-tooltip-align="end">
                                    <x-backend-icon name="trash" class="ui-icon" />
                                </span>
                            @else
                                <form method="POST" class="ui-cohort-row-form"
                                      action="{{ route('admin.attendance-policies.destroy', $policy->id) }}"
                                      data-lf-confirm="{{ __('lf.LF_attendance_policy_delete_confirm', ['name' => $policy->name]) }}"
                                      data-lf-confirm-tone="danger" data-lf-confirm-label="{{ __('lf.LF_common_button_delete') }}">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="ux-row-delete cursor-interaction"
                                            aria-label="{{ __('lf.LF_common_button_delete') }}: {{ $policy->name }}" data-tooltip="{{ __('lf.LF_common_button_delete') }}">
                                        <x-backend-icon name="trash" class="ui-icon" />
                                    </button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>

    @include('liveclass-attendance-policies.partials.form', [
        'dialogId' => 'attendance-policy-create',
        'policy' => null,
        'bag' => 'policyCreate',
        'autoOpen' => $createRetry,
    ])
    @if ($formMode === 'edit')
        @include('liveclass-attendance-policies.partials.form', [
            'dialogId' => 'attendance-policy-edit',
            'policy' => $formPolicy,
            'bag' => 'policyEdit',
            'autoOpen' => true,
        ])
    @elseif ($formMode === 'apply')
        @include('liveclass-attendance-policies.partials.apply', ['policy' => $formPolicy, 'cohorts' => $applyCohorts])
    @endif
</div>
@endsection
