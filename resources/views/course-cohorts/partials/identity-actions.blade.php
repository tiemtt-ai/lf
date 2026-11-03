{{-- Status changes for the identity card. --}}
@if ($cohort->is_mutable)
    <a href="{{ route($routePrefix.'.edit', $cohort->id) }}" class="ui-button ui-button--outline cursor-interaction">
        <x-backend-icon name="square-pen" class="ui-icon" />
        {{ __('lf.LF_course_cohort_action_edit_overview') }}
    </a>
@endif
@if ($cohort->status === 'draft')
    <div class="course-cohort-activation-action">
        @include('course-cohorts.partials.kit-lifecycle-action', [
            'dialogId' => 'cohort-activate',
            'action' => route($routePrefix.'.activate', $cohort->id),
            'triggerClass' => 'ui-button ui-button--primary cursor-interaction',
            'triggerLabel' => __('lf.LF_course_cohort_action_activate'),
            'title' => __('lf.LF_course_cohort_lifecycle_activate_title'),
            'body' => __('lf.LF_course_cohort_lifecycle_activate_body'),
            'confirmClass' => 'ui-button ui-button--primary cursor-interaction',
            'confirmLabel' => __('lf.LF_course_cohort_lifecycle_activate_confirm'),
            'disabled' => $activationIssues !== [],
        ])
    </div>
    @include('course-cohorts.partials.kit-lifecycle-action', [
        'dialogId' => 'cohort-archive',
        'action' => route($routePrefix.'.archive', $cohort->id),
        'triggerClass' => 'ui-button ui-button--danger-outline cursor-interaction',
        'triggerLabel' => __('lf.LF_course_cohort_common_archive'),
        'title' => __('lf.LF_course_cohort_lifecycle_archive_title'),
        'body' => __('lf.LF_course_cohort_lifecycle_archive_body'),
        'confirmClass' => 'ui-button ui-button--danger-outline cursor-interaction',
        'confirmLabel' => __('lf.LF_course_cohort_lifecycle_archive_confirm'),
    ])
@elseif ($cohort->status === 'active')
    @include('course-cohorts.partials.kit-lifecycle-action', [
        'dialogId' => 'cohort-complete',
        'action' => route($routePrefix.'.complete', $cohort->id),
        'triggerClass' => 'ui-button ui-button--primary cursor-interaction',
        'triggerLabel' => __('lf.LF_course_cohort_action_complete'),
        'title' => __('lf.LF_course_cohort_lifecycle_complete_title'),
        'body' => __('lf.LF_course_cohort_lifecycle_complete_body'),
        'confirmClass' => 'ui-button ui-button--primary cursor-interaction',
        'confirmLabel' => __('lf.LF_course_cohort_lifecycle_complete_confirm'),
    ])
@elseif ($cohort->status === 'completed')
    @include('course-cohorts.partials.kit-lifecycle-action', [
        'dialogId' => 'cohort-archive',
        'action' => route($routePrefix.'.archive', $cohort->id),
        'triggerClass' => 'ui-button ui-button--danger-outline cursor-interaction',
        'triggerLabel' => __('lf.LF_course_cohort_common_archive'),
        'title' => __('lf.LF_course_cohort_lifecycle_archive_title'),
        'body' => __('lf.LF_course_cohort_lifecycle_archive_body'),
        'confirmClass' => 'ui-button ui-button--danger-outline cursor-interaction',
        'confirmLabel' => __('lf.LF_course_cohort_lifecycle_archive_confirm'),
    ])
@endif
