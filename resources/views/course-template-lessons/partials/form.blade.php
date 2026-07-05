@php
    $formLesson = $lesson ?? null;
    $selectedPreview = (string) old('is_preview', $formLesson?->is_preview ?? 0);
    $selectedUnlockRule = old('unlock_rule', $formLesson?->unlock_rule ?? 'none');
    $selectedPrerequisiteId = old(
        'unlock_after_lesson_id',
        $formLesson?->unlock_after_lesson_id
    );
    $selectedStatus = old('status', $formLesson?->status ?? 'draft');
    $unlockAt = old(
        'unlock_at',
        $formLesson?->unlock_at
            ? \Illuminate\Support\Carbon::parse($formLesson->unlock_at)->format('Y-m-d\TH:i')
            : null
    );
    $isRequired = static fn (string $field): bool => in_array($field, $requiredFields, true);
@endphp

<section class="admin-form-section" aria-labelledby="course-template-lesson-basic-title">
    <h2 id="course-template-lesson-basic-title" class="admin-form-section-title">
        {{ __('lf.LF_course_template_lesson_group_basic') }}
    </h2>

    <div class="lf-form-group">
        <x-form-label for="title"
                      :value="__('lf.LF_course_template_lesson_common_name')"
                      :required="$isRequired('title')" />
        <input id="title" type="text" name="title" class="lf-form-control"
               value="{{ old('title', $formLesson?->title) }}" required maxlength="255">
    </div>

    <div class="lf-form-group">
        <x-form-label for="slug"
                      :value="__('lf.LF_course_template_lesson_common_slug')"
                      :required="$isRequired('slug')" />
        <input id="slug" type="text" name="slug" class="lf-form-control"
               value="{{ old('slug', $formLesson?->slug) }}" maxlength="255">
    </div>

    <div class="lf-form-group">
        <x-form-label for="short_description"
                      :value="__('lf.LF_course_template_lesson_common_short_description')"
                      :required="$isRequired('short_description')" />
        <textarea id="short_description" name="short_description"
                  class="lf-form-control" rows="2"
                  maxlength="500">{{ old('short_description', $formLesson?->short_description) }}</textarea>
    </div>

    <div class="lf-form-group">
        <x-form-label for="description"
                      :value="__('lf.LF_course_template_lesson_common_description')"
                      :required="$isRequired('description')" />
        <textarea id="description" name="description" class="lf-form-control"
                  rows="5">{{ old('description', $formLesson?->description) }}</textarea>
    </div>

    <div class="lf-form-group">
        <x-form-label for="learning_objective"
                      :value="__('lf.LF_course_template_lesson_common_learning_objective')"
                      :required="$isRequired('learning_objective')" />
        <textarea id="learning_objective" name="learning_objective"
                  class="lf-form-control"
                  rows="4">{{ old('learning_objective', $formLesson?->learning_objective) }}</textarea>
    </div>
</section>

<section class="admin-form-section" aria-labelledby="course-template-lesson-media-title">
    <h2 id="course-template-lesson-media-title" class="admin-form-section-title">
        Lesson media
    </h2>

    @if (($lessonMedia ?? collect())->isNotEmpty())
        <div class="admin-table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>Type</th>
                    <th>Name</th>
                    <th>Action</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($lessonMedia as $media)
                    <tr>
                        <td>{{ $media->usage_type }}</td>
                        <td>{{ $media->display_name }}</td>
                        <td>
                            <a href="{{ $media->signed_url }}" target="_blank" rel="noopener">
                                Open
                            </a>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="lf-form-group">
        <x-form-label for="media_video_file" value="Video" />
        <input id="media_video_file"
               type="file"
               name="media_video_file"
               class="lf-form-control"
               accept="video/*">
    </div>

    <div class="lf-form-group">
        <x-form-label for="media_audio_file" value="Audio" />
        <input id="media_audio_file"
               type="file"
               name="media_audio_file"
               class="lf-form-control"
               accept="audio/*">
    </div>

    <div class="lf-form-group">
        <x-form-label for="media_document_file" value="Document" />
        <input id="media_document_file"
               type="file"
               name="media_document_file"
               class="lf-form-control"
               accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,application/pdf">
    </div>
