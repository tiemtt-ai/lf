@extends('layouts.backend')

@section('title', __('lf.LF_course_cohort_common_edit'))
@section('page_title', __('lf.LF_course_cohort_common_edit'))

@section('content')
    @if (session('success'))
        <div class="admin-alert admin-alert-success">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div class="admin-alert admin-alert-danger admin-form-card">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="course-cohort-edit-navigation">
        <a class="cohort-detail-back" href="{{ route($routePrefix.'.show', $cohort->id) }}">
            <span aria-hidden="true">←</span>
            {{ __('lf.LF_course_cohort_common_back_to_detail') }}
        </a>
    </div>

    <div x-data="{ lockedReason: '' }">
        <nav class="admin-form-actions course-cohort-detail-tabs course-cohort-edit-tabs" aria-label="{{ __('lf.LF_course_cohort_common_tabs') }}">
            @foreach ($cohortTabs as $tab)
                <span class="sr-only">{{ $tab['note'] }}</span>
                @php($editRoute = $tab['key'] === 'overview'
                    ? route($routePrefix.'.edit', $cohort->id)
                    : $tab['route'])
                @if ($tab['accessible'])
                    <a @class(['btn', 'btn-primary' => $tab['key'] === 'overview', 'btn-secondary' => $tab['key'] !== 'overview'])
                       href="{{ $editRoute }}" @if($tab['key'] === 'overview') aria-current="page" @endif>
                        {{ $tab['label'] }} @if($tab['read_only']) · {{ __('lf.LF_course_cohort_tab_read_only') }} @endif
                    </a>
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
            <span x-text="lockedReason || @js(collect($cohortTabs)->firstWhere('key', 'overview')['note'])"></span>
        </p>
    </div>

    <div class="admin-card admin-form-card admin-form-surface course-cohort-edit-overview">
        <form method="POST" action="{{ route($routePrefix.'.update', $cohort->id) }}"
              class="admin-form-standard" x-data="{ submitting: false }" x-on:submit="submitting = true">
            @csrf
            @method('PUT')

            @include('course-cohorts.partials.form', [
                'cohort' => $cohort,
                'submitLabel' => __('lf.LF_common_button_save_changes'),
            ])
        </form>
    </div>
@endsection
