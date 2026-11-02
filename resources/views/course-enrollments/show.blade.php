@extends('layouts.backend')

@section('title', __('lf.LF_course_enrollment_common_detail'))
@section('page_title', __('lf.LF_course_enrollment_common_detail'))

@section('page_title_hidden', '1')

@section('page_back')
    <x-page-back-link :href="route($routePrefix.'.index')" :label="__('lf.LF_course_enrollment_common_back_to_enrollments')" />
@endsection

@section('vite')
    @parent
    @vite(['resources/css/admin/admin-ui-kit.css', 'resources/js/admin-ui-kit-controls.js'])
@endsection

@section('content')
    @php
        $formatDateTime = static fn ($value): string => $value
            ? \Illuminate\Support\Carbon::parse($value)->format('d/m/Y H:i')
            : '';
        $statusTone = match ($enrollment->status) {
            'active' => 'active',
            'pending' => 'draft',
            'suspended' => 'inactive',
            'expired', 'cancelled' => 'danger',
            default => 'archived',
        };
    @endphp
<div class="lf-admin-ui lf-admin-ui--list ui-enroll">
    <x-ui-list-toasts />

    @if ($errors->has('lifecycle'))<div class="admin-alert admin-alert-danger" role="alert">{{ $errors->first('lifecycle') }}</div>@endif

    <div class="ui-enroll-head">
        <x-ui-list-head :title="__('lf.LF_course_enrollment_common_detail')" :text="__('lf.LF_course_enrollment_detail_description')" />
        <div class="ui-enroll-head__actions">
            @if (in_array($enrollment->status, ['pending', 'active', 'suspended'], true))
                <a href="{{ route($routePrefix.'.edit', $enrollment->id) }}" class="ui-button ui-button--outline cursor-interaction">
                    <x-backend-icon name="square-pen" class="ui-icon" />
                    {{ __('lf.LF_common_button_edit') }}
                </a>
            @endif
            @if ($enrollment->status === 'pending')
                @include('course-enrollments.partials.lifecycle-action', ['dialogId' => 'enrollment-activate', 'action' => route($routePrefix.'.activate', $enrollment->id), 'triggerClass' => 'ui-button ui-button--primary cursor-interaction', 'triggerLabel' => __('lf.LF_course_enrollment_lifecycle_activate'), 'title' => __('lf.LF_course_enrollment_lifecycle_activate_title'), 'body' => __('lf.LF_course_enrollment_lifecycle_activate_body'), 'confirmClass' => 'ui-button ui-button--primary cursor-interaction', 'confirmLabel' => __('lf.LF_course_enrollment_lifecycle_activate')])
            @elseif ($enrollment->status === 'active')
                @include('course-enrollments.partials.lifecycle-action', ['dialogId' => 'enrollment-suspend', 'action' => route($routePrefix.'.suspend', $enrollment->id), 'triggerClass' => 'ui-button ui-button--outline cursor-interaction', 'triggerLabel' => __('lf.LF_course_enrollment_lifecycle_suspend'), 'title' => __('lf.LF_course_enrollment_lifecycle_suspend_title'), 'body' => __('lf.LF_course_enrollment_lifecycle_suspend_body'), 'confirmClass' => 'ui-button ui-button--primary cursor-interaction', 'confirmLabel' => __('lf.LF_course_enrollment_lifecycle_suspend')])
            @elseif ($enrollment->status === 'suspended')
                @include('course-enrollments.partials.lifecycle-action', ['dialogId' => 'enrollment-reactivate', 'action' => route($routePrefix.'.reactivate', $enrollment->id), 'triggerClass' => 'ui-button ui-button--primary cursor-interaction', 'triggerLabel' => __('lf.LF_course_enrollment_lifecycle_reactivate'), 'title' => __('lf.LF_course_enrollment_lifecycle_reactivate_title'), 'body' => __('lf.LF_course_enrollment_lifecycle_reactivate_body'), 'confirmClass' => 'ui-button ui-button--primary cursor-interaction', 'confirmLabel' => __('lf.LF_course_enrollment_lifecycle_reactivate')])
            @endif
            @if (in_array($enrollment->status, ['pending', 'active', 'suspended'], true))
                @include('course-enrollments.partials.lifecycle-action', ['dialogId' => 'enrollment-cancel', 'action' => route($routePrefix.'.cancel', $enrollment->id), 'triggerClass' => 'ui-button ui-button--danger-outline cursor-interaction', 'triggerLabel' => __('lf.LF_course_enrollment_lifecycle_cancel'), 'title' => __('lf.LF_course_enrollment_lifecycle_cancel_title'), 'body' => __('lf.LF_course_enrollment_lifecycle_cancel_body'), 'confirmClass' => 'ui-button ui-button--danger-outline cursor-interaction', 'confirmLabel' => __('lf.LF_course_enrollment_lifecycle_cancel')])
            @endif
        </div>
    </div>

    @include('course-enrollments.partials.access-summary', ['headingId' => 'enrollment-show-access'])

    <section class="mk-section" aria-labelledby="enrollment-show-information">
        <div class="ui-summary-head"><h2 id="enrollment-show-information">{{ __('lf.LF_course_enrollment_information') }}</h2></div>
        <dl class="ui-summary ui-enroll-summary">
            <div class="ui-summary__item">
                <dt>{{ __('lf.LF_course_enrollment_common_enrolled_at') }}</dt>
                <dd>{{ $formatDateTime($enrollment->enrolled_at) }}</dd>
            </div>
            <div class="ui-summary__item">
                <dt>{{ __('lf.LF_course_enrollment_common_source') }}</dt>
                <dd>{{ __('lf.LF_course_enrollment_common_source_'.$enrollment->source) }}</dd>
            </div>
            <div class="ui-summary__item">
                <dt>{{ __('lf.LF_course_enrollment_common_status') }}</dt>
                <dd><span class="ui-status ui-status--{{ $statusTone }}">{{ __('lf.LF_course_enrollment_common_'.$enrollment->status) }}</span></dd>
            </div>
            <div class="ui-summary__item">
                <dt>{{ __('lf.LF_course_enrollment_access_window') }}</dt>
                <dd>
                    {{ $enrollment->access_starts_at ? $formatDateTime($enrollment->access_starts_at) : __('lf.LF_bulk_enrollment_access_immediate') }}
                    <span aria-hidden="true">→</span>
                    {{ $enrollment->access_ends_at ? $formatDateTime($enrollment->access_ends_at) : __('lf.LF_bulk_enrollment_access_unlimited') }}
                </dd>
            </div>
            <div class="ui-summary__item">
                <dt>{{ __('lf.LF_course_enrollment_review_window') }}</dt>
                <dd>
                    @if (! $enrollment->review_starts_at && ! $enrollment->review_ends_at)
                        {{ __('lf.LF_bulk_enrollment_not_configured') }}
                    @else
                        {{ $enrollment->review_starts_at ? $formatDateTime($enrollment->review_starts_at) : __('lf.LF_bulk_enrollment_not_configured') }}
                        <span aria-hidden="true">→</span>
                        {{ $enrollment->review_ends_at ? $formatDateTime($enrollment->review_ends_at) : __('lf.LF_bulk_enrollment_not_configured') }}
                    @endif
                </dd>
            </div>
            @if (filled($enrollment->notes))
                <div class="ui-summary__item ui-summary__item--wide">
                    <dt>{{ __('lf.LF_course_enrollment_common_notes') }}</dt>
                    <dd class="ui-enroll-notes">{{ $enrollment->notes }}</dd>
                </div>
            @endif
        </dl>
    </section>
</div>
@endsection
