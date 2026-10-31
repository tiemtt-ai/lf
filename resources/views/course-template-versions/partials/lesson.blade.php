{{-- Read-only Lesson of a published Version, laid out like the authoring Content tab: fold, phase tabs and one view icon per activity. --}}
@php
    $lessonActivities = $activitiesByLesson->get($lesson->id, collect());
    $lessonDescription = trim((string) $lesson->short_description_snapshot) !== ''
        ? $lesson->short_description_snapshot
        : (trim((string) $lesson->description_snapshot) !== ''
            ? $lesson->description_snapshot
            : null);
    $activityPhases = [
        'before_session' => 'available_before_session',
        'during_session' => 'available_during_session',
        'after_session' => 'available_after_session',
        'anytime' => 'available_anytime',
    ];
    $activityPhaseCounts = [];
    foreach ($activityPhases as $phase => $column) {
        $activityPhaseCounts[$phase] = $lessonActivities
            ->filter(static fn ($activity): bool => (bool) ($activity->{$column} ?? false))
            ->count();
    }
    // Phase tabs appear only when the lesson has session activities; a mixed lesson adds an Anytime tab after them.
    $sessionActivityCount = $lessonActivities
        ->filter(static fn ($activity): bool => ! ($activity->available_anytime ?? false))
        ->count();
    $activityTabs = $sessionActivityCount > 0 ? array_keys(array_filter($activityPhaseCounts)) : [];
    $activityDefaultPhase = $activityTabs[0] ?? 'anytime';
    $lessonPanelId = 'course-version-lesson-'.$lesson->id.'-activities';
@endphp

