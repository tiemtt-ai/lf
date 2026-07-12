<article class="course-version-lesson">
    <div class="course-version-item-heading">
        <div>
            <p class="course-version-eyebrow">
                {{ __('lf.LF_course_template_version_detail_lesson_order', [
                    'order' => $lesson->sort_order,
                ]) }}
            </p>
            <h4>{{ $lesson->title_snapshot }}</h4>
        </div>
    </div>

    @if ($lesson->short_description_snapshot)
        <p>{{ $lesson->short_description_snapshot }}</p>
    @endif

    <dl class="course-version-inline-summary">
        <div>
            <dt>{{ __('lf.LF_course_template_version_detail_duration') }}</dt>
            <dd>{{ __('lf.LF_course_template_version_detail_seconds', [
                'seconds' => $lesson->duration_seconds,
            ]) }}</dd>
        </div>
        <div>
            <dt>{{ __('lf.LF_course_template_version_detail_activities') }}</dt>
            <dd>{{ $lesson->activity_count }}</dd>
        </div>
        <div>
            <dt>{{ __('lf.LF_course_template_version_detail_preview') }}</dt>
            <dd>{{ $lesson->is_preview
                ? __('lf.LF_course_template_version_detail_yes')
                : __('lf.LF_course_template_version_detail_no') }}</dd>
        </div>
        <div>
            <dt>{{ __('lf.LF_course_template_lesson_common_role') }}</dt>
            <dd>{{ __('lf.LF_course_template_lesson_common_role_'.($lesson->lesson_type ?? 'regular')) }}</dd>
        </div>
    </dl>

    @php
        $lessonActivities = $activitiesByLesson->get($lesson->id, collect());
    @endphp

    <div class="course-version-activities">
        <h5>{{ __('lf.LF_course_template_version_detail_activities') }}</h5>

        @forelse ($lessonActivities as $activity)
            <article class="course-version-activity">
                <div>
                    <strong>{{ $activity->title_snapshot }}</strong>
                    <span>
                        {{ __('lf.LF_course_template_activity_common_type_'.$activity->activity_type) }}
                        ·
                        {{ __('lf.LF_course_template_version_detail_order', [
                            'order' => $activity->sort_order,
                        ]) }}
                    </span>
                </div>
            </article>
        @empty
            <p class="course-version-empty">
                {{ __('lf.LF_course_template_version_detail_no_activities') }}
            </p>
        @endforelse
    </div>
</article>
