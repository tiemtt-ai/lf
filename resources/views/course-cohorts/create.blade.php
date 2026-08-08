@extends('layouts.backend')

@section('title', __('lf.LF_course_cohort_common_create'))
@section('page_title', __('lf.LF_course_cohort_common_create'))

@section('content')
    <div x-data="{ lockedReason: '' }">
        <nav class="admin-form-actions course-cohort-detail-tabs" aria-label="{{ __('lf.LF_course_cohort_common_tabs') }}">
            @foreach ($cohortTabs as $tab)
                <span class="sr-only">{{ $tab['note'] }}</span>
                @if ($tab['accessible'])
                    <button type="button" class="btn btn-primary" aria-current="page">{{ $tab['label'] }}</button>
                @else
                    <button type="button" class="btn btn-secondary course-cohort-detail-tab--locked" aria-disabled="true"
                            title="{{ $tab['locked_reason'] }}"
                            x-on:click="lockedReason = @js($tab['locked_reason'])"
                            x-on:focus="lockedReason = @js($tab['locked_reason'])">
                        <span>{{ $tab['label'] }}</span>
                        <x-backend-icon name="lock" class="course-cohort-detail-tab__lock-icon" />
                    </button>
                    <span class="sr-only">{{ $tab['locked_reason'] }}</span>
                @endif
            @endforeach
        </nav>
        <p class="admin-form-section-help course-cohort-detail-tabs-help" role="status" aria-live="polite">
            <span class="course-cohort-detail-tabs-help__icon" aria-hidden="true">i</span>
            <span x-text="lockedReason || @js($cohortTabs[0]['note'])"></span>
        </p>
    </div>

    @if ($errors->any())
        <div class="admin-alert admin-alert-danger admin-form-card">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="admin-card admin-form-card admin-form-surface course-cohort-create">
        <form class="admin-form-standard"
              method="POST"
              action="{{ route($routePrefix.'.store') }}"
              x-data="{ submitting: false }"
              x-on:submit="submitting = true">
            @csrf

            @include('course-cohorts.partials.create-form')
        </form>
    </div>
@endsection
