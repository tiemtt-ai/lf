@php
    $formActivity = $activity ?? null;
    $selectedActivityType = old(
        'activity_type',
        $formActivity?->activity_type
    );
    $selectedRequired = (string) old(
        'is_required',
        $formActivity?->is_required ?? 1
    );
    $selectedCompletionRule = old(
        'completion_rule',
        $formActivity?->completion_rule ?? 'view'
    );
    $selectedPreview = (string) old(
        'is_preview',
        $formActivity?->is_preview ?? 0
    );
    $selectedUnlockRule = old(
        'unlock_rule',
        $formActivity?->unlock_rule ?? 'none'
    );
    $selectedPrerequisiteId = old(
        'unlock_after_activity_id',
        $formActivity?->unlock_after_activity_id
    );
    $selectedStatus = old('status', $formActivity?->status ?? 'draft');
    $unlockAt = old(
        'unlock_at',
        $formActivity?->unlock_at
            ? \Illuminate\Support\Carbon::parse($formActivity->unlock_at)
                ->format('Y-m-d\TH:i')
            : null
    );
    $isRequired = static fn (string $field): bool => in_array(
        $field,
        $requiredFields,
        true
    );
@endphp

<div x-data="{ activityType: @js($selectedActivityType) }">
    <section class="admin-form-section" aria-labelledby="course-template-activity-basic-title">
        <h2 id="course-template-activity-basic-title" class="admin-form-section-title">
            {{ __('lf.LF_course_template_activity_group_basic') }}
        </h2>

        <div class="lf-form-group">
            <x-form-label for="activity_type"
                          :value="__('lf.LF_course_template_activity_common_type')"
                          :required="$isRequired('activity_type')" />
            <select id="activity_type" name="activity_type"
                    class="lf-form-control" x-model="activityType" required>
                <option value="" disabled>
                    {{ __('lf.LF_course_template_activity_common_select_type') }}
                </option>
                @foreach ($activityTypes as $activityType)
                    <option value="{{ $activityType }}"
                            @selected($selectedActivityType === $activityType)>
                        {{ __('lf.LF_course_template_activity_common_type_'.$activityType) }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="lf-form-group">
            <x-form-label for="title"
                          :value="__('lf.LF_course_template_activity_common_name')"
                          :required="$isRequired('title')" />
            <input id="title" type="text" name="title" class="lf-form-control"
                   value="{{ old('title', $formActivity?->title) }}"
                   required maxlength="255">
        </div>

        <div class="lf-form-group">
            <x-form-label for="description"
                          :value="__('lf.LF_course_template_activity_common_description')"
                          :required="$isRequired('description')" />
            <textarea id="description" name="description"
                      class="lf-form-control"
                      rows="4">{{ old('description', $formActivity?->description) }}</textarea>
        </div>

        <div class="lf-form-group">
            <x-form-label for="sort_order"
                          :value="__('lf.LF_course_template_activity_common_sort_order')"
                          :required="$isRequired('sort_order')" />
            <input id="sort_order" type="number" min="0" name="sort_order"
                   class="lf-form-control"
                   value="{{ old('sort_order', $formActivity?->sort_order ?? 0) }}"
                   required>
        </div>
    </section>

    <section class="admin-form-section" aria-labelledby="course-template-activity-content-title">
        <h2 id="course-template-activity-content-title" class="admin-form-section-title">
            {{ __('lf.LF_course_template_activity_group_content') }}
        </h2>

        <div class="lf-form-group"
             x-show='@json($referenceActivityTypes).includes(activityType)'>
            <x-form-label for="activity_ref_type"
                          :value="__('lf.LF_course_template_activity_common_ref_type')"
                          :required="$isRequired('activity_ref_type')" />
            <input id="activity_ref_type" type="text"
                   name="activity_ref_type" class="lf-form-control"
                   value="{{ old('activity_ref_type', $formActivity?->activity_ref_type) }}"
                   x-bind:disabled='! @json($referenceActivityTypes).includes(activityType)'
                   maxlength="100">
        </div>

        <div class="lf-form-group"
             x-show='@json($referenceActivityTypes).includes(activityType)'>
            <x-form-label for="activity_ref_id"
                          :value="__('lf.LF_course_template_activity_common_ref_id')"
                          :required="$isRequired('activity_ref_id')" />
            <input id="activity_ref_id" type="number" min="1"
                   name="activity_ref_id" class="lf-form-control"
                   value="{{ old('activity_ref_id', $formActivity?->activity_ref_id) }}"
                   x-bind:disabled='! @json($referenceActivityTypes).includes(activityType)'>
        </div>

        <div class="lf-form-group" x-show="activityType === 'external_link'">
            <x-form-label for="external_url"
                          :value="__('lf.LF_course_template_activity_common_external_url')"
                          :required="true" />
            <input id="external_url" type="url" name="external_url"
                   class="lf-form-control"
                   value="{{ old('external_url', $formActivity?->external_url) }}"
                   x-bind:disabled="activityType !== 'external_link'"
                   x-bind:required="activityType === 'external_link'"
                   maxlength="1000">
        </div>

        <div class="lf-form-group">
            <x-form-label for="embed_code"
                          :value="__('lf.LF_course_template_activity_common_embed_code')"
                          :required="$isRequired('embed_code')" />
            <textarea id="embed_code" name="embed_code"
                      class="lf-form-control"
                      rows="4">{{ old('embed_code', $formActivity?->embed_code) }}</textarea>
        </div>

        <div class="lf-form-group"
             x-show='@json($manualDurationTypes).includes(activityType)'>
            <x-form-label for="duration_seconds"
                          :value="__('lf.LF_course_template_activity_common_duration_seconds')"
                          :required="$isRequired('duration_seconds')" />
            <input id="duration_seconds" type="number" min="0"
                   name="duration_seconds" class="lf-form-control"
                   value="{{ old('duration_seconds', $formActivity?->duration_seconds ?? 0) }}"
                   x-bind:disabled='! @json($manualDurationTypes).includes(activityType)'>
        </div>
    </section>

    <section class="admin-form-section" aria-labelledby="course-template-activity-media-title">
        <h2 id="course-template-activity-media-title" class="admin-form-section-title">
            Activity media
        </h2>

        @if (($activityMedia ?? collect())->isNotEmpty())
            <div class="admin-table-wrapper">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>File</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($activityMedia as $media)
                            <tr>
                                <td>{{ $media->usage_type }}</td>
                                <td>{{ $media->original_name }}</td>
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
            <x-form-label for="activity_video_file" value="Video file" />
            <input id="activity_video_file" type="file"
                   name="activity_video_file" class="lf-form-control"
                   accept="video/*">
            <x-upload-hint :formats="['MP4', 'WEBM', 'MOV', 'AVI']" />
        </div>

        <div class="lf-form-group">
            <x-form-label for="activity_audio_file" value="Audio file" />
            <input id="activity_audio_file" type="file"
                   name="activity_audio_file" class="lf-form-control"
                   accept="audio/*">
            <x-upload-hint :formats="['MP3', 'WAV', 'OGG', 'WEBM', 'M4A', 'AAC']" />
        </div>

        <div class="lf-form-group">
            <x-form-label for="activity_document_file" value="Document file" />
            <input id="activity_document_file" type="file"
                   name="activity_document_file" class="lf-form-control"
                   accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,application/pdf">
            <x-upload-hint :formats="['PDF', 'DOC', 'DOCX', 'XLS', 'XLSX', 'PPT', 'PPTX', 'TXT']" />
        </div>

        <div class="lf-form-group">
            <x-form-label for="activity_attachment_file" value="Attachment file" />
            <input id="activity_attachment_file" type="file"
                   name="activity_attachment_file" class="lf-form-control">
            <x-upload-hint :formats="['PDF', 'DOC', 'DOCX', 'XLS', 'XLSX', 'PPT', 'PPTX', 'TXT']" />
        </div>
    </section>

    <section class="admin-form-section" aria-labelledby="course-template-activity-completion-title">
        <h2 id="course-template-activity-completion-title" class="admin-form-section-title">
            {{ __('lf.LF_course_template_activity_group_completion') }}
        </h2>

        <div class="lf-form-group">
            <x-form-label for="is_required"
                          :value="__('lf.LF_course_template_activity_common_required')"
                          :required="$isRequired('is_required')" />
            <select id="is_required" name="is_required"
                    class="lf-form-control" required>
                <option value="1" @selected($selectedRequired === '1')>
                    {{ __('lf.LF_course_template_activity_common_yes') }}
                </option>
                <option value="0" @selected($selectedRequired === '0')>
                    {{ __('lf.LF_course_template_activity_common_no') }}
                </option>
            </select>
        </div>

        <div class="lf-form-group">
            <x-form-label for="completion_rule"
                          :value="__('lf.LF_course_template_activity_common_completion_rule')"
                          :required="$isRequired('completion_rule')" />
            <select id="completion_rule" name="completion_rule"
                    class="lf-form-control" required>
                @foreach ([
                    'view',
                    'watch_percent',
                    'submit',
                    'pass',
                    'attend',
                    'manual',
                ] as $completionRule)
                    <option value="{{ $completionRule }}"
                            @selected($selectedCompletionRule === $completionRule)>
                        {{ __('lf.LF_course_template_activity_common_completion_'.$completionRule) }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="lf-form-group">
            <x-form-label for="completion_threshold"
                          :value="__('lf.LF_course_template_activity_common_completion_threshold')"
                          :required="$isRequired('completion_threshold')" />
            <input id="completion_threshold" type="number" min="0"
                   name="completion_threshold" class="lf-form-control"
                   value="{{ old(
                       'completion_threshold',
                       $formActivity?->completion_threshold
                   ) }}">
        </div>

        <div class="lf-form-group">
            <x-form-label for="is_preview"
                          :value="__('lf.LF_course_template_activity_common_preview')"
                          :required="$isRequired('is_preview')" />
            <select id="is_preview" name="is_preview"
                    class="lf-form-control" required>
                <option value="0" @selected($selectedPreview === '0')>
                    {{ __('lf.LF_course_template_activity_common_no') }}
                </option>
                <option value="1" @selected($selectedPreview === '1')>
                    {{ __('lf.LF_course_template_activity_common_yes') }}
                </option>
            </select>
        </div>
    </section>

    <section class="admin-form-section" aria-labelledby="course-template-activity-unlock-title">
        <h2 id="course-template-activity-unlock-title" class="admin-form-section-title">
            {{ __('lf.LF_course_template_activity_group_unlock') }}
        </h2>

        <div class="lf-form-group">
            <x-form-label for="unlock_rule"
                          :value="__('lf.LF_course_template_activity_common_unlock_rule')"
                          :required="$isRequired('unlock_rule')" />
            <select id="unlock_rule" name="unlock_rule"
                    class="lf-form-control" required>
                @foreach ([
                    'none',
                    'previous_activity_completed',
                    'previous_lesson_completed',
                    'date_based',
                ] as $unlockRule)
                    <option value="{{ $unlockRule }}"
                            @selected($selectedUnlockRule === $unlockRule)>
                        {{ __('lf.LF_course_template_activity_common_unlock_'.$unlockRule) }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="lf-form-group">
            <x-form-label for="unlock_after_activity_id"
                          :value="__('lf.LF_course_template_activity_common_unlock_after_activity')"
                          :required="$isRequired('unlock_after_activity_id')" />
            <select id="unlock_after_activity_id"
                    name="unlock_after_activity_id" class="lf-form-control">
                <option value="">
                    {{ __('lf.LF_course_template_activity_common_no_prerequisite') }}
                </option>
                @foreach ($prerequisiteActivities as $prerequisiteActivity)
                    <option value="{{ $prerequisiteActivity->id }}"
                            @selected(
                                (string) $selectedPrerequisiteId
                                === (string) $prerequisiteActivity->id
                            )>
                        {{ $prerequisiteActivity->title }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="lf-form-group">
            <x-form-label for="unlock_at"
                          :value="__('lf.LF_course_template_activity_common_unlock_at')"
                          :required="$isRequired('unlock_at')" />
            <input id="unlock_at" type="datetime-local" name="unlock_at"
                   class="lf-form-control" value="{{ $unlockAt }}">
        </div>
    </section>

    <section class="admin-form-section" aria-labelledby="course-template-activity-status-title">
        <h2 id="course-template-activity-status-title" class="admin-form-section-title">
            {{ __('lf.LF_course_template_activity_group_status') }}
        </h2>

        <div class="lf-form-group">
            <x-form-label for="status"
                          :value="__('lf.LF_course_template_activity_common_status')"
                          :required="$isRequired('status')" />
            <select id="status" name="status" class="lf-form-control" required>
                @foreach (['draft', 'active', 'inactive', 'archived'] as $status)
                    <option value="{{ $status }}" @selected($selectedStatus === $status)>
                        {{ __('lf.LF_course_template_activity_common_'.$status) }}
                    </option>
                @endforeach
            </select>
        </div>
    </section>
</div>
