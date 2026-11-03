@extends('layouts.backend')

@section('title', $cohort->status === 'draft' ? __('lf.LF_course_cohort_setup_title') : ($cohort->status === 'active' ? __('lf.LF_course_cohort_management_title') : __('lf.LF_course_cohort_common_detail')))
@section('page_title', $cohort->status === 'draft' ? __('lf.LF_course_cohort_setup_title') : ($cohort->status === 'active' ? __('lf.LF_course_cohort_management_title') : __('lf.LF_course_cohort_common_detail')))

@section('page_title_hidden', '1')

@section('page_back')
    <x-page-back-link :href="route($routePrefix.'.index')" :label="__('lf.LF_course_cohort_common_back_to_cohorts')" />
@endsection

@section('vite')
    @parent
    @vite(['resources/css/admin/admin-ui-kit.css', 'resources/js/admin-ui-kit-controls.js'])
@endsection

@section('content')
<div class="lf-admin-ui lf-admin-ui--list ui-cohort">
    <x-ui-list-toasts />

    @if ($errors->has('lifecycle'))
        <div class="admin-alert admin-alert-danger" role="alert">{{ $errors->first('lifecycle') }}</div>
    @endif

    @if (!$cohort->product_id || !$cohort->version_id)
        <div class="admin-alert admin-alert-danger" role="alert">{{ __('lf.LF_course_cohort_common_configuration_required') }}</div>
    @endif

    @include('course-cohorts.partials.identity', ['identityActions' => 'course-cohorts.partials.identity-actions'])

    @if ($cohort->status === 'draft' && $activationIssues !== [])
        <div id="cohort-activate-requirements" class="ui-cohort-alert" role="alert">
            <x-backend-icon name="circle-alert" class="ui-icon" />
            <div>
                <strong>{{ __('lf.LF_course_cohort_activation_requirements') }}</strong>
                <ul>@foreach ($activationIssues as $issue)<li>{{ $issue }}</li>@endforeach</ul>
            </div>
        </div>
    @endif

    @include('course-cohorts.partials.page-tabs', ['activeKey' => $activeTab])

    @if ($activeTab === 'overview')
        <section id="overview" class="mk-section course-cohort-detail" aria-labelledby="cohort-show-information">
            <div class="ui-summary-head"><h2 id="cohort-show-information">{{ __('lf.LF_course_cohort_create_group_information') }}</h2></div>
            <dl class="ui-summary ui-enroll-summary">
                @include('course-cohorts.partials.context-facts')
                <div class="ui-summary__item">
                    <dt>{{ __('lf.LF_course_cohort_overview_students') }}</dt>
                    <dd>
                        {{ trans_choice('lf.LF_course_cohort_overview_students_count', $activeMembershipCount, ['current' => $activeMembershipCount]) }}
                        @if ($cohort->capacity)
                            <span class="ui-caption ui-framework-description">{{ __('lf.LF_course_cohort_overview_students_limit', ['capacity' => $cohort->capacity]) }}</span>
                            <span class="ui-cohort-capacity" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $cohort->capacity }}" aria-valuenow="{{ $activeMembershipCount }}"
                                  aria-label="{{ __('lf.LF_course_cohort_student_capacity_summary', ['current' => $activeMembershipCount, 'capacity' => $cohort->capacity]) }}">
                                <span style="width: {{ min(100, round(($activeMembershipCount / $cohort->capacity) * 100)) }}%"></span>
                            </span>
                        @else
                            <span class="ui-caption ui-framework-description">{{ __('lf.LF_course_cohort_student_capacity_unlimited') }}</span>
                        @endif
                    </dd>
                </div>
                @if (filled($cohort->notes))
                    <div class="ui-summary__item ui-summary__item--wide">
                        <dt>{{ __('lf.LF_course_cohort_common_notes') }}</dt>
                        <dd class="ui-enroll-notes">{{ $cohort->notes }}</dd>
                    </div>
                @endif
            </dl>
        </section>

        <section class="mk-section ui-cohort-dates" aria-labelledby="cohort-show-dates">
            <div class="ui-summary-head"><h2 id="cohort-show-dates">{{ __('lf.LF_course_cohort_create_group_dates') }}</h2></div>
            <dl class="ui-summary ui-enroll-summary">
                <div class="ui-summary__item">
                    <dt>{{ __('lf.LF_course_cohort_common_start_date') }}</dt>
                    <dd>{{ $cohort->start_date ? \Illuminate\Support\Carbon::parse($cohort->start_date)->format('d/m/Y') : '—' }}</dd>
                </div>
                <div class="ui-summary__item">
                    <dt>{{ __('lf.LF_course_cohort_common_end_date') }}</dt>
                    <dd>{{ $cohort->end_date ? \Illuminate\Support\Carbon::parse($cohort->end_date)->format('d/m/Y') : '—' }}</dd>
                </div>
                @include('course-cohorts.partials.product-registration-window', ['registrationWindowTitleId' => 'cohort-show-product-registration-window'])
            </dl>
        </section>
    @elseif ($activeTab === 'students')
    <section class="mk-section course-cohort-students-readonly" aria-labelledby="cohort-show-students"
         x-data="{
             formOpen: @js($errors->hasAny(['enrollment_ids', 'enrollment_ids.*', 'roster_snapshot']) || request()->boolean('manage')),
             detail: null,
             detailTrigger: null,
             openDetail(item, event) {
                 this.detail = item; this.detailTrigger = event.currentTarget;
                 this.$refs.detailDialog.showModal()
             },
             closeDetail() {
                 this.$refs.detailDialog.close()
             },
             detailClosed() {
                 this.detail = null;
                 this.$nextTick(() => this.detailTrigger?.focus())
             }
         }"
         x-init="$watch('formOpen', open => { const dialog = $refs.manageDialog; if (! dialog) return; open ? dialog.showModal() : dialog.close() }); if (formOpen) $refs.manageDialog?.showModal()">
        <div class="ui-summary-head">
            <h2 id="cohort-show-students">{{ __('lf.LF_course_cohort_student_view_title') }}</h2>
        </div>

        @if ($cohort->is_mutable)
            @include('course-cohorts.partials.tabs.student-manager')
        @endif

        @if ($cohort->is_mutable && $cancelledSeatCount > 0)
            <div class="ui-banner ui-cohort-seat-warning" role="status">
                <x-backend-icon name="circle" class="ui-icon ui-banner__icon" />
                <span class="ui-banner__text">{{ trans_choice('lf.LF_course_cohort_student_cancelled_seat_warning', $cancelledSeatCount, ['count' => $cancelledSeatCount]) }}</span>
            </div>
        @endif

        <div class="mk-tablebar mk-tablebar--filters ui-enroll-filterbar">
            <form method="GET" action="{{ route($routePrefix.'.show', $cohort->id) }}" class="mk-filter-form cohort-student-list-filter">
                <div class="ui-field mk-search">
                    <label class="ui-field__label ui-sr-only" for="student_keyword">{{ __('lf.LF_course_cohort_student_common_keyword') }}</label>
                    <div class="ui-search">
                        <input id="student_keyword" type="search" name="student_keyword" value="{{ $studentKeyword }}" placeholder="{{ __('lf.LF_course_cohort_student_search_placeholder') }}">
                        <button type="submit" class="ui-button cursor-interaction" aria-label="{{ __('lf.LF_common_button_search') }}">
                            <svg class="ui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><circle cx="11" cy="11" r="8" /><path d="m21 21-4.3-4.3" /></svg>
                        </button>
                    </div>
                </div>
                @if ($studentKeyword !== '')
                    <a class="ui-button ui-button--outline cursor-interaction" href="{{ route($routePrefix.'.show', ['id' => $cohort->id, 'tab' => 'students']) }}">{{ __('lf.LF_course_cohort_student_common_clear_filters') }}</a>
                @endif
                <input type="hidden" name="tab" value="students">
            </form>
            <div class="mk-table-top__actions cohort-student-list-heading-actions">
                @if ($cohort->is_mutable)
                    <button type="button" class="ui-button ui-button--outline cursor-interaction"
                            aria-haspopup="dialog" x-on:click="formOpen = true">
                        {{ __('lf.LF_course_cohort_student_manage_heading') }}
                    </button>
                @endif
            </div>
        </div>

        <div class="mk-table-scroll" role="region" aria-labelledby="cohort-show-students" tabindex="0">
            <table class="ui-cohort-roster-table">
                <colgroup><col class="mk-col-sequence"><col><col><col class="mk-col-status"><col class="mk-col-action"></colgroup>
                <thead><tr>
                    <th scope="col" class="mk-sequence">{{ __('lf.table_no') }}</th>
                    <th scope="col" class="mk-text-cell">{{ __('lf.LF_course_cohort_student_common_student') }}</th>
                    <th scope="col" class="mk-text-cell">{{ __('lf.LF_course_cohort_student_common_enrollment') }}</th>
                    <th scope="col" class="ux-action-cell">{{ __('lf.LF_course_cohort_student_common_status') }}</th>
                    <th scope="col" class="ux-action-cell">{{ __('lf.table_actions') }}</th>
                </tr></thead>
                <tbody>
                @forelse ($students as $student)
                    @php($studentTone = match ($student->enrollment_status) { 'active' => 'active', 'pending' => 'draft', 'suspended' => 'inactive', 'expired', 'cancelled' => 'danger', default => 'archived' })
                    <tr>
                        <td class="mk-sequence" data-label="{{ __('lf.table_no') }}">{{ $students->firstItem() + $loop->index }}</td>
                        <td class="mk-text-cell" data-label="{{ __('lf.LF_course_cohort_student_common_student') }}">
                            <span class="mk-name-text">{{ $student->student_name }}</span>
                            <span class="ui-caption ui-framework-description">{{ $student->student_email }}</span>
                        </td>
                        <td class="mk-text-cell" data-label="{{ __('lf.LF_course_cohort_student_common_enrollment') }}">
                            <span class="mk-name-text">ENR-{{ str_pad((string) $student->enrollment_id, 6, '0', STR_PAD_LEFT) }}</span>
                            <span class="ui-caption ui-framework-description">{{ __('lf.LF_course_cohort_student_joined_short') }}: {{ \Illuminate\Support\Carbon::parse($student->joined_at)->format('d/m/Y H:i') }}</span>
                        </td>
                        <td class="ux-action-cell" data-label="{{ __('lf.LF_course_cohort_student_common_status') }}">
                            <span class="ui-status ui-status--{{ $studentTone }}">{{ __('lf.LF_course_enrollment_common_'.$student->enrollment_status) }}</span>
                            @if ($cohort->is_mutable && $student->enrollment_status === 'cancelled')
                                <span class="ui-caption ui-cohort-seat-note"><x-backend-icon name="info" class="ui-icon" />{{ __('lf.LF_course_cohort_student_holds_seat') }}</span>
                            @endif
                        </td>
                        <td class="ux-action-cell" data-label="{{ __('lf.table_actions') }}">
                            <button type="button" class="ux-row-edit cursor-interaction"
                                    aria-label="{{ __('lf.LF_course_cohort_student_view_enrollment') }}: {{ $student->student_name }}"
                                    data-tooltip="{{ __('lf.LF_course_cohort_student_view_enrollment') }}"
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
                                <x-backend-icon name="eye" class="ui-icon" />
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr class="mk-empty-row">
                        <td class="mk-empty" colspan="5">
                            <div role="status">
                                <strong>{{ $studentKeyword !== '' ? __('lf.LF_course_cohort_student_filter_empty') : __('lf.LF_course_cohort_student_class_empty') }}</strong>
                                <p class="ui-caption">{{ $studentKeyword !== '' ? __('lf.LF_course_cohort_student_filter_empty_help') : __('lf.LF_course_cohort_student_class_empty_help') }}</p>
                            </div>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        @if ($students->hasPages())
            <x-ui-list-foot :paginator="$students"
                :label="__('lf.LF_course_cohort_student_view_title')"
                :page-size-label="__('lf.LF_course_template_index_page_size')"
                :page-size-option="__('lf.LF_course_template_index_page_size_option', ['count' => $students->perPage()])" />
        @endif

        @include('course-cohorts.partials.enrollment-quick-view', ['cohort' => $cohort])
    </section>
    @elseif ($activeTab === 'teachers')
        @include('course-cohorts.partials.tabs.teachers')
    @elseif ($activeTab === 'schedules')
        @include('course-cohorts.partials.tabs.schedules')
    @elseif ($activeTab === 'sessions')
        @include('course-cohorts.partials.tabs.sessions')
    @elseif ($activeTab === 'attendance')
        @include('course-cohorts.partials.tabs.attendance')
    @elseif ($activeTab === 'recordings')
        @include('course-cohorts.partials.tabs.recordings')
    @endif
</div>
@endsection
