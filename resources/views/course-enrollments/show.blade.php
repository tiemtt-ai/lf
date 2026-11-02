@extends('layouts.backend')

@section('title', __('lf.LF_course_enrollment_common_detail'))
@section('page_title', __('lf.LF_course_enrollment_common_detail'))

@section('page_title_hidden', '1')

@section('page_back')
    <x-page-back-link :href="route($routePrefix.'.index')" :label="__('lf.LF_course_enrollment_common_back_to_enrollments')" />
@endsection

@section('vite')
    @parent
    @vite(['resources/css/admin/admin-ui-kit.css', 'resources/js/admin-ui-kit-controls.js', 'resources/js/admin-ui-kit-dialog-form.js'])
@endsection

@section('content')
    @php
        $formatDateTime = static fn ($value): string => $value
            ? \Illuminate\Support\Carbon::parse($value)->format('d/m/Y H:i')
            : '';
        $formatDay = static fn ($value): string => $value
            ? \Illuminate\Support\Carbon::parse($value)->format('d/m/Y')
            : '—';
        $statusTone = match ($enrollment->status) {
            'active' => 'active',
            'pending' => 'draft',
            'suspended' => 'inactive',
            'expired', 'cancelled' => 'danger',
            default => 'archived',
        };
        $isOpen = in_array($enrollment->status, ['pending', 'active', 'suspended'], true);
        $runsInClass = in_array($enrollment->course_type, \App\Support\CourseType::COHORT_VALUES, true);
        $hasRunningCohort = $cohorts->whereIn('status', ['active', 'completed'])->isNotEmpty();
        $cohortRoutePrefix = str_replace('course-enrollments', 'course-cohorts', $routePrefix);
        $learningTone = ['learning' => 'active', 'review' => 'draft', 'upcoming' => 'draft'];
        $endedAt = [
            'cancelled' => ['lf.LF_course_enrollment_detail_cancelled_at', $enrollment->cancelled_at],
            'completed' => ['lf.LF_course_enrollment_detail_completed_at', $enrollment->completed_at],
            'expired' => ['lf.LF_course_enrollment_detail_expired_at', $enrollment->expired_at],
        ][$enrollment->status] ?? null;
        $activationBlocked = __('lf.LF_course_enrollment_lifecycle_activation_ineligible');
    @endphp
