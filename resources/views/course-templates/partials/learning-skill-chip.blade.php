@php
    $skillName = $skill->name_snapshot ?? $skill->code_snapshot;
    $activityTitle = $skill->activity_title ?? null;
    $activityPage = $skill->source_type === 'course_template_activity' ? $activityPages->get((int) $skill->source_id) : null;
    $roleLabel = $roleLabels[$skill->mapping_role] ?? null;
@endphp
<li class="ui-learning-chip" data-course-learning-intent="{{ $skill->id }}" @if ($skill->description_snapshot ?? null) title="{{ $skill->description_snapshot }}" @endif>
    @if ($skill->code_snapshot ?? null)<span class="ui-learning-code">{{ $skill->code_snapshot }}</span>@endif
    <span class="ui-learning-chip__text">
        <span class="ui-learning-chip__name">{{ $skillName }}</span>
        @if ($activityTitle || $roleLabel)
            <span class="ui-learning-chip__meta">
                @if ($activityTitle && $activityPage)
                    <a class="course-learning__skill-link ui-learning-link" href="{{ $activityPage }}" title="{{ __('lf.LF_learning_mapping_open_activity') }}">{{ __('lf.LF_course_template_learning_activity_scope', ['title' => $activityTitle]) }}</a>
                @elseif ($activityTitle)
                    {{ __('lf.LF_course_template_learning_activity_scope', ['title' => $activityTitle]) }}
                @endif
                @if ($activityTitle && $roleLabel) · @endif
                {{ $roleLabel }}
            </span>
        @endif
    </span>
    <form method="POST" action="{{ route($routePrefix.'.learning-mappings.destroy', [$template->id, $skill->id]) }}" class="ui-learning-chip__remove-form"
          data-lf-confirm="{{ __('lf.LF_course_template_learning_remove_confirm', ['name' => $skillName]) }}"
          data-lf-confirm-label="{{ __('lf.LF_course_template_learning_remove', ['name' => $skillName]) }}" data-lf-confirm-tone="danger"
          @if ($mappingPicker ?? null) data-lf-mapping-async data-lf-mapping-remove
          data-error-message="{{ __('lf.LF_course_template_learning_attach_network_error') }}"
          data-refresh-error="{{ __('lf.LF_course_template_learning_attach_refresh_error') }}" @endif>
        @if ($mappingPicker ?? null)
            <input type="hidden" name="mapping_picker" value="{{ $mappingPicker }}">
            <p data-lf-mapping-error class="ui-field__error" role="alert" hidden></p>
        @endif
        @csrf
        @method('DELETE')
        <button type="submit" class="ui-learning-chip__remove cursor-interaction" aria-label="{{ __('lf.LF_course_template_learning_remove', ['name' => $skillName]) }}" data-tooltip="{{ __('lf.LF_course_template_learning_remove', ['name' => $skillName]) }}">
            <x-backend-icon name="x" class="ui-icon" />
        </button>
    </form>
</li>
