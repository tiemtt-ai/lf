@extends('layouts.backend')

@section('title', __('lf.LF_navigation_menu_teacher_cohorts'))
@section('page_title', __('lf.LF_navigation_menu_teacher_cohorts'))

@section('page_title_hidden', '1')

@section('vite')
    @parent
    @vite(['resources/css/admin/admin-ui-kit.css', 'resources/js/admin-ui-kit-controls.js'])
@endsection

@section('content')
<div class="lf-admin-ui lf-admin-ui--list ui-teacher-cohorts">
    <x-ui-list-toasts />

    @if ($errors->has('lifecycle'))
        <div class="admin-alert admin-alert-danger" role="alert">{{ $errors->first('lifecycle') }}</div>
    @endif

    <x-ui-list-head :title="__('lf.LF_navigation_menu_teacher_cohorts')" :text="__('lf.LF_teacher_cohorts_intro')" />

    <section class="mk-section" aria-labelledby="teacher-cohorts-list-title">
        <div class="ui-summary-head">
            <h2 id="teacher-cohorts-list-title">{{ __('lf.LF_teacher_cohorts_list_title') }}</h2>
            <div class="mk-table-top__actions">
                @if ($statusCounts->count() <= 1)
                    {{-- With the status tabs, "All" carries the total. --}}
                    <span class="ui-caption ui-enroll-nowrap">{{ trans_choice('lf.LF_teacher_cohorts_count', $totalCohorts, ['count' => $totalCohorts]) }}</span>
                @endif
                @if ($mayCreateCohort)
                    <a class="ui-button ui-button--outline cursor-interaction" href="{{ route('teacher.course-cohorts.create') }}" data-teacher-cohort-create>
                        <svg class="ui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
                        {{ __('lf.LF_teacher_cohorts_create') }}
                    </a>
                @endif
            </div>
        </div>

        @if ($statusCounts->count() > 1)
            @php $indexUrl = route('teacher.cohorts.index'); @endphp
            <nav class="ui-tabs ui-teacher-cohort-filter" aria-label="{{ __('lf.LF_teacher_cohorts_filter_label') }}" data-teacher-cohort-filter>
                <a class="ui-tabs__tab cursor-interaction" href="{{ $indexUrl }}" @if ($status === null) aria-current="page" @endif>
                    {{ __('lf.LF_teacher_cohorts_filter_all') }}<span class="ui-tab-count">{{ $totalCohorts }}</span>
                </a>
                @foreach ($statusCounts as $tabStatus => $tabCount)
                    <a class="ui-tabs__tab cursor-interaction" href="{{ $indexUrl }}?status={{ $tabStatus }}" @if ($status === $tabStatus) aria-current="page" @endif>
                        {{ __('lf.LF_course_cohort_common_'.$tabStatus) }}<span class="ui-tab-count">{{ $tabCount }}</span>
                    </a>
                @endforeach
            </nav>
        @endif

        @forelse ($cohorts as $cohort)
            @php
                $basis = $cohort->bases->first();
                $isPrimary = in_array((int) $cohort->id, $primaryCohortIds, true);
            @endphp
            <article class="ui-teacher-cohort" data-teacher-cohort="{{ $cohort->id }}">
                <div class="ui-teacher-cohort__main">
                    <div class="ui-teacher-cohort__title-row">
                        <h3 class="ui-teacher-cohort__title">{{ $cohort->name }}</h3>
                        <span class="ui-status ui-status--{{ $cohort->status }}">{{ __('lf.LF_course_cohort_common_'.$cohort->status) }}</span>
                        @if ($isPrimary)
                            <span class="ui-teacher-cohort__role" data-teacher-cohort-primary>{{ __('lf.LF_teacher_cohorts_primary') }}</span>
                        @endif
                    </div>
                    <p class="ui-teacher-cohort__meta">
                        {{ $cohort->product_title }}
                        @if ($cohort->start_date && $cohort->end_date)
                            · {{ \Illuminate\Support\Carbon::parse($cohort->start_date)->format('d/m/Y') }} – {{ \Illuminate\Support\Carbon::parse($cohort->end_date)->format('d/m/Y') }}
                        @endif
                        · {{ trans_choice('lf.LF_teacher_cohorts_students', $cohort->student_count, ['count' => $cohort->student_count]) }}
                    </p>
                    @if ($basis)
                        <p class="ui-teacher-cohort__note">
                            {{ __('lf.LF_teacher_cohorts_basis', ['framework' => $basis->framework_name, 'number' => $basis->version_number]) }}
                            <x-info-tip :text="__('lf.LF_teacher_cohorts_basis_tip')" />
                        </p>
                    @endif
                    @if ($cohort->blocked_reason)
                        <p class="ui-teacher-cohort__note">{{ __('lf.LF_teacher_cohorts_blocked_'.$cohort->blocked_reason) }}</p>
                    @endif
                </div>
                <div class="ui-teacher-cohort__actions">
                    @if ($isPrimary && $mayCreateCohort)
                        <a class="ui-button cursor-interaction" href="{{ route('teacher.course-cohorts.show', $cohort->id) }}" data-teacher-cohort-manage>{{ __('lf.LF_teacher_cohorts_manage') }}</a>
                    @endif
                    @if (\App\Services\LiveClassAttendanceCheckIn::enabled())
                        <a class="ui-button cursor-interaction" href="{{ route('teacher.cohorts.attendance', $cohort->id) }}" data-teacher-attendance>{{ __('lf.LF_teacher_attendance_open') }}</a>
                    @endif
                    @if ($cohort->blocked_reason === null)
                        <a class="ui-button ui-button--primary cursor-interaction" href="{{ route('teacher.cohorts.show', $cohort->id) }}">{{ __('lf.LF_teacher_cohorts_judge') }}</a>
                    @elseif ($cohort->bases->isNotEmpty())
                        {{-- Outside the judging window the page still shows earlier results, read-only. --}}
                        <a class="ui-button cursor-interaction" href="{{ route('teacher.cohorts.show', $cohort->id) }}">{{ __('lf.LF_teacher_cohorts_view') }}</a>
                    @endif
                    @if ($isPrimary && $mayDeleteCohort && $cohort->status === 'draft')
                        <form method="POST" action="{{ route('teacher.course-cohorts.bulk-destroy') }}"
                              data-lf-confirm="{{ __('lf.LF_teacher_cohorts_delete_confirm', ['name' => $cohort->name]) }}"
                              data-lf-confirm-tone="danger" data-lf-confirm-label="{{ __('lf.LF_teacher_cohorts_delete') }}">
                            @csrf @method('DELETE')
                            <input type="hidden" name="ids[]" value="{{ $cohort->id }}">
                            <button type="submit" class="ui-button ui-button--danger-outline cursor-interaction" data-teacher-cohort-delete>{{ __('lf.LF_teacher_cohorts_delete') }}</button>
                        </form>
                    @endif
                </div>
            </article>
        @empty
            <div class="ui-teacher-cohort-empty" role="status">
                <strong>{{ __('lf.LF_teacher_cohorts_empty') }}</strong>
                <p class="ui-caption">{{ __('lf.LF_teacher_cohorts_empty_help') }}</p>
            </div>
        @endforelse
    </section>
</div>
@endsection
