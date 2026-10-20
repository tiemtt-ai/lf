@php
    $formTemplate = $template ?? null;
    $archivedTemplate = $formTemplate?->status === \App\Support\CourseTemplateStatus::ARCHIVED;
    $selectedCategoryId = $formTemplate?->category_id;
    $selectedVideoSource = $formTemplate?->intro_video_source;
    $selectedDifficulty = $formTemplate?->difficulty_level;
    $selectedStatus = $formTemplate?->status ?? \App\Support\CourseTemplateStatus::DEFAULT;
    $isRequired = static fn (string $field): bool => in_array($field, $requiredFields, true);
    $star = '<span class="ui-field__required" aria-hidden="true">*</span>';
    $documentKind = $introDocumentThumbnail['kind'] ?? 'document';
@endphp

<div x-data="{
         savedVideoSource: @js($selectedVideoSource),
         videoFileChosen: false,
         embedUrl: @js($selectedVideoSource === 'embed' ? (string) $formTemplate?->intro_video_embed_url : ''),
         previewOpen: false,
         preview: { name: '', url: '', mimeType: '', mediaType: '' },
         openPreview(detail) {
             if (! ['image', 'video', 'embed'].includes(detail.kind)) {
                 window.open(detail.url, '_blank', 'noopener');
                 return;
             }
             this.preview = { name: detail.name, url: detail.url, mimeType: detail.mimeType || '', mediaType: detail.kind };
             this.previewOpen = true;
         },
         closePreview() {
             this.previewOpen = false;
             this.preview = { name: '', url: '', mimeType: '', mediaType: '' };
         },
     }"
     x-on:ui-media-preview="openPreview($event.detail)">
    <fieldset class="ui-fieldset-reset" @disabled($archivedTemplate)>
        <div class="ui-modal__grid">
            <div class="ui-field ui-field--full">
                <label class="ui-field__label" for="template-form-title">{!! $star !!}{{ __('lf.LF_course_template_common_name') }}</label>
                <input id="template-form-title" type="text" name="title" value="{{ $formTemplate?->title }}"
                       required maxlength="255" placeholder="{{ __('lf.LF_course_template_placeholder_name') }}">
            </div>

            <div class="ui-field">
                <label class="ui-field__label" for="template-form-category_id">@if ($isRequired('category_id')){!! $star !!}@endif{{ __('lf.LF_course_template_common_category') }}</label>
                <select id="template-form-category_id" name="category_id" data-ui-kit-select @if ($isRequired('category_id')) required @endif
                        data-ui-empty-label="{{ __('lf.LF_course_template_index_no_options') }}" data-ui-clear-label="{{ __('lf.LF_course_template_common_clear_filters') }}">
                    <option value="" @selected($selectedCategoryId === null)>{{ __('lf.LF_course_template_select_category') }}</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected((string) $selectedCategoryId === (string) $category->id)>{{ $category->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="ui-field">
                <label class="ui-field__label" for="template-form-publisher_name">@if ($isRequired('publisher_name')){!! $star !!}@endif{{ __('lf.LF_course_template_common_publisher_name') }}</label>
                <input id="template-form-publisher_name" type="text" name="publisher_name" value="{{ $formTemplate?->publisher_name }}"
                       maxlength="255" placeholder="{{ __('lf.LF_course_template_placeholder_publisher') }}" @if ($isRequired('publisher_name')) required @endif>
            </div>

            <div class="ui-field">
                <label class="ui-field__label" for="template-form-difficulty_level">{{ __('lf.LF_course_template_common_difficulty_level') }}</label>
                <select id="template-form-difficulty_level" name="difficulty_level" data-ui-kit-select
                        data-ui-empty-label="{{ __('lf.LF_course_template_index_no_options') }}" data-ui-clear-label="{{ __('lf.LF_course_template_common_clear_filters') }}">
                    <option value="" @selected($selectedDifficulty === null || $selectedDifficulty === '')>{{ __('lf.LF_course_template_select_difficulty') }}</option>
                    @foreach (['beginner', 'intermediate', 'advanced'] as $difficulty)
                        <option value="{{ $difficulty }}" @selected($selectedDifficulty === $difficulty)>{{ __('lf.LF_course_template_common_'.$difficulty) }}</option>
                    @endforeach
                </select>
            </div>

            <div class="ui-field">
                <label class="ui-field__label" for="template-form-estimated_minutes_per_lesson">{{ __('lf.LF_course_template_estimated_minutes_per_lesson') }}</label>
                <input id="template-form-estimated_minutes_per_lesson" type="number" min="1" name="estimated_minutes_per_lesson"
                       value="{{ $formTemplate?->estimated_minutes_per_lesson }}" placeholder="{{ __('lf.LF_course_template_placeholder_minutes') }}">
            </div>

            <div class="ui-field">
                <label class="ui-field__label" for="template-form-estimated_lesson_count">{{ __('lf.LF_course_template_estimated_lesson_count') }}</label>
                <input id="template-form-estimated_lesson_count" type="number" min="1" name="estimated_lesson_count"
                       value="{{ $formTemplate?->estimated_lesson_count }}" placeholder="{{ __('lf.LF_course_template_placeholder_lesson_count') }}">
            </div>

            <div class="ui-field">
                @if ($archivedTemplate)
                    <span class="ui-field__label">{{ __('lf.LF_course_template_common_status') }}</span>
                    <span class="ui-status ui-status--archived">{{ __('lf.LF_course_template_common_archived') }}</span>
                @else
                    <label class="ui-field__label" for="template-form-status">{!! $star !!}{{ __('lf.LF_course_template_common_status') }}</label>
                    <select id="template-form-status" name="status" data-ui-kit-select required
                            data-ui-empty-label="{{ __('lf.LF_course_template_index_no_options') }}" data-ui-clear-label="{{ __('lf.LF_course_template_common_clear_filters') }}">
                        @foreach (\App\Support\CourseTemplateStatus::EDITABLE_VALUES as $templateStatus)
                            <option value="{{ $templateStatus }}" @selected($selectedStatus === $templateStatus)>{{ __('lf.LF_course_template_common_'.$templateStatus) }}</option>
                        @endforeach
                    </select>
                @endif
            </div>

            <div class="ui-field ui-field--full"
                 x-on:change="if ($event.target.name === 'intro_video_file') { videoFileChosen = ($event.target.files?.length ?? 0) > 0; if (videoFileChosen) $root.querySelector('[data-ui-embed-slot]')?.dispatchEvent(new CustomEvent('ui-embed-clear')); }"
                 x-on:ui-embed-change="embedUrl = $event.detail.url; if (embedUrl) { videoFileChosen = false; $root.querySelector('[data-ui-media-slot][data-kind=video]')?.dispatchEvent(new CustomEvent('ui-media-clear')); }">
                {{-- One intro video: an uploaded file OR an embedded link. The source follows whichever the user last set. --}}
                <input type="hidden" name="intro_video_source" :value="embedUrl ? 'embed' : (videoFileChosen ? 'upload' : (savedVideoSource === 'upload' ? 'upload' : ''))">
                <div class="ui-field-row">
                    <div class="ui-field">
                        <p class="ui-field__label" id="template-intro-image-label">{{ __('lf.LF_course_template_intro_image') }}</p>
                        <input type="hidden" name="intro_image_media_file_id" value="{{ $formTemplate?->intro_image_media_file_id }}">
                        <x-ui-media-slot id="intro_image_file" name="intro_image_file" accept="image/*" kind="image"
                            labelledby="template-intro-image-label"
                            :presentation="$introImageMedia ? $introImageThumbnail : null"
                            :display-name="$introImageMedia?->display_name"
                            :preview-url="$introImageMedia?->signed_url"
                            :mime="$introImageMedia?->mime_type"
                            remove-name="remove_intro_image"
                            :view-label="__('lf.LF_media_file_common_preview_action')"
                            :remove-label="__('lf.LF_course_template_remove_current_image')">
                            <x-upload-hint ui-kit :formats="['JPG', 'PNG', 'GIF', 'WEBP', 'SVG']" />
                        </x-ui-media-slot>
                    </div>

                    <div class="ui-field">
                        <p class="ui-field__label" id="template-intro-video-label">{{ __('lf.LF_course_template_intro_video') }}</p>
                        <input type="hidden" name="intro_video_media_file_id" value="{{ $formTemplate?->intro_video_media_file_id }}">
                        <x-ui-media-slot id="intro_video_file" name="intro_video_file"
                            accept="video/*" kind="video" focus-alias="intro_video_source"
                            labelledby="template-intro-video-label"
                            :presentation="$introVideoMedia ? $introVideoThumbnail : null"
                            :display-name="$introVideoMedia?->display_name"
                            :preview-url="$introVideoMedia?->signed_url"
                            :mime="$introVideoMedia?->mime_type"
                            remove-name="remove_intro_video"
                            :view-label="__('lf.LF_media_file_common_preview_action')"
                            :remove-label="__('lf.LF_course_template_remove_current_video')">
                            <x-upload-hint ui-kit :formats="['MP4', 'WEBM', 'MOV', 'AVI']" :max-size="\App\Support\UploadLimit::effectiveVideoKilobytes()" />
                        </x-ui-media-slot>
                    </div>

                    <div class="ui-field">
                        <p class="ui-field__label" id="template-intro-embed-label">{{ __('lf.LF_course_template_video_embed') }}</p>
                        <x-ui-embed-slot id="template-form-intro_video_embed_url" name="intro_video_embed_url"
                            labelledby="template-intro-embed-label"
                            :value="$selectedVideoSource === 'embed' ? $formTemplate?->intro_video_embed_url : null"
                            :thumbnail="($introVideoThumbnail['state'] ?? null) === 'provider_video_thumbnail' ? ($introVideoThumbnail['url'] ?? null) : null"
                            :preview-url="$introVideoEmbedUrl"
                            :display-name="ucfirst((string) $formTemplate?->intro_video_provider)">
                            {{ __('lf.LF_course_template_embed_hint') }}
                        </x-ui-embed-slot>
                    </div>

                    <div class="ui-field">
                        <p class="ui-field__label" id="template-intro-document-label">{{ __('lf.LF_course_template_intro_document') }}</p>
                        <input type="hidden" name="intro_document_media_file_id" value="{{ $formTemplate?->intro_document_media_file_id }}">
                        <x-ui-media-slot id="intro_document_file" name="intro_document_file"
                            accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx" :kind="$documentKind"
                            labelledby="template-intro-document-label"
                            :presentation="$introDocumentMedia ? $introDocumentThumbnail : null"
                            :display-name="$introDocumentMedia?->display_name"
                            :preview-url="$introDocumentMedia?->signed_url"
                            :mime="$introDocumentMedia?->mime_type"
                            remove-name="remove_intro_document"
                            :view-label="__('lf.LF_media_file_common_preview_action')"
                            :remove-label="__('lf.LF_course_template_remove_current_document')">
                            <x-upload-hint ui-kit :formats="['PDF', 'DOC', 'DOCX', 'PPT', 'PPTX', 'XLS', 'XLSX']" />
                        </x-ui-media-slot>
                    </div>
                </div>
            </div>

            <div class="ui-field ui-field--full">
                <label class="ui-field__label" for="template-form-short_description">{{ __('lf.LF_course_template_common_short_description') }}</label>
                <textarea id="template-form-short_description" name="short_description" rows="3" maxlength="500"
                          placeholder="{{ __('lf.LF_course_template_placeholder_short_description') }}">{{ $formTemplate?->short_description }}</textarea>
            </div>

            <div class="ui-field ui-field--full">
                <label class="ui-field__label" for="template-form-description">{{ __('lf.LF_course_template_common_description') }}</label>
                <textarea id="template-form-description" name="description" rows="4"
                          placeholder="{{ __('lf.LF_course_template_placeholder_description') }}">{{ $formTemplate?->description }}</textarea>
            </div>
        </div>
    </fieldset>

    <x-media-preview-modal ui-kit id="course-template-preview-title" show="previewOpen" close="closePreview()" title="preview.name" compact="false">
        <template x-if="previewOpen && preview.mediaType === 'image'">
            <img x-bind:src="preview.url" x-bind:alt="preview.name">
        </template>
        <template x-if="previewOpen && preview.mediaType === 'video'">
            <video controls preload="metadata" autoplay x-bind:src="preview.url"></video>
        </template>
        <template x-if="previewOpen && preview.mediaType === 'embed'">
            <iframe x-bind:src="preview.url" x-bind:title="preview.name" loading="lazy"
                    sandbox="allow-scripts allow-same-origin allow-presentation"
                    allow="fullscreen; picture-in-picture" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>
        </template>
    </x-media-preview-modal>
</div>
