@php
    $activityRouteParameters = $section
        ? [$template->id, $section->id, $lesson->id, $activity->id]
        : [$template->id, $lesson->id, $activity->id];
    $learningAvailability = collect([
        'anytime' => $activity->available_anytime,
        'before_session' => $activity->available_before_session,
        'during_session' => $activity->available_during_session,
        'after_session' => $activity->available_after_session,
    ])->filter()->keys()->map(
        fn (string $phase) => __('lf.LF_course_template_activity_learning_availability_'.$phase)
    )->implode(', ');
@endphp
{{-- Read-only details of an activity, in the same dialog as the forms. It has no field and no submit; Close and Edit. --}}
<form class="ui-modal__form" novalidate onsubmit="return false">
    <header class="ui-modal__header">
        <div>
            <h3 id="structure-dialog-title">{{ $activity->title }}</h3>
            <p class="ui-caption">
                {{ __('lf.LF_course_template_activity_common_location') }}:
                {{ $template->title }} → @if ($section){{ $section->title }} → @endif{{ $lesson->title }}
            </p>
        </div>
        <button class="ui-modal__close cursor-interaction" type="button" data-ui-dialog-form-cancel
                aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
    </header>
    <div class="ui-modal__body">
        <dl class="ui-summary">
            @if ($activity->description)
                <div class="ui-summary__item ui-summary__item--wide">
                    <dt class="ui-field__label">{{ __('lf.LF_course_template_activity_common_description') }}</dt>
                    <dd>{{ $activity->description }}</dd>
                </div>
            @endif

            <div class="ui-summary__item">
                <dt class="ui-field__label">{{ __('lf.LF_course_template_activity_common_type') }}</dt>
                <dd>{{ __('lf.LF_course_template_activity_common_type_'.$activity->activity_type) }}</dd>
            </div>

            @if ($activity->estimated_duration_seconds !== null)
                <div class="ui-summary__item">
                    <dt class="ui-field__label">{{ __('lf.LF_course_template_activity_common_estimated_duration_minutes') }}</dt>
                    <dd>{{ __('lf.LF_course_template_activity_common_duration_minutes', ['minutes' => intdiv($activity->estimated_duration_seconds, 60)]) }}</dd>
                </div>
            @endif

            <div class="ui-summary__item ui-summary__item--wide">
                <dt class="ui-field__label">{{ __('lf.LF_course_template_activity_learning_availability') }}</dt>
                <dd>{{ $learningAvailability }}</dd>
            </div>

            @if ($activity->assessment_quiz_id)
                <div class="ui-summary__item">
                    <dt class="ui-field__label">{{ __('lf.LF_course_template_activity_common_reference') }}</dt>
                    <dd>Assessment Quiz #{{ $activity->assessment_quiz_id }}</dd>
                </div>
            @endif

            @if ($externalUrl)
                <div class="ui-summary__item ui-summary__item--wide">
                    <dt class="ui-field__label">{{ __('lf.LF_course_template_activity_common_external_url') }}</dt>
                    <dd><a href="{{ $externalUrl }}" target="_blank" rel="noopener noreferrer">{{ $externalUrl }}</a></dd>
                </div>
            @endif

            @if ($activityMedia->isNotEmpty())
                <div class="ui-summary__item ui-summary__item--wide">
                    <dt class="ui-field__label">{{ __('lf.LF_course_template_activity_common_attached_media') }}</dt>
                    <dd>
                        <ul class="ui-summary__files">
                            @foreach ($activityMedia as $media)
                                <li>
                                    @if ($media->signed_url)
                                        <a href="{{ $media->signed_url }}" target="_blank" rel="noopener noreferrer">{{ $media->display_name ?: $media->original_name }}</a>
                                    @else
                                        <span>{{ $media->display_name ?: $media->original_name }}</span>
                                        <span class="ui-caption">{{ __('lf.LF_course_template_activity_media_unavailable') }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </dd>
                </div>
            @endif
        </dl>
    </div>
    <footer class="ui-modal__footer">
        <button type="button" class="ui-button cursor-interaction" data-ui-dialog-form-cancel>
            {{ __('lf.LF_common_button_close') }}
        </button>
        <button type="button" class="ui-button ui-button--primary cursor-interaction"
                data-ui-dialog-form-open aria-haspopup="dialog"
                data-url="{{ route($routePrefix.'.edit', $activityRouteParameters) }}">
            {{ __('lf.LF_course_template_activity_common_edit') }}
        </button>
    </footer>
</form>
