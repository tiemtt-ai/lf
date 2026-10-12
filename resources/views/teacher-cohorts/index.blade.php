@extends('layouts.backend')

@section('title', __('lf.LF_navigation_menu_teacher_cohorts'))
@section('page_title', __('lf.LF_navigation_menu_teacher_cohorts'))

@section('content')
    <div class="teacher-cohorts">
        <p class="teacher-cohorts__intro">{{ __('lf.LF_teacher_cohorts_intro') }}</p>

        @forelse ($cohorts as $cohort)
            @php
                $basis = $cohort->bases->first();
            @endphp
            <article class="admin-card teacher-cohorts__card" data-teacher-cohort="{{ $cohort->id }}">
                <div class="teacher-cohorts__main">
                    <div class="teacher-cohorts__title-row">
                        <h2 class="teacher-cohorts__title">{{ $cohort->name }}</h2>
                        <span class="badge {{ $cohort->status === 'active' ? 'badge-success' : 'badge-neutral' }}">{{ __('lf.LF_course_cohort_common_'.$cohort->status) }}</span>
                    </div>
                    <span class="teacher-cohorts__meta">
                        {{ $cohort->product_title }}
                        @if ($cohort->start_date && $cohort->end_date)
                            · {{ \Illuminate\Support\Carbon::parse($cohort->start_date)->format('d/m/Y') }} – {{ \Illuminate\Support\Carbon::parse($cohort->end_date)->format('d/m/Y') }}
                        @endif
                        · {{ trans_choice('lf.LF_teacher_cohorts_students', $cohort->student_count, ['count' => $cohort->student_count]) }}
                    </span>
                    @if ($basis)
                        <span class="teacher-cohorts__basis">
                            {{ __('lf.LF_teacher_cohorts_basis', ['framework' => $basis->framework_name, 'number' => $basis->version_number]) }}
                            <x-info-tip :text="__('lf.LF_teacher_cohorts_basis_tip')" />
                        </span>
                    @endif
                    @if ($cohort->blocked_reason)
                        <span class="teacher-cohorts__blocked">{{ __('lf.LF_teacher_cohorts_blocked_'.$cohort->blocked_reason) }}</span>
                    @endif
                </div>
                {{-- The teacher runs the class: one entry for every state; the page itself says what can be done now. --}}
                <a class="btn {{ $cohort->blocked_reason === null ? 'btn-primary' : 'btn-secondary' }}" href="{{ route('teacher.cohorts.show', $cohort->id) }}">{{ __('lf.LF_teacher_cohorts_manage') }}</a>
            </article>
        @empty
            <div class="admin-card admin-form-empty-state" role="status">
                <strong>{{ __('lf.LF_teacher_cohorts_empty') }}</strong>
                <span>{{ __('lf.LF_teacher_cohorts_empty_help') }}</span>
            </div>
        @endforelse
    </div>
@endsection
