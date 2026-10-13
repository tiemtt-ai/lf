@php
    $skillName = $skill->name_snapshot ?? $skill->code_snapshot;
    $activityTitle = $skill->activity_title ?? null;
    $activityPage = $skill->source_type === 'course_template_activity' ? $activityPages->get((int) $skill->source_id) : null;
    $roleLabel = $roleLabels[$skill->mapping_role] ?? null;
@endphp
<li class="course-learning__skill" data-course-learning-intent="{{ $skill->id }}">
    <span class="course-learning__skill-text">
        <span class="course-learning__skill-name">{{ $skillName }}</span>
        @if ($activityTitle || $roleLabel)
            <span class="course-learning__skill-meta">
                @if ($activityTitle && $activityPage)
                    <a class="course-learning__skill-link" href="{{ $activityPage }}" title="{{ __('lf.LF_learning_mapping_open_activity') }}">{{ __('lf.LF_course_template_learning_activity_scope', ['title' => $activityTitle]) }}</a>
                @elseif ($activityTitle)
                    {{ __('lf.LF_course_template_learning_activity_scope', ['title' => $activityTitle]) }}
                @endif
                @if ($activityTitle && $roleLabel) · @endif
                {{ $roleLabel }}
            </span>
        @endif
    </span>
    <form method="POST" action="{{ route($routePrefix.'.learning-mappings.destroy', [$template->id, $skill->id]) }}" class="course-learning__skill-remove"
          data-lf-confirm="{{ __('lf.LF_course_template_learning_remove_confirm', ['name' => $skillName]) }}"
          data-lf-confirm-label="{{ __('lf.LF_course_template_learning_remove', ['name' => $skillName]) }}" data-lf-confirm-tone="danger"
          @if ($mappingPicker ?? null) data-lf-mapping-async data-lf-mapping-remove
          data-error-message="{{ __('lf.LF_course_template_learning_attach_network_error') }}"
          data-refresh-error="{{ __('lf.LF_course_template_learning_attach_refresh_error') }}" @endif>
        @if ($mappingPicker ?? null)
            <input type="hidden" name="mapping_picker" value="{{ $mappingPicker }}">
            <p data-lf-mapping-error class="course-learning__picker-feedback is-error" role="alert" hidden></p>
        @endif
        @csrf
        @method('DELETE')
        <button type="submit" aria-label="{{ __('lf.LF_course_template_learning_remove', ['name' => $skillName]) }}" title="{{ __('lf.LF_course_template_learning_remove', ['name' => $skillName]) }}">
            <span aria-hidden="true">×</span>
        </button>
    </form>
</li>
