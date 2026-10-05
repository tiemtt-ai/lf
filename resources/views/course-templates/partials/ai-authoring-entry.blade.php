{{-- The one place for AI proposals. Choosing an Activity reloads the page, so the section below
     is always built for exactly one Activity and no state carries over from another. --}}
<div id="ai-authoring-entry" class="admin-card admin-form-card admin-form-surface">
    <h2 class="admin-form-section-title">{{ __('lf.LF_course_template_ai_entry_title') }}</h2>
    <p class="admin-form-help">{{ __('lf.LF_course_template_ai_entry_intro') }}</p>

    @if ($aiAuthoring['activities'] === [])
        <p class="lf-secondary-text" data-ai-authoring-no-activities>{{ __('lf.LF_course_template_ai_entry_none') }}</p>
    @else
        <form class="admin-form-standard" method="GET" action="{{ route($routePrefix.'.edit', $template->id) }}#ai-authoring-entry">
            <input type="hidden" name="tab" value="learning">
            <div class="admin-form-group">
                <label for="ai-authoring-activity">{{ __('lf.LF_course_template_ai_entry_label') }}</label>
                <select id="ai-authoring-activity" name="ai_activity" required>
                    <option value="">{{ __('lf.LF_course_template_ai_entry_placeholder') }}</option>
                    @foreach (collect($aiAuthoring['activities'])->groupBy('lesson_title') as $lessonTitle => $activities)
                        <optgroup label="{{ $lessonTitle }}">
                            @foreach ($activities as $activity)
                                <option value="{{ $activity['id'] }}" @selected($aiAuthoring['selected_activity_id'] === $activity['id'])>{{ $activity['title'] }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </div>
            <div class="admin-form-actions">
                <button type="submit" class="btn btn-primary">{{ __('lf.LF_course_template_ai_entry_submit') }}</button>
            </div>
        </form>
    @endif
</div>

@if ($aiAuthoring['entry'] !== null)
    @include('course-template-activities.partials.ai-authoring', ['aiAuthoring' => $aiAuthoring['entry']])
@endif
