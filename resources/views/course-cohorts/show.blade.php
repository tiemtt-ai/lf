@extends('layouts.backend')

@section('title', $cohort->status === 'draft' ? __('lf.LF_course_cohort_setup_title') : ($cohort->status === 'active' ? __('lf.LF_course_cohort_management_title') : __('lf.LF_course_cohort_common_detail')))
@section('page_title', $cohort->status === 'draft' ? __('lf.LF_course_cohort_setup_title') : ($cohort->status === 'active' ? __('lf.LF_course_cohort_management_title') : __('lf.LF_course_cohort_common_detail')))

@section('content')
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

    <div x-data="{ lockedReason: '' }">
        <nav class="admin-form-actions" aria-label="{{ __('lf.LF_course_cohort_common_tabs') }}">
            @foreach ($cohortTabs as $tab)
                <span class="sr-only">{{ $tab['note'] }}</span>
                @if ($tab['accessible'])
                    <a @class(['btn', 'btn-primary' => $activeTab === $tab['key'], 'btn-secondary' => $activeTab !== $tab['key']])
                       href="{{ $tab['route'] }}" @if($activeTab === $tab['key']) aria-current="page" @endif>
                        {{ $tab['label'] }}
                        @if ($tab['key'] === 'students') ({{ $activeMembershipCount }}/{{ $cohort->capacity ?? '∞' }}) @endif
                        @if ($tab['read_only']) · {{ __('lf.LF_course_cohort_tab_read_only') }} @endif
                    </a>
                @else
                    <button type="button" class="btn btn-secondary" aria-disabled="true"
                            x-on:click="lockedReason = @js($tab['locked_reason'])"
                            x-on:focus="lockedReason = @js($tab['locked_reason'])">
                        <span aria-hidden="true">🔒</span> {{ $tab['label'] }}
                    </button>
                    <span class="sr-only">{{ $tab['locked_reason'] }}</span>
                @endif
            @endforeach
        </nav>
        <p class="admin-form-section-help" role="status" aria-live="polite"
           x-text="lockedReason || @js(collect($cohortTabs)->firstWhere('key', $activeTab)['note'])"></p>
    </div>

    <div class="cohort-detail-toolbar">
        <a class="cohort-detail-back" href="{{ route($routePrefix.'.index') }}">
            <span aria-hidden="true">←</span>
            {{ __('lf.LF_course_cohort_common_back_to_cohorts') }}
        </a>
        <div class="cohort-detail-action-group">
            @if (in_array($cohort->status, ['draft', 'active'], true) && $activeTab === 'overview')
                <a href="{{ route($routePrefix.'.edit', $cohort->id) }}" class="btn btn-primary">
                    {{ __('lf.LF_course_cohort_action_edit_overview') }}
                </a>
            @endif
            @if ($cohort->status === 'draft')
                @if ($activationIssues !== [])
                    <div id="cohort-activate-requirements" class="admin-alert admin-alert-danger" role="alert">
                        <strong>{{ __('lf.LF_course_cohort_activation_requirements') }}</strong>
                        <ul>@foreach ($activationIssues as $issue)<li>{{ $issue }}</li>@endforeach</ul>
                    </div>
                @endif
                @include('course-cohorts.partials.lifecycle-action', [
                    'dialogId' => 'cohort-activate',
                    'action' => route($routePrefix.'.activate', $cohort->id),
                    'triggerClass' => 'btn btn-secondary',
                    'triggerLabel' => __('lf.LF_course_cohort_action_activate'),
                    'title' => __('lf.LF_course_cohort_lifecycle_activate_title'),
                    'body' => __('lf.LF_course_cohort_lifecycle_activate_body'),
                    'confirmClass' => 'btn btn-primary',
                    'confirmLabel' => __('lf.LF_course_cohort_lifecycle_activate_confirm'),
                    'disabled' => $activationIssues !== [],
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
            <section class="admin-form-standard-section cohort-overview-identity" aria-labelledby="cohort-show-information">
                <div class="cohort-overview-heading">
                    <div>
                        <span class="cohort-overview-eyebrow">{{ __('lf.LF_course_cohort_create_group_information') }}</span>
                        <h2 id="cohort-show-information">{{ $cohort->name }}</h2>
                        <div class="cohort-overview-code" x-data="{ copied: false }">
                            <span>{{ $cohort->code ?: '—' }}</span>
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
                    <span @class(['badge', 'badge-success' => $cohort->status === 'active', 'badge-danger' => $cohort->status === 'archived'])>{{ __('lf.LF_course_cohort_common_'.$cohort->status) }}</span>
                </div>

                <dl class="cohort-overview-context">
                    <div>
                        <dt>{{ __('lf.LF_course_cohort_common_product') }}</dt>
                        <dd>
                            <strong>{{ $cohort->product_title ?: '—' }}</strong>
                            @if ($cohort->product_code)<span>{{ $cohort->product_code }} · {{ __('lf.LF_course_cohort_common_locked') }}</span>@endif
                        </dd>
                    </div>
                    <div>
                        <dt>{{ __('lf.LF_course_cohort_create_content_version') }}</dt>
                        <dd>
                            @if ($cohort->version_id)
                                <strong>{{ str_replace(':code', $cohort->version_code, __('lf.LF_course_cohort_create_version_prefix')) }}</strong>
                                <span>{{ __('lf.LF_course_cohort_common_published') }} · {{ __('lf.LF_course_cohort_create_lesson_count', ['count' => (int) $cohort->lesson_count]) }} · {{ __('lf.LF_course_cohort_create_activity_count', ['count' => (int) $cohort->activity_count]) }}</span>
                            @else
                                <strong>—</strong>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt>{{ __('lf.LF_course_cohort_common_capacity') }}</dt>
                        <dd><strong>{{ $activeMembershipCount }}/{{ $cohort->capacity ?? '∞' }}</strong></dd>
                    </div>
                </dl>
            </section>

            <section class="admin-form-standard-section" aria-labelledby="cohort-show-dates">
                <header class="admin-form-section-header">
                    <h2 id="cohort-show-dates" class="admin-form-section-title">{{ __('lf.LF_course_cohort_create_group_dates') }}</h2>
                </header>
                <dl class="cohort-overview-dates">
                    <div><dt>{{ __('lf.LF_course_cohort_common_start_date') }}</dt><dd>{{ $cohort->start_date ? \Illuminate\Support\Carbon::parse($cohort->start_date)->format('d/m/Y') : '—' }}</dd></div>
                    <div><dt>{{ __('lf.LF_course_cohort_common_end_date') }}</dt><dd>{{ $cohort->end_date ? \Illuminate\Support\Carbon::parse($cohort->end_date)->format('d/m/Y') : '—' }}</dd></div>
                </dl>
            </section>

            <section class="admin-form-standard-section" aria-labelledby="cohort-show-additional">
                <header class="admin-form-section-header">
                    <h2 id="cohort-show-additional" class="admin-form-section-title">{{ __('lf.LF_course_cohort_create_group_additional') }}</h2>
                </header>
                <div class="cohort-overview-notes">
                    <span>{{ __('lf.LF_course_cohort_common_notes') }}</span>
                    <p>{{ $cohort->notes ?: __('lf.LF_course_cohort_empty_notes') }}</p>
                </div>
            </section>
        </div>
    </div>
    @elseif ($activeTab === 'students')
    <div class="admin-card admin-form-card admin-form-surface course-cohort-students-readonly"
         x-data="{
             detail: null,
             detailTrigger: null,
             openDetail(item, event) {
                 this.detail = item; this.detailTrigger = event.currentTarget;
                 this.$nextTick(() => this.$refs.detailClose.focus())
             },
             closeDetail() {
                 this.detail = null;
                 this.$nextTick(() => this.detailTrigger?.focus())
             }
         }"
         x-on:keydown.escape.window="if (detail) closeDetail()">
        <div class="admin-form-standard">
            <section class="admin-form-standard-section" aria-labelledby="cohort-show-students">
                <header class="admin-form-section-header">
                    <h2 id="cohort-show-students" class="admin-form-section-title">{{ __('lf.LF_course_cohort_student_common_title') }}</h2>
                    <p class="admin-form-section-help">{{ __('lf.LF_course_cohort_student_view_help') }}</p>
                    @if (in_array($cohort->status, ['draft', 'active'], true))
                        <a class="btn btn-primary" href="{{ route('admin.course-cohorts.students.create', $cohort->id) }}">
                            {{ __('lf.LF_course_cohort_student_common_create') }}
                        </a>
                    @endif
                </header>

                <form method="GET"
                      action="{{ route($routePrefix.'.show', $cohort->id) }}"
                      class="cohort-student-list-filter">
                    <input type="hidden" name="tab" value="students">
                    <div class="lf-form-group">
                        <label class="sr-only" for="student_keyword">{{ __('lf.LF_course_cohort_student_common_keyword') }}</label>
                        <input id="student_keyword" type="search" name="student_keyword" class="lf-form-control"
                               value="{{ $studentKeyword }}" placeholder="{{ __('lf.LF_course_cohort_student_search_placeholder') }}">
                    </div>
                    <div class="admin-form-actions cohort-student-list-filter__actions">
                        <button type="submit" class="btn btn-primary">{{ __('lf.LF_common_button_search') }}</button>
                        @if ($studentKeyword !== '')
                            <a class="admin-text-action" href="{{ route($routePrefix.'.show', ['id' => $cohort->id, 'tab' => 'students']) }}">{{ __('lf.LF_course_cohort_student_common_clear_filters') }}</a>
                        @endif
                    </div>
                </form>

                <div class="admin-table-wrap cohort-student-list-table-wrap">
                    <table class="table cohort-student-list-table">
                        <thead><tr>
                            <th>{{ __('lf.LF_course_cohort_student_common_student') }}</th>
                            <th>{{ __('lf.LF_course_cohort_student_common_enrollment') }}</th>
                            <th class="cohort-student-list-actions">{{ __('lf.table_actions') }}</th>
                        </tr></thead>
                        <tbody>
                        @forelse ($students as $student)
                            <tr>
                                <td data-label="{{ __('lf.LF_course_cohort_student_common_student') }}">
                                    <strong class="course-cohort-index-primary">{{ $student->student_name }}</strong>
                                    <span class="course-cohort-index-meta">{{ $student->student_email }}</span>
                                </td>
                                <td data-label="{{ __('lf.LF_course_cohort_student_common_enrollment') }}">
                                    <strong class="cohort-student-enrollment-code">ENR-{{ str_pad((string) $student->enrollment_id, 6, '0', STR_PAD_LEFT) }}</strong>
                                    <span class="course-cohort-index-meta">
                                        {{ __('lf.LF_course_cohort_student_joined_short') }}:
                                        {{ \Illuminate\Support\Carbon::parse($student->joined_at)->format('d/m/Y H:i') }}
                                    </span>
                                </td>
                                <td class="cohort-student-list-actions" data-label="{{ __('lf.table_actions') }}">
                                    <div class="admin-table-actions">
                                        <button type="button"
                                                class="admin-text-action"
                                                x-on:click="openDetail(@js([
                                                    'id' => $student->enrollment_id,
                                                    'name' => $student->student_name,
                                                    'email' => $student->student_email,
                                                    'code' => 'ENR-'.str_pad((string) $student->enrollment_id, 6, '0', STR_PAD_LEFT),
                                                    'status' => $student->enrollment_status,
                                                    'status_label' => __('lf.LF_course_enrollment_common_'.$student->enrollment_status),
                                                    'source_label' => __('lf.LF_course_enrollment_common_source_'.$student->enrollment_source),
                                                    'enrolled_at' => $student->enrolled_at ? \Illuminate\Support\Carbon::parse($student->enrolled_at)->format('d/m/Y H:i') : '—',
                                                    'access_starts_at' => $student->access_starts_at ? \Illuminate\Support\Carbon::parse($student->access_starts_at)->format('d/m/Y H:i') : '—',
                                                    'access_ends_at' => $student->access_ends_at ? \Illuminate\Support\Carbon::parse($student->access_ends_at)->format('d/m/Y H:i') : '—',
                                                    'review_starts_at' => $student->review_starts_at ? \Illuminate\Support\Carbon::parse($student->review_starts_at)->format('d/m/Y H:i') : '—',
                                                    'review_ends_at' => $student->review_ends_at ? \Illuminate\Support\Carbon::parse($student->review_ends_at)->format('d/m/Y H:i') : '—',
                                                    'detail_url' => route('admin.course-enrollments.show', $student->enrollment_id),
                                                    'current' => true,
                                                ]), $event)">
                                            {{ __('lf.LF_course_cohort_student_view_enrollment') }}
                                        </button>
                                        @if (in_array($cohort->status, ['draft', 'active'], true))
                                            <a class="admin-text-action" href="{{ route('admin.course-cohort-students.edit', $student->membership_id) }}">
                                                {{ __('lf.LF_course_cohort_student_manage_heading') }}
                                            </a>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr class="cohort-student-list-empty-row">
                                <td class="cohort-student-list-empty-cell" colspan="3">
                                    <div class="course-cohort-empty-state" role="status">
                                        <strong>{{ $studentKeyword !== '' ? __('lf.LF_course_cohort_student_filter_empty') : __('lf.LF_course_cohort_student_class_empty') }}</strong>
                                        <span>{{ $studentKeyword !== '' ? __('lf.LF_course_cohort_student_filter_empty_help') : __('lf.LF_course_cohort_student_class_empty_help') }}</span>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($students->hasPages())
                    <div class="admin-pagination">{{ $students->links() }}</div>
                @endif
            </section>
        </div>
        @include('course-cohorts.partials.enrollment-quick-view', ['cohort' => $cohort])
    </div>
    @elseif ($activeTab === 'teachers')
        @include('course-cohorts.partials.tabs.teachers')
    @elseif ($activeTab === 'sessions')
        @include('course-cohorts.partials.tabs.sessions')
    @elseif ($activeTab === 'attendance')
        @include('course-cohorts.partials.tabs.attendance')
    @elseif ($activeTab === 'recordings')
        @include('course-cohorts.partials.tabs.recordings')
    @endif
@endsection
