@extends('layouts.backend')

@section('title', __('lf.LF_course_template_activity_common_view'))
@section('page_title', __('lf.LF_course_template_activity_common_view'))

@section('content')
    @php
        $activityRouteParameters = $section
            ? [$template->id, $section->id, $lesson->id, $activity->id]
            : [$template->id, $lesson->id, $activity->id];
    @endphp

    <div class="admin-card admin-form-card">
        <p class="lf-secondary-text">
            {{ __('lf.LF_course_template_activity_common_location') }}:
            {{ $template->title }} →
            @if ($section)
                {{ $section->title }} →
            @endif
            {{ $lesson->title }}
        </p>

        <h2>{{ $activity->title }}</h2>

        @if ($activity->description)
            <section aria-labelledby="activity-description-title">
                <h3 id="activity-description-title">
                    {{ __('lf.LF_course_template_activity_common_description') }}
                </h3>
                <p>{{ $activity->description }}</p>
            </section>
        @endif

        <dl class="admin-readonly-summary">
            <dt>{{ __('lf.LF_course_template_activity_common_type') }}</dt>
            <dd>{{ __('lf.LF_course_template_activity_common_type_'.$activity->activity_type) }}</dd>

            @if ($activity->estimated_duration_seconds !== null)
                <dt>{{ __('lf.LF_course_template_activity_common_estimated_duration_minutes') }}</dt>
                <dd>{{ __('lf.LF_course_template_activity_common_duration_minutes', ['minutes' => intdiv($activity->estimated_duration_seconds, 60)]) }}</dd>
            @endif

            @if ($activity->assessment_quiz_id)
                <dt>{{ __('lf.LF_course_template_activity_common_reference') }}</dt>
                <dd>Assessment Quiz #{{ $activity->assessment_quiz_id }}</dd>
            @endif

            @if ($externalUrl)
                <dt>{{ __('lf.LF_course_template_activity_common_external_url') }}</dt>
                <dd>
                    <a href="{{ $externalUrl }}"
                       target="_blank" rel="noopener noreferrer">
                        {{ $externalUrl }}
                    </a>
                </dd>
            @endif

        </dl>

        @if ($activityMedia->isNotEmpty())
            <section aria-labelledby="activity-media-title">
                <h3 id="activity-media-title">
                    {{ __('lf.LF_course_template_activity_common_attached_media') }}
                </h3>
                <ul>
                    @foreach ($activityMedia as $media)
                        <li>
                            <a href="{{ $media->signed_url }}"
                               target="_blank" rel="noopener noreferrer">
                                {{ $media->display_name ?: $media->original_name }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <div class="admin-form-actions">
            <a href="{{ route($templateRoutePrefix.'.edit', $template->id) }}?tab=structure#course-template-lesson-{{ $lesson->id }}-activities">
                {{ __('lf.LF_common_button_back') }}
            </a>
            <a href="{{ route(
                $routePrefix.'.edit',
                $activityRouteParameters
            ) }}" class="btn btn-primary">
                {{ __('lf.LF_course_template_activity_common_edit') }}
            </a>
        </div>
    </div>
@endsection
