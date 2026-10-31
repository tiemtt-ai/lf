{{--
    One lesson of the learning map, as a Content-tab lesson card: number, title, activity count, the competencies
    attached to it and the attach action. The card keeps id "{picker}-row" so the async save can focus it again.
--}}
@php
    $lesson = $row['lesson'];
    $lessonNodeIds = $row['skills']->where('source_type', 'course_template_lesson')->where('mapping_role', 'teaches')->pluck('learning_node_id')->map(fn ($id) => (int) $id);
    $pickerId = 'course-learning-picker-'.$lesson->id;
@endphp
<article id="{{ $pickerId }}-row" class="course-template-lesson-item ui-learning-lesson-card" data-course-learning-lesson="{{ $lesson->id }}"
         x-bind:hidden="! lessonShown({{ $lesson->id }})"
         x-data="{ open() { $refs.mappingDialog.showModal(); } }">
    <div class="course-template-lesson-summary">
        <span class="course-template-lesson-number" aria-hidden="true">{{ str_pad((string) $position, 2, '0', STR_PAD_LEFT) }}</span>
        <div class="course-template-lesson-copy">
            <strong>{{ $lesson->title }}</strong>
            <span class="course-template-lesson-meta">
                <span>
                    <x-backend-icon name="layers" class="ui-icon" />
                    {{ trans_choice('lf.LF_course_template_learning_lesson_activities', $row['activities']->count(), ['count' => $row['activities']->count()]) }}
                </span>
                @if ($row['skills']->isEmpty())
                    <span class="ui-learning-unmapped">{{ __('lf.LF_course_template_learning_not_mapped') }}</span>
                @endif
            </span>
        </div>
        <div class="admin-table-actions">
            <button type="button" class="ui-learning-attach cursor-interaction" x-on:click="open()"
                    aria-label="{{ __('lf.LF_course_template_learning_picker_label', ['lesson' => $lesson->title]) }}">
                <x-backend-icon name="plus" class="ui-icon" />{{ __('lf.LF_course_template_learning_add') }}
            </button>
        </div>
    </div>
    @if ($row['skills']->isNotEmpty() || (session('mapping_picker') === $pickerId && session('mapping_feedback')))
        <div class="ui-learning-lesson-card__skills">
            @if ($row['skills']->isNotEmpty())
                <ul class="ui-learning-chips">
                    @foreach ($row['skills'] as $skill)
                        @include('course-templates.partials.learning-skill-chip', ['mappingPicker' => $pickerId])
                    @endforeach
                </ul>
            @endif
            @if (session('mapping_picker') === $pickerId && session('mapping_feedback'))
                <p class="ui-learning-feedback" role="status" x-data="{ visible: true }" x-init="setTimeout(() => visible = false, 3000)" x-show="visible" x-transition.opacity>
                    <x-backend-icon name="check" class="ui-icon" /><span>{{ session('mapping_feedback') }}</span>
                </p>
            @endif
        </div>
    @endif
    @include('course-templates.partials.learning-node-picker')
</article>
