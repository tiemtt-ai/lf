@extends('layouts.backend')

@section('title', __('lf.LF_course_cohort_common_detail'))
@section('page_title', __('lf.LF_course_cohort_common_detail'))

@section('content')
    @if (session('success'))
        <div class="admin-alert admin-alert-success">
            {{ session('success') }}
        </div>
    @endif

    @if (!$cohort->product_id || !$cohort->version_id)
        <div class="admin-alert admin-alert-danger" role="alert">
            {{ __('lf.LF_course_cohort_common_configuration_required') }}
        </div>
    @endif

    <nav class="admin-form-actions" aria-label="{{ __('lf.LF_course_cohort_common_tabs') }}">
        <a class="btn btn-primary" href="#overview">{{ __('lf.LF_course_cohort_tab_overview') }}</a>
        <a class="btn btn-secondary" href="{{ route('admin.course-cohort-students.index', ['cohort_id' => $cohort->id]) }}">
            {{ __('lf.LF_course_cohort_tab_students') }} ({{ $activeMembershipCount }}/{{ $cohort->capacity ?? '∞' }})
        </a>
    </nav>

    <div class="cohort-detail-toolbar">
        <a class="cohort-detail-back" href="{{ route($routePrefix.'.index') }}">
            <span aria-hidden="true">←</span>
            {{ __('lf.LF_course_cohort_common_back_to_cohorts') }}
        </a>
        <div class="cohort-detail-action-group">
            @if ($cohort->status === 'draft')
                <form method="POST" action="{{ route($routePrefix.'.transition', $cohort->id) }}">
                    @csrf
                    <input type="hidden" name="status" value="active">
                    <button type="submit" class="btn btn-secondary">{{ __('lf.LF_course_cohort_action_activate') }}</button>
                </form>
            @elseif ($cohort->status === 'active')
                <form method="POST" action="{{ route($routePrefix.'.transition', $cohort->id) }}">
                    @csrf
                    <input type="hidden" name="status" value="completed">
                    <button type="submit" class="btn btn-secondary">{{ __('lf.LF_course_cohort_action_complete') }}</button>
                </form>
            @endif
            @if (in_array($cohort->status, ['draft', 'active'], true))
                <a href="{{ route($routePrefix.'.edit', $cohort->id) }}" class="btn btn-primary">
                    {{ __('lf.LF_course_cohort_action_edit') }}
                </a>
            @endif
            @if ($cohort->status !== 'archived')
                <form method="POST" action="{{ route($routePrefix.'.archive', $cohort->id) }}">
                    @csrf
                    <button type="submit" class="admin-danger-text-action"
                            onclick="return confirm('{{ __('lf.LF_course_cohort_common_archive_confirm') }}')">
                        {{ __('lf.LF_course_cohort_common_archive') }}
                    </button>
                </form>
            @endif
        </div>
    </div>

    <div id="overview" class="admin-card admin-form-card admin-form-surface">
        <div class="admin-form-standard">
            <section class="admin-form-standard-section" aria-labelledby="cohort-show-information">
                <header class="admin-form-section-header">
                    <h2 id="cohort-show-information" class="admin-form-section-title">{{ __('lf.LF_course_cohort_create_group_information') }}</h2>
                </header>
                <div class="admin-form-field-grid">
                    <div class="lf-form-group admin-form-field">
                        <span class="lf-form-label">{{ __('lf.LF_course_cohort_common_code') }}</span>
                        <div class="cohort-edit-readonly-row" x-data="{ copied: false }">
                            <strong class="cohort-edit-readonly-value">{{ $cohort->code ?: '—' }}</strong>
                            @if ($cohort->code)
                                <button type="button" class="cohort-edit-copy-action"
                                        x-bind:aria-label="copied ? @js(__('lf.LF_course_cohort_edit_copied')) : @js(__('lf.LF_course_cohort_edit_copy_code'))"
                                        x-on:click="navigator.clipboard.writeText(@js($cohort->code)).then(() => { copied = true; setTimeout(() => copied = false, 1600) })">
                                    <span x-show="!copied">{{ __('lf.LF_course_cohort_edit_copy') }}</span>
                                    <span x-cloak x-show="copied">{{ __('lf.LF_course_cohort_edit_copied') }}</span>
                                </button>
                            @endif
                        </div>
                    </div>
                    <div class="lf-form-group admin-form-field">
                        <span class="lf-form-label">{{ __('lf.LF_course_cohort_common_status') }}</span>
                        <div class="cohort-edit-readonly-row">
                            <span @class(['badge', 'badge-success' => $cohort->status === 'active', 'badge-danger' => $cohort->status === 'archived'])>{{ __('lf.LF_course_cohort_common_'.$cohort->status) }}</span>
                        </div>
                    </div>
                    <div class="lf-form-group admin-form-field">
                        <span class="lf-form-label">{{ __('lf.LF_course_cohort_common_product') }}</span>
                        <div class="admin-form-calculated-summary">
                            <strong class="admin-form-calculated-summary-value">{{ $cohort->product_title ?: '—' }}</strong>
                            @if ($cohort->product_code)<span class="admin-form-calculated-summary-meta">{{ $cohort->product_code }} · {{ __('lf.LF_course_cohort_common_locked') }}</span>@endif
                        </div>
                    </div>
                    <div class="lf-form-group admin-form-field">
                        <span class="lf-form-label">{{ __('lf.LF_course_cohort_create_content_version') }}</span>
                        <div class="admin-form-calculated-summary">
                            @if ($cohort->version_id)
                                <div class="admin-form-calculated-summary-content">
                                    <strong class="admin-form-calculated-summary-value">{{ str_replace(':code', $cohort->version_code, __('lf.LF_course_cohort_create_version_prefix')) }}</strong>
                                    <span class="admin-form-calculated-summary-meta admin-form-calculated-summary-meta-row">
                                        <span class="admin-form-calculated-summary-meta-item">{{ __('lf.LF_course_cohort_common_published') }}</span>
                                        <span class="admin-form-calculated-summary-meta-item">{{ __('lf.LF_course_cohort_create_lesson_count', ['count' => (int) $cohort->lesson_count]) }}</span>
                                        <span class="admin-form-calculated-summary-meta-item">{{ __('lf.LF_course_cohort_create_activity_count', ['count' => (int) $cohort->activity_count]) }}</span>
                                    </span>
                                </div>
                            @else
                                <span class="admin-form-calculated-summary-meta">—</span>
                            @endif
                        </div>
                    </div>
                    <div class="lf-form-group admin-form-field">
                        <span class="lf-form-label">{{ __('lf.LF_course_cohort_common_name') }}</span>
                        <div class="cohort-edit-readonly-stack"><strong class="cohort-edit-readonly-value">{{ $cohort->name }}</strong></div>
                    </div>
                    <div class="lf-form-group admin-form-field">
                        <span class="lf-form-label">{{ __('lf.LF_course_cohort_common_capacity') }}</span>
                        <div class="cohort-edit-readonly-stack"><strong class="cohort-edit-readonly-value">{{ $cohort->capacity ?? '—' }}</strong></div>
                    </div>
                </div>
            </section>

            <section class="admin-form-standard-section" aria-labelledby="cohort-show-dates">
                <header class="admin-form-section-header">
                    <h2 id="cohort-show-dates" class="admin-form-section-title">{{ __('lf.LF_course_cohort_create_group_dates') }}</h2>
                </header>
                <div class="admin-form-field-grid">
                    <div class="lf-form-group admin-form-field"><span class="lf-form-label">{{ __('lf.LF_course_cohort_common_start_date') }}</span><div class="cohort-edit-readonly-stack"><strong class="cohort-edit-readonly-value">{{ $cohort->start_date ?: '—' }}</strong></div></div>
                    <div class="lf-form-group admin-form-field"><span class="lf-form-label">{{ __('lf.LF_course_cohort_common_end_date') }}</span><div class="cohort-edit-readonly-stack"><strong class="cohort-edit-readonly-value">{{ $cohort->end_date ?: '—' }}</strong></div></div>
                </div>
            </section>

            <section class="admin-form-standard-section" aria-labelledby="cohort-show-additional">
                <header class="admin-form-section-header">
                    <h2 id="cohort-show-additional" class="admin-form-section-title">{{ __('lf.LF_course_cohort_create_group_additional') }}</h2>
                </header>
                <div class="admin-form-field-grid">
                    <div class="lf-form-group admin-form-field admin-form-field--full"><span class="lf-form-label">{{ __('lf.LF_course_cohort_common_notes') }}</span><div class="cohort-show-notes">{{ $cohort->notes ?: '—' }}</div></div>
                </div>
            </section>
        </div>
    </div>
@endsection