<article class="course-template-lesson-item"
         data-version-lesson-id="{{ $lesson->id }}"
         x-data="courseTemplateLessonCollapse(@js($template->customer_id), @js($foldScope), @js($lesson->id), @js($loop->first))"
         x-bind:class="{ 'is-expanded': expanded }">
    <div class="course-template-lesson-summary"
         x-on:click="if (! $event.target.closest('button, a')) toggle()">
        <span class="course-template-lesson-number" aria-hidden="true">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
        <div class="course-template-lesson-copy">
            <strong>{{ $lesson->title_snapshot }}</strong>
            @if ($lessonDescription !== null)
                <span class="lf-secondary-text lf-line-clamp-2">
                    {{ $lessonDescription }}
                </span>
            @endif
            <span class="course-template-lesson-meta">
                <span>
                    <x-backend-icon name="layers" class="ui-icon" />
                    {{ trans_choice('lf.LF_course_template_activity_common_count', $lessonActivities->count(), ['count' => $lessonActivities->count()]) }}
                </span>
                @if ($sessionActivityCount > 0 && $activityPhaseCounts['anytime'] > 0)
                    <span>
                        <x-backend-icon name="clock" class="ui-icon" />
                        {{ __('lf.LF_course_template_activity_learning_mode_session') }} ({{ $sessionActivityCount }})
                        · {{ __('lf.LF_course_template_activity_learning_mode_anytime') }} ({{ $activityPhaseCounts['anytime'] }})
                    </span>
                @elseif ($lessonActivities->isNotEmpty())
                    <span>
                        <x-backend-icon name="clock" class="ui-icon" />
                        {{ __($sessionActivityCount > 0 ? 'lf.LF_course_template_activity_learning_mode_session' : 'lf.LF_course_template_activity_learning_mode_anytime') }}
                    </span>
                @endif
            </span>
        </div>
        <button type="button"
                class="admin-link-button admin-text-action ui-row-action course-template-lesson-toggle"
                aria-controls="{{ $lessonPanelId }}"
                aria-expanded="{{ $loop->first ? 'true' : 'false' }}"
                x-bind:aria-expanded="expanded.toString()"
                aria-label="{{ __('lf.LF_course_template_lesson_common_toggle', ['title' => $lesson->title_snapshot]) }}"
                x-on:click="toggle()">
            <x-backend-icon name="chevron-down" class="ui-icon" />
        </button>
    </div>

    <section id="{{ $lessonPanelId }}"
             class="course-template-activity-panel"
             aria-label="{{ __('lf.LF_course_template_activity_common_list_title') }}"
             x-show="expanded"
             @unless ($loop->first) x-cloak @endunless
             x-data="{ phase: @js($activityDefaultPhase) }">
        @if ($activityTabs !== [])
            <div class="ui-tabs course-template-activity-tabs" role="tablist"
                 aria-label="{{ __('lf.LF_course_template_activity_common_list_title') }}">
                @foreach ($activityTabs as $phase)
                    <button type="button" role="tab" class="ui-tabs__tab"
                            id="course-version-lesson-{{ $lesson->id }}-tab-{{ $phase }}"
                            aria-controls="course-version-lesson-{{ $lesson->id }}-activity-list"
                            aria-selected="{{ $phase === $activityDefaultPhase ? 'true' : 'false' }}"
                            x-bind:aria-selected="(phase === '{{ $phase }}').toString()"
                            x-on:click="phase = '{{ $phase }}'">
                        {{ __('lf.LF_course_template_activity_learning_availability_'.$phase) }}
                        <span class="ui-tabs__count">{{ $activityPhaseCounts[$phase] }}</span>
                    </button>
                @endforeach
            </div>
        @endif

        <div class="course-template-activity-list" id="course-version-lesson-{{ $lesson->id }}-activity-list"
             @if ($activityTabs !== []) role="tabpanel" x-bind:aria-labelledby="'course-version-lesson-{{ $lesson->id }}-tab-' + phase" @endif>
            @forelse ($lessonActivities as $activity)
                @php
                    $activityView = $presentedActivities[$activity->id];
                    $activityIcon = match ($activity->activity_type) {
                        'video' => 'file-video',
                        'embedded_video' => 'square-play',
                        'external_link' => 'link',
                        'audio' => 'audio',
                        'quiz', 'assignment' => 'clipboard-check',
                        'live_class', 'liveclass' => 'video',
                        default => 'file-text',
                    };
                    $activitySeconds = $activity->estimated_duration_seconds_snapshot ?? $activity->duration_seconds ?? null;
                    $previewLabel = __('lf.LF_common_button_view').': '.$activity->title_snapshot;
                @endphp

                <div class="course-template-activity-item"
                     data-version-activity-id="{{ $activity->id }}"
                     @if ($activityTabs !== [])
                         x-show="@js(array_keys(array_filter(array_map(static fn (string $column): bool => (bool) ($activity->{$column} ?? false), $activityPhases)))).includes(phase)"
                     @endif>
                    <div class="course-template-activity-identity">
                        <span class="course-template-activity-icon course-template-activity-icon--{{ $activityTypeGroup($activity->activity_type) }}" aria-hidden="true">
                            <x-backend-icon :name="$activityIcon" class="course-template-activity-type-icon" />
                        </span>
                        <div class="course-template-activity-copy">
                            <span class="course-template-activity-title-text">
                                {{ $activity->title_snapshot }}
                            </span>
                            <span class="course-template-activity-meta">
                                {{ \Illuminate\Support\Facades\Lang::has('lf.LF_course_template_activity_common_type_'.$activity->activity_type) ? __('lf.LF_course_template_activity_common_type_'.$activity->activity_type) : $activity->activity_type }}
                                @if ($activitySeconds)
                                    · {{ __('lf.LF_course_template_activity_common_duration_minutes', ['minutes' => (int) ceil($activitySeconds / 60)]) }}
                                @endif
                            </span>
                        </div>
                    </div>
                    {{-- One UI Kit eye per row: a document opens in a new tab, media and embeds in the preview popup; the rest has nothing stored to preview. --}}
                    <div class="admin-table-actions">
                        @if ($activityView['mediaUrl'] && $activity->activity_type === 'document')
                            <a class="ux-row-edit cursor-interaction" data-version-activity-view
                               href="{{ $activityView['mediaUrl'] }}" target="_blank" rel="noopener noreferrer"
                               aria-label="{{ $previewLabel }}" data-tooltip="{{ __('lf.LF_common_button_view') }}">
                                <x-backend-icon name="eye" class="ui-icon" />
                            </a>
                        @elseif ($activityView['mediaUrl'] || $activityView['embeddedUrl'])
                            <button type="button" class="ux-row-edit cursor-interaction" data-version-activity-view
                                    data-preview-name="{{ $activity->title_snapshot }}"
                                    data-preview-url="{{ $activityView['mediaUrl'] ?: $activityView['embeddedUrl'] }}"
                                    data-preview-type="{{ $activityView['mediaUrl'] ? $activity->activity_type : 'embed' }}"
                                    data-preview-mime="{{ $activityView['embeddedUrl'] ? 'text/html' : '' }}"
                                    aria-label="{{ $previewLabel }}" data-tooltip="{{ __('lf.LF_common_button_view') }}"
                                    x-on:click="openVersionPreview($el.dataset.previewName, $el.dataset.previewUrl, $el.dataset.previewType, $el.dataset.previewMime)">
                                <x-backend-icon name="eye" class="ui-icon" />
                            </button>
                        @else
                            @php
                                $noPreview = __(in_array($activity->activity_type, ['live_class', 'liveclass'], true)
                                    ? 'lf.LF_version_detail_activity_no_preview_live'
                                    : 'lf.LF_version_detail_activity_no_preview');
                            @endphp
                            <span class="ui-bulk-lock" tabindex="0" data-tooltip="{{ $noPreview }}">
                                <button type="button" class="ux-row-edit" disabled aria-label="{{ $noPreview }}">
                                    <x-backend-icon name="eye-off" class="ui-icon" />
                                </button>
                            </span>
                        @endif
                    </div>
                </div>
            @empty
                <p class="course-template-activity-empty">{{ __('lf.LF_course_template_activity_common_empty') }}</p>
            @endforelse
        </div>
    </section>
</article>
