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
    <form method="POST" action="{{ route($routePrefix.'.learning-mappings.destroy', [$template->id, $skill->id]) }}" class="course-learning__skill-remove">
        @csrf
        @method('DELETE')
        <button type="submit" aria-label="{{ __('lf.LF_course_template_learning_remove', ['name' => $skillName]) }}" title="{{ __('lf.LF_course_template_learning_remove', ['name' => $skillName]) }}">
            <span aria-hidden="true">×</span>
        </button>
    </form>
</li>