</section>

<section class="admin-form-section" aria-labelledby="course-template-lesson-display-title">
    <h2 id="course-template-lesson-display-title" class="admin-form-section-title">
        {{ __('lf.LF_course_template_lesson_group_display') }}
    </h2>

    <div class="lf-form-group">
        <x-form-label for="sort_order"
                      :value="__('lf.LF_course_template_lesson_common_sort_order')"
                      :required="$isRequired('sort_order')" />
        <input id="sort_order" type="number" min="0" name="sort_order"
               class="lf-form-control"
               value="{{ old('sort_order', $formLesson?->sort_order ?? 0) }}" required>
    </div>

    <div class="lf-form-group">
        <x-form-label for="is_preview"
                      :value="__('lf.LF_course_template_lesson_common_preview')"
                      :required="$isRequired('is_preview')" />
        <select id="is_preview" name="is_preview" class="lf-form-control" required>
            <option value="0" @selected($selectedPreview === '0')>
                {{ __('lf.LF_course_template_lesson_common_no') }}
            </option>
            <option value="1" @selected($selectedPreview === '1')>
                {{ __('lf.LF_course_template_lesson_common_yes') }}
            </option>
        </select>
    </div>
</section>

<section class="admin-form-section" aria-labelledby="course-template-lesson-unlock-title">
    <h2 id="course-template-lesson-unlock-title" class="admin-form-section-title">
        {{ __('lf.LF_course_template_lesson_group_unlock') }}
    </h2>

    <div class="lf-form-group">
        <x-form-label for="unlock_rule"
                      :value="__('lf.LF_course_template_lesson_common_unlock_rule')"
                      :required="$isRequired('unlock_rule')" />
        <select id="unlock_rule" name="unlock_rule" class="lf-form-control" required>
            @foreach (['none', 'previous_lesson_completed', 'date_based'] as $unlockRule)
                <option value="{{ $unlockRule }}" @selected($selectedUnlockRule === $unlockRule)>
                    {{ __('lf.LF_course_template_lesson_common_unlock_'.$unlockRule) }}
                </option>
            @endforeach
        </select>
    </div>

    <div class="lf-form-group">
        <x-form-label for="unlock_after_lesson_id"
                      :value="__('lf.LF_course_template_lesson_common_unlock_after_lesson')"
                      :required="$isRequired('unlock_after_lesson_id')" />
        <select id="unlock_after_lesson_id" name="unlock_after_lesson_id"
                class="lf-form-control">
            <option value="">
                {{ __('lf.LF_course_template_lesson_common_no_prerequisite') }}
            </option>
            @foreach ($prerequisiteLessons as $prerequisiteLesson)
                <option value="{{ $prerequisiteLesson->id }}"
                        @selected(
                            (string) $selectedPrerequisiteId
                            === (string) $prerequisiteLesson->id
                        )>
                    {{ $prerequisiteLesson->title }}
                </option>
            @endforeach
        </select>
    </div>

    <div class="lf-form-group">
        <x-form-label for="unlock_at"
                      :value="__('lf.LF_course_template_lesson_common_unlock_at')"
                      :required="$isRequired('unlock_at')" />
        <input id="unlock_at" type="datetime-local" name="unlock_at"
               class="lf-form-control" value="{{ $unlockAt }}">
    </div>
</section>

<section class="admin-form-section" aria-labelledby="course-template-lesson-status-title">
    <h2 id="course-template-lesson-status-title" class="admin-form-section-title">
        {{ __('lf.LF_course_template_lesson_group_status') }}
    </h2>

    <div class="lf-form-group">
        <x-form-label for="status"
                      :value="__('lf.LF_course_template_lesson_common_status')"
                      :required="$isRequired('status')" />
        <select id="status" name="status" class="lf-form-control" required>
            @foreach (['draft', 'active', 'inactive', 'archived'] as $status)
                <option value="{{ $status }}" @selected($selectedStatus === $status)>
                    {{ __('lf.LF_course_template_lesson_common_'.$status) }}
                </option>
            @endforeach
        </select>
    </div>
</section>