<div class="lf-admin-ui lf-admin-ui--list ui-enroll" data-ui-dialog-form-scope>
    <x-ui-list-toasts />

    @if ($errors->has('lifecycle'))<div class="admin-alert admin-alert-danger" role="alert">{{ $errors->first('lifecycle') }}</div>@endif

    <section class="mk-section ui-framework__identity" aria-labelledby="enrollment-identity-title">
        <div class="ui-version__identity">
            <p class="ui-version__eyebrow">ENR-{{ str_pad((string) $enrollment->id, 6, '0', STR_PAD_LEFT) }}</p>
            <h2 id="enrollment-identity-title">{{ $enrollment->student_name }}</h2>
            <p class="ui-caption">{{ $enrollment->student_email }}</p>
            <div class="ui-version__chips">
                <span class="ui-status ui-status--{{ $statusTone }}">{{ __('lf.LF_course_enrollment_common_'.$enrollment->status) }}</span>
                @if ($enrollment->course_type)
                    <span class="ui-course-type ui-course-type--{{ $enrollment->course_type }}" data-course-type="{{ $enrollment->course_type }}">{{ __(\App\Support\CourseType::labelKey($enrollment->course_type)) }}</span>
                @endif
                @if ($learningState === 'ended')
                    <span class="ui-status ui-status--danger">{{ __('lf.LF_course_enrollment_learning_ended') }}</span>
                @endif
            </div>
        </div>
        @if ($isOpen)
            <div class="ui-enroll-head__actions">
                <button type="button" class="ui-button ui-button--outline cursor-interaction" data-ui-dialog-form-open aria-haspopup="dialog"
                        data-url="{{ route($routePrefix.'.edit', $enrollment->id) }}">
                    <x-backend-icon name="square-pen" class="ui-icon" />
                    {{ __('lf.LF_common_button_edit') }}
                </button>
                @if ($enrollment->status === 'pending')
                    @include('course-enrollments.partials.lifecycle-action', ['dialogId' => 'enrollment-activate', 'action' => route($routePrefix.'.activate', $enrollment->id), 'triggerClass' => 'ui-button ui-button--primary cursor-interaction', 'triggerLabel' => __('lf.LF_course_enrollment_lifecycle_activate'), 'title' => __('lf.LF_course_enrollment_lifecycle_activate_title'), 'body' => __('lf.LF_course_enrollment_lifecycle_activate_body'), 'confirmClass' => 'ui-button ui-button--primary cursor-interaction', 'confirmLabel' => __('lf.LF_course_enrollment_lifecycle_activate'), 'blockedReason' => $canActivate ? null : $activationBlocked])
                @elseif ($enrollment->status === 'active')
                    @include('course-enrollments.partials.lifecycle-action', ['dialogId' => 'enrollment-suspend', 'action' => route($routePrefix.'.suspend', $enrollment->id), 'triggerClass' => 'ui-button ui-button--outline cursor-interaction', 'triggerLabel' => __('lf.LF_course_enrollment_lifecycle_suspend'), 'title' => __('lf.LF_course_enrollment_lifecycle_suspend_title'), 'body' => __('lf.LF_course_enrollment_lifecycle_suspend_body'), 'confirmClass' => 'ui-button ui-button--primary cursor-interaction', 'confirmLabel' => __('lf.LF_course_enrollment_lifecycle_suspend')])
                @elseif ($enrollment->status === 'suspended')
                    @include('course-enrollments.partials.lifecycle-action', ['dialogId' => 'enrollment-reactivate', 'action' => route($routePrefix.'.reactivate', $enrollment->id), 'triggerClass' => 'ui-button ui-button--primary cursor-interaction', 'triggerLabel' => __('lf.LF_course_enrollment_lifecycle_reactivate'), 'title' => __('lf.LF_course_enrollment_lifecycle_reactivate_title'), 'body' => __('lf.LF_course_enrollment_lifecycle_reactivate_body'), 'confirmClass' => 'ui-button ui-button--primary cursor-interaction', 'confirmLabel' => __('lf.LF_course_enrollment_lifecycle_reactivate'), 'blockedReason' => $canReactivate ? null : $activationBlocked])
                @endif
                @include('course-enrollments.partials.lifecycle-action', ['dialogId' => 'enrollment-cancel', 'action' => route($routePrefix.'.cancel', $enrollment->id), 'triggerClass' => 'ui-button ui-button--danger-outline cursor-interaction', 'triggerLabel' => __('lf.LF_course_enrollment_lifecycle_cancel'), 'title' => __('lf.LF_course_enrollment_lifecycle_cancel_title'), 'body' => __('lf.LF_course_enrollment_lifecycle_cancel_body'), 'confirmClass' => 'ui-button ui-button--danger-outline cursor-interaction', 'confirmLabel' => __('lf.LF_course_enrollment_lifecycle_cancel')])
            </div>
        @endif
    </section>

    <section class="mk-section" aria-labelledby="enrollment-show-access">
        <div class="ui-summary-head"><h2 id="enrollment-show-access">{{ __('lf.LF_course_enrollment_information') }}</h2></div>
        <dl class="ui-summary ui-enroll-summary">
            <div class="ui-summary__item">
                <dt>{{ __('lf.LF_course_enrollment_common_product') }}</dt>
                <dd>{{ $enrollment->product_title }}<span class="ui-caption ui-framework-description">{{ $enrollment->product_code }}</span></dd>
            </div>
            <div class="ui-summary__item">
                <dt class="ui-enroll-dt-tip">{{ __('lf.LF_course_enrollment_locked_version_label') }} <x-info-tip :text="__('lf.LF_course_enrollment_common_frozen_help')" /></dt>
                <dd>{{ $enrollment->version_title }}<span class="ui-caption ui-framework-description">{{ __('lf.LF_course_product_item_common_version_number', ['number' => $enrollment->version_number]) }} · {{ $enrollment->version_code }}</span></dd>
            </div>
            <div class="ui-summary__item">
                <dt>{{ __('lf.LF_course_enrollment_common_enrolled_at') }}</dt>
                <dd>{{ $formatDateTime($enrollment->enrolled_at) }}</dd>
            </div>
            <div class="ui-summary__item">
                <dt>{{ __('lf.LF_course_enrollment_common_source') }}</dt>
                <dd>{{ __('lf.LF_course_enrollment_common_source_'.$enrollment->source) }}</dd>
            </div>
            @if ($enrolledByName)
                <div class="ui-summary__item">
                    <dt>{{ __('lf.LF_bulk_enrollment_completed_by') }}</dt>
                    <dd>{{ $enrolledByName }}</dd>
                </div>
            @endif
            @if ($previousEnrollmentId)
                <div class="ui-summary__item">
                    <dt>{{ __('lf.LF_bulk_enrollment_design_reenroll') }}</dt>
                    <dd><a class="ui-enroll-link" href="{{ route($routePrefix.'.show', $previousEnrollmentId) }}">{{ __('lf.LF_course_enrollment_detail_reenrolled_from', ['id' => '#'.$previousEnrollmentId]) }}</a></dd>
                </div>
            @endif
            @if ($endedAt && $endedAt[1])
                <div class="ui-summary__item">
                    <dt>{{ __($endedAt[0]) }}</dt>
                    <dd>{{ $formatDateTime($endedAt[1]) }}</dd>
                </div>
            @endif
            @if (filled($enrollment->notes))
                <div class="ui-summary__item ui-summary__item--wide">
                    <dt>{{ __('lf.LF_course_enrollment_common_notes') }}</dt>
                    <dd class="ui-enroll-notes">{{ $enrollment->notes }}</dd>
                </div>
            @endif
        </dl>
    </section>

    <section class="mk-section" aria-labelledby="enrollment-show-information">
        <div class="ui-summary-head"><h2 id="enrollment-show-information">{{ __('lf.LF_course_enrollment_detail_learning_title') }}</h2></div>
        @if ($runsInClass)
            @if ($isOpen && ! $hasRunningCohort && $learningState !== 'ended')
                <p class="ui-enroll-warning" role="status" data-enrollment-no-class>
                    <x-backend-icon name="circle-alert" class="ui-icon" />
                    <span>{{ $cohorts->isEmpty() ? __('lf.LF_course_enrollment_detail_no_class') : __('lf.LF_course_enrollment_detail_class_not_running') }}
                        <a class="ui-enroll-link" href="{{ route($cohortRoutePrefix.'.index') }}">{{ __('lf.LF_course_enrollment_detail_go_classes') }}</a>
                    </span>
                </p>
            @endif
            @if ($cohorts->isNotEmpty())
                <dl class="ui-summary ui-enroll-summary">
                    @foreach ($cohorts as $cohort)
                        <div class="ui-summary__item">
                            <dt>{{ __('lf.LF_course_cohort_common_title') }}</dt>
                            <dd><a class="ui-enroll-link" href="{{ route($cohortRoutePrefix.'.show', $cohort->id) }}">{{ $cohort->name }}</a>
                                <span class="ui-caption ui-framework-description">{{ __('lf.LF_course_cohort_common_'.$cohort->status) }} · {{ $formatDay($cohort->start_date) }} → {{ $formatDay($cohort->end_date) }}</span></dd>
                        </div>
                    @endforeach
                </dl>
            @elseif (! $isOpen)
                <p class="ui-caption ui-enroll-empty-line">{{ __('lf.LF_course_enrollment_detail_no_class_closed') }}</p>
            @endif
        @endif
        @if (! $runsInClass || $enrollment->access_ends_at)
            <dl class="ui-summary ui-enroll-summary">
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
                @if ($learningState && $learningState !== 'ended')
                    <div class="ui-summary__item">
                        <dt>{{ __('lf.LF_course_enrollment_detail_learning_state') }}</dt>
                        <dd><span class="ui-status ui-status--{{ $learningTone[$learningState] }}">{{ __('lf.LF_course_enrollment_learning_'.$learningState) }}</span></dd>
                    </div>
                @endif
            </dl>
        @endif
    </section>

    @include('course-enrollments.partials.edit-dialog-shell')
</div>
@endsection
