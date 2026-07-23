@extends('layouts.backend')

@section('title', __('lf.LF_course_cohort_common_detail'))
@section('page_title', __('lf.LF_course_cohort_common_detail'))

@section('content')
    @php($activeTab = request('tab') === 'students' ? 'students' : 'overview')
    @if (session('success'))
        <div class="admin-alert admin-alert-success">
            {{ session('success') }}
        </div>
    @endif

    @if ($errors->has('lifecycle'))
        <div class="admin-alert admin-alert-danger" role="alert">
            {{ $errors->first('lifecycle') }}
        </div>
    @endif

    @if (!$cohort->product_id || !$cohort->version_id)
        <div class="admin-alert admin-alert-danger" role="alert">
            {{ __('lf.LF_course_cohort_common_configuration_required') }}
        </div>
    @endif

    <nav class="admin-form-actions" aria-label="{{ __('lf.LF_course_cohort_common_tabs') }}">
        <a @class(['btn', 'btn-primary' => $activeTab === 'overview', 'btn-secondary' => $activeTab !== 'overview'])
           href="{{ route($routePrefix.'.show', $cohort->id) }}" @if($activeTab === 'overview') aria-current="page" @endif>
            {{ __('lf.LF_course_cohort_tab_overview') }}
        </a>
        <a @class(['btn', 'btn-primary' => $activeTab === 'students', 'btn-secondary' => $activeTab !== 'students'])
           href="{{ route($routePrefix.'.show', ['id' => $cohort->id, 'tab' => 'students']) }}" @if($activeTab === 'students') aria-current="page" @endif>
            {{ __('lf.LF_course_cohort_tab_students') }} ({{ $activeMembershipCount }}/{{ $cohort->capacity ?? '∞' }})
        </a>
    </nav>

    <div class="cohort-detail-toolbar">
        <a class="cohort-detail-back" href="{{ route($routePrefix.'.index') }}">
            <span aria-hidden="true">←</span>
            {{ __('lf.LF_course_cohort_common_back_to_cohorts') }}
        </a>
        <div class="cohort-detail-action-group">
            @if (in_array($cohort->status, ['draft', 'active'], true))
                <a href="{{ route($routePrefix.'.edit', $activeTab === 'students' ? ['id' => $cohort->id, 'tab' => 'students'] : $cohort->id) }}" class="btn btn-primary">
                    {{ $activeTab === 'students' ? __('lf.LF_course_cohort_student_manage_heading') : __('lf.LF_course_cohort_action_edit') }}
                </a>
            @endif
            @if ($cohort->status === 'draft')
                @include('course-cohorts.partials.lifecycle-action', [
                    'dialogId' => 'cohort-activate',
                    'action' => route($routePrefix.'.activate', $cohort->id),
                    'triggerClass' => 'btn btn-secondary',
                    'triggerLabel' => __('lf.LF_course_cohort_action_activate'),
                    'title' => __('lf.LF_course_cohort_lifecycle_activate_title'),
                    'body' => __('lf.LF_course_cohort_lifecycle_activate_body'),
                    'confirmClass' => 'btn btn-primary',
                    'confirmLabel' => __('lf.LF_course_cohort_lifecycle_activate_confirm'),
                ])
                @include('course-cohorts.partials.lifecycle-action', [
                    'dialogId' => 'cohort-archive',
                    'action' => route($routePrefix.'.archive', $cohort->id),
                    'triggerClass' => 'admin-danger-text-action',
                    'triggerLabel' => __('lf.LF_course_cohort_common_archive'),
                    'title' => __('lf.LF_course_cohort_lifecycle_archive_title'),
                    'body' => __('lf.LF_course_cohort_lifecycle_archive_body'),
                    'confirmClass' => 'btn btn-danger',
                    'confirmLabel' => __('lf.LF_course_cohort_lifecycle_archive_confirm'),
                ])
            @elseif ($cohort->status === 'active')
                @include('course-cohorts.partials.lifecycle-action', [
                    'dialogId' => 'cohort-complete',
                    'action' => route($routePrefix.'.complete', $cohort->id),
                    'triggerClass' => 'btn btn-secondary',
                    'triggerLabel' => __('lf.LF_course_cohort_action_complete'),
                    'title' => __('lf.LF_course_cohort_lifecycle_complete_title'),
                    'body' => __('lf.LF_course_cohort_lifecycle_complete_body'),
                    'confirmClass' => 'btn btn-primary',
                    'confirmLabel' => __('lf.LF_course_cohort_lifecycle_complete_confirm'),
                ])
            @elseif ($cohort->status === 'completed')
                @include('course-cohorts.partials.lifecycle-action', [
                    'dialogId' => 'cohort-archive',
                    'action' => route($routePrefix.'.archive', $cohort->id),
                    'triggerClass' => 'admin-danger-text-action',
                    'triggerLabel' => __('lf.LF_course_cohort_common_archive'),
                    'title' => __('lf.LF_course_cohort_lifecycle_archive_title'),
                    'body' => __('lf.LF_course_cohort_lifecycle_archive_body'),
                    'confirmClass' => 'btn btn-danger',
                    'confirmLabel' => __('lf.LF_course_cohort_lifecycle_archive_confirm'),
                ])
            @endif
        </div>
    </div>

    @if ($activeTab === 'overview')
    <div id="overview" class="admin-card admin-form-card admin-form-surface course-cohort-detail">
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
    @else
    <div class="admin-card admin-form-card admin-form-surface course-cohort-students-readonly">
        <div class="admin-form-standard">
            <section class="admin-form-standard-section" aria-labelledby="cohort-show-students">
                <header class="admin-form-section-header">
                    <h2 id="cohort-show-students" class="admin-form-section-title">{{ __('lf.LF_course_cohort_student_common_title') }}</h2>
                    <p class="admin-form-section-help">{{ __('lf.LF_course_cohort_student_view_help') }}</p>
                </header>

                <form method="GET" action="{{ route($routePrefix.'.show', $cohort->id) }}" class="admin-form-stack">
                    <input type="hidden" name="tab" value="students">
                    <div class="lf-form-group admin-form-field--full">
                        <label class="lf-form-label" for="student_keyword">{{ __('lf.LF_course_cohort_student_common_keyword') }}</label>
                        <input id="student_keyword" type="search" name="student_keyword" class="lf-form-control"
                               value="{{ $studentKeyword }}" placeholder="{{ __('lf.LF_course_cohort_student_search_placeholder') }}">
                    </div>
                    <div class="admin-form-actions">
                        <button type="submit" class="btn btn-primary">{{ __('lf.LF_common_button_search') }}</button>
                        @if ($studentKeyword !== '')
                            <a href="{{ route($routePrefix.'.show', ['id' => $cohort->id, 'tab' => 'students']) }}">{{ __('lf.LF_course_cohort_student_common_clear_filters') }}</a>
                        @endif
                    </div>
                </form>

                <div class="admin-table-wrap">
                    <table class="table">
                        <thead><tr>
                            <th class="admin-table-sequence">{{ __('lf.table_no') }}</th>
                            <th>{{ __('lf.LF_course_cohort_student_common_student') }}</th>
                            <th>{{ __('lf.LF_course_cohort_student_common_enrollment') }}</th>
                            <th>{{ __('lf.LF_course_cohort_student_common_joined_at') }}</th>
                            <th>{{ __('lf.table_actions') }}</th>
                        </tr></thead>
                        <tbody>
                        @forelse ($students as $student)
                            <tr>
                                <td class="admin-table-sequence">{{ $students->firstItem() + $loop->index }}</td>
                                <td><strong>{{ $student->student_name }}</strong><br><small>{{ $student->student_email }}</small></td>
                                <td>ENR-{{ str_pad((string) $student->enrollment_id, 6, '0', STR_PAD_LEFT) }}</td>
                                <td>{{ $student->joined_at }}</td>
                                <td><a class="admin-table-action-link admin-text-action" href="{{ route('admin.course-cohort-students.show', $student->membership_id) }}">{{ __('lf.action_view') }}</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="5">{{ __('lf.LF_course_cohort_student_common_empty') }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($students->hasPages())
                    <div class="admin-pagination">{{ $students->links() }}</div>
                @endif
            </section>
        </div>
    </div>
    @endif
@endsection
