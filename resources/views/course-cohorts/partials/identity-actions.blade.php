{{-- Status changes for the identity card: they concern the whole class, unlike the per-tab actions. --}}
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
        // A draft left on an old Version archives with its members, who leave the class (CourseCohortLifecycleService).
        'warnings' => in_array(__('lf.LF_course_cohort_activation_product_version_invalid'), $activationIssues, true)
            ? [__('lf.LF_course_cohort_lifecycle_archive_stale_version')] : [],
        'confirmClass' => 'ui-button ui-button--danger-outline cursor-interaction',
        'confirmLabel' => __('lf.LF_course_cohort_lifecycle_archive_confirm'),
    ])
@elseif ($cohort->status === 'active')
    @include('course-cohorts.partials.kit-lifecycle-action', [
        'dialogId' => 'cohort-complete',
        'action' => route($routePrefix.'.complete', $cohort->id),
        'triggerClass' => 'ui-button ui-button--outline cursor-interaction',
        'triggerLabel' => __('lf.LF_course_cohort_action_complete'),
        'title' => __('lf.LF_course_cohort_lifecycle_complete_title'),
        'body' => __('lf.LF_course_cohort_lifecycle_complete_body'),
        'warnings' => $completionWarnings,
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
