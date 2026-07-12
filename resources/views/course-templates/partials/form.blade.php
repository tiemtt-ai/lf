@php
    $formTemplate = $template ?? null;
    $selectedCategoryId = old('category_id', $formTemplate?->category_id);
    $selectedVideoSource = old('intro_video_source', $formTemplate?->intro_video_source);
    $selectedDifficulty = old('difficulty_level', $formTemplate?->difficulty_level);
    $selectedStatus = old('status', $formTemplate?->status ?? 'draft');
    $isRequired = static fn (string $field): bool => in_array($field, $requiredFields, true);
@endphp

<div class="backend-form-columns course-template-information-grid"
     x-data="{
         selectedVideoSource: @js($selectedVideoSource),
         selectedCategoryId: @js($selectedCategoryId),
         selectedDifficulty: @js($selectedDifficulty),
         previewOpen: false,
         previewLoaded: false,
         videoSrc: '',
         preview: {
             name: '',
             url: '',
             mimeType: '',
             mediaType: '',
         },
         openTemplatePreview(name, url, mimeType, mediaType) {
             this.resetTemplatePreview();
             this.preview = { name, url: ['image', 'embed'].includes(mediaType) ? url : '', mimeType, mediaType };
             this.previewLoaded = true;

             if (mediaType === 'video') {
                 this.videoSrc = url;
                 this.$refs.templatePreviewVideoSource?.setAttribute('src', url);
                 this.$refs.templatePreviewVideoPlayer?.load();
             }

             this.previewOpen = true;
             this.$nextTick(() => {
                 if (mediaType === 'video') {
                     this.playTemplatePreviewVideo();
                 }
             });
         },
         playTemplatePreviewVideo() {
             const player = this.$refs.templatePreviewVideoPlayer;

             if (! player) {
                 return;
             }

             player.muted = false;
             const playAttempt = player.play();

             if (playAttempt !== undefined) {
                 playAttempt.catch(() => {
                     player.muted = true;
                     player.play().catch(() => {});
                 });
             }
         },
         resetTemplatePreview() {
             const player = this.$refs.templatePreviewVideoPlayer;

             if (player) {
                 player.pause();
                 player.muted = false;
                 player.removeAttribute('src');
                 player.querySelectorAll('source').forEach((source) => {
                     source.removeAttribute('src');
                 });
                 player.load();
             }

             this.previewLoaded = false;
             this.videoSrc = '';
             this.preview = { name: '', url: '', mimeType: '', mediaType: '' };
         },
         closeTemplatePreview() {
             this.resetTemplatePreview();
             this.previewOpen = false;
         },
     }"
     x-on:keydown.escape.window="closeTemplatePreview()">
    <div class="backend-form-column">
        <div class="lf-form-group">
            <x-form-label for="category_id"
                          :value="__('lf.LF_course_template_common_category')"
                          :required="$isRequired('category_id')" />
            <select id="category_id" name="category_id" class="lf-form-control" x-model="selectedCategoryId" :class="{ 'lf-select-placeholder': selectedCategoryId === null || selectedCategoryId === '' }">
                <option value="" @selected($selectedCategoryId === null || $selectedCategoryId === '')>{{ __('lf.LF_course_template_select_category') }}</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}"
                            @selected((string) $selectedCategoryId === (string) $category->id)>
                        {{ $category->name }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="lf-form-group">
            <x-form-label for="title"
                          :value="__('lf.LF_course_template_common_name')"
                          :required="$isRequired('title')" />
            <input id="title" type="text" name="title" class="lf-form-control"
                   value="{{ old('title', $formTemplate?->title) }}"
                   required
                   maxlength="255" placeholder="{{ __('lf.LF_course_template_placeholder_name') }}">
        </div>

        <div class="lf-form-group course-template-information-wide">
            <x-form-label for="short_description" :value="__('lf.LF_course_template_common_short_description')" />
            <textarea id="short_description" name="short_description" class="lf-form-control" rows="2" maxlength="500" placeholder="{{ __('lf.LF_course_template_placeholder_short_description') }}">{{ old('short_description', $formTemplate?->short_description) }}</textarea>
        </div>

        <div class="lf-form-group course-template-information-wide">
            <x-form-label for="description" :value="__('lf.LF_course_template_common_description')" />
            <textarea id="description" name="description" class="lf-form-control" rows="5" placeholder="{{ __('lf.LF_course_template_placeholder_description') }}">{{ old('description', $formTemplate?->description) }}</textarea>
        </div>
    </div>

    <div class="backend-form-column">
        <div class="lf-form-group course-template-information-media">
            <x-form-label for="intro_image_file" :value="__('lf.LF_course_template_intro_image')" />
            <input type="hidden" name="intro_image_media_file_id" value="{{ old('intro_image_media_file_id', $formTemplate?->intro_image_media_file_id) }}">
            <input id="intro_image_file" type="file" name="intro_image_file" class="lf-form-control" accept="image/*">
            <x-upload-hint :formats="['JPG', 'PNG', 'GIF', 'WEBP', 'SVG']" />
            @if ($introImageMedia ?? null)
                <div class="course-template-preview-card">
                    <div class="course-template-preview-thumb"><img src="{{ $introImageMedia->signed_url }}" alt="{{ $introImageMedia->display_name }}" loading="lazy" decoding="async"></div>
                    <button type="button" class="admin-link-button admin-text-action" x-on:click.stop="openTemplatePreview(@js($introImageMedia->display_name), @js($introImageMedia->signed_url), @js($introImageMedia->mime_type), 'image')">{{ __('lf.LF_media_file_common_preview_action') }}</button>
                    <label class="course-template-preview-remove" for="remove_intro_image"><input id="remove_intro_image" type="checkbox" name="remove_intro_image" value="1"> {{ __('lf.LF_course_template_remove_current') }}</label>
                </div>
            @endif
        </div>

        <div class="lf-form-group course-template-information-media">
            <x-form-label for="intro_video_source" :value="__('lf.LF_course_template_intro_video')" />
            <select id="intro_video_source" name="intro_video_source" class="lf-form-control" x-model="selectedVideoSource" :class="{ 'lf-select-placeholder': selectedVideoSource === null || selectedVideoSource === '' }">
                <option value="">{{ __('lf.LF_course_template_select_video_source') }}</option>
                <option value="upload">{{ __('lf.LF_course_template_video_upload') }}</option>
                <option value="embed">{{ __('lf.LF_course_template_video_embed') }}</option>
            </select>
            <input type="hidden" name="intro_video_media_file_id" value="{{ old('intro_video_media_file_id', $formTemplate?->intro_video_media_file_id) }}" :disabled="selectedVideoSource !== 'upload'">
            <input type="file" name="intro_video_file" class="lf-form-control" accept="video/*" x-show="selectedVideoSource === 'upload'" :disabled="selectedVideoSource !== 'upload'">
            <x-upload-hint :formats="['MP4', 'WEBM', 'MOV', 'AVI']" x-show="selectedVideoSource === 'upload'" />
            <input type="url" name="intro_video_embed_url" class="lf-form-control" value="{{ old('intro_video_embed_url', $formTemplate?->intro_video_embed_url) }}" placeholder="{{ __('lf.LF_course_template_placeholder_embed_url') }}" x-show="selectedVideoSource === 'embed'" :disabled="selectedVideoSource !== 'embed'">
            @if (($introVideoMedia ?? null) || ($introVideoEmbedUrl ?? null))
                <div class="course-template-preview-card">
                    <div class="course-template-preview-thumb course-template-preview-thumb-video"><x-backend-icon name="video" class="course-template-preview-icon" /></div>
                    @if ($introVideoMedia ?? null)
                        <button type="button" class="admin-link-button admin-text-action" x-on:click.stop="openTemplatePreview(@js($introVideoMedia->display_name), @js($introVideoMedia->signed_url), @js($introVideoMedia->mime_type), 'video')">{{ __('lf.LF_media_file_common_preview_action') }}</button>
                    @else
                        <button type="button" class="admin-link-button admin-text-action" x-on:click.stop="openTemplatePreview(@js(ucfirst((string) $formTemplate?->intro_video_provider)), @js($introVideoEmbedUrl), 'text/html', 'embed')">{{ __('lf.LF_media_file_common_preview_action') }}</button>
                    @endif
                    <label class="course-template-preview-remove" for="remove_intro_video"><input id="remove_intro_video" type="checkbox" name="remove_intro_video" value="1"> {{ __('lf.LF_course_template_remove_current') }}</label>
                </div>
            @endif
        </div>

        <div class="lf-form-group course-template-information-media">
            <x-form-label for="intro_document_file" :value="__('lf.LF_course_template_intro_document')" />
            <input type="hidden" name="intro_document_media_file_id" value="{{ old('intro_document_media_file_id', $formTemplate?->intro_document_media_file_id) }}">
            <input id="intro_document_file" type="file" name="intro_document_file" class="lf-form-control">
            <x-upload-hint :formats="['PDF', 'DOC', 'DOCX', 'PPT', 'PPTX', 'XLS', 'XLSX']" />
            @if ($introDocumentMedia ?? null)
                <div class="course-template-preview-card">
                    <div class="course-template-preview-thumb course-template-preview-thumb-document"><x-backend-icon name="document" class="course-template-preview-icon" /></div>
                    <a class="admin-text-action" href="{{ $introDocumentMedia->signed_url }}" target="_blank" rel="noopener">{{ __('lf.LF_media_file_common_preview_action') }}</a>
                    <label class="course-template-preview-remove" for="remove_intro_document"><input id="remove_intro_document" type="checkbox" name="remove_intro_document" value="1"> {{ __('lf.LF_course_template_remove_current') }}</label>
                </div>
            @endif
        </div>

        <div class="lf-form-group"><x-form-label for="estimated_minutes_per_lesson" :value="__('lf.LF_course_template_estimated_minutes_per_lesson')" /><input id="estimated_minutes_per_lesson" type="number" min="1" name="estimated_minutes_per_lesson" class="lf-form-control" value="{{ old('estimated_minutes_per_lesson', $formTemplate?->estimated_minutes_per_lesson) }}" placeholder="{{ __('lf.LF_course_template_placeholder_minutes') }}"></div>
        <div class="lf-form-group"><x-form-label for="estimated_lesson_count" :value="__('lf.LF_course_template_estimated_lesson_count')" /><input id="estimated_lesson_count" type="number" min="1" name="estimated_lesson_count" class="lf-form-control" value="{{ old('estimated_lesson_count', $formTemplate?->estimated_lesson_count) }}" placeholder="{{ __('lf.LF_course_template_placeholder_lesson_count') }}"></div>
        <div class="lf-form-group"><x-form-label for="difficulty_level" :value="__('lf.LF_course_template_common_difficulty_level')" /><select id="difficulty_level" name="difficulty_level" class="lf-form-control" x-model="selectedDifficulty" :class="{ 'lf-select-placeholder': selectedDifficulty === null || selectedDifficulty === '' }"><option value="" @selected($selectedDifficulty === null || $selectedDifficulty === '')>{{ __('lf.LF_course_template_select_difficulty') }}</option>@foreach (['beginner', 'intermediate', 'advanced'] as $difficulty)<option value="{{ $difficulty }}" @selected($selectedDifficulty === $difficulty)>{{ __('lf.LF_course_template_common_'.$difficulty) }}</option>@endforeach</select></div>
        <div class="lf-form-group"><x-form-label for="publisher_name" :value="__('lf.LF_course_template_common_publisher_name')" :required="$isRequired('publisher_name')" /><input id="publisher_name" type="text" name="publisher_name" class="lf-form-control" value="{{ old('publisher_name', $formTemplate?->publisher_name) }}" maxlength="255" placeholder="{{ __('lf.LF_course_template_placeholder_publisher') }}" required></div>

        <div class="lf-form-group">
            <x-form-label for="status"
                          :value="__('lf.LF_course_template_common_status')"
                          :required="$isRequired('status')" />
            <select id="status" name="status" class="lf-form-control" required>
                <option value="draft" @selected($selectedStatus === 'draft')>
                    {{ __('lf.LF_course_template_common_draft') }}
                </option>
                <option value="active" @selected($selectedStatus === 'active')>
                    {{ __('lf.LF_course_template_common_active') }}
                </option>
                <option value="archived" @selected($selectedStatus === 'archived')>
                    {{ __('lf.LF_course_template_common_archived') }}
                </option>
            </select>
        </div>
    </div>

    <div class="media-library-modal"
         x-cloak
         x-show="previewOpen"
         x-transition.opacity
         role="dialog"
         aria-modal="true"
         aria-labelledby="course-template-preview-title">
        <button type="button"
                class="media-library-modal-backdrop"
                aria-label="{{ __('lf.LF_common_button_cancel') }}"
                x-on:click="closeTemplatePreview()"></button>

        <div class="media-library-modal-panel">
            <div class="media-library-modal-header">
                <h2 id="course-template-preview-title"
                    x-text="preview.name"></h2>
                <button type="button"
                        class="admin-link-button admin-text-action"
                        x-on:click="closeTemplatePreview()">
                    {{ __('lf.LF_common_button_cancel') }}
                </button>
            </div>

            <div class="media-library-modal-body">
                <template x-if="previewLoaded && preview.mediaType === 'image'">
                    <img x-bind:src="preview.url"
                         x-bind:alt="preview.name"
                         class="media-library-modal-image">
                </template>

                <video x-ref="templatePreviewVideoPlayer"
                       x-show="previewLoaded && preview.mediaType === 'video'"
                       controls
                       preload="metadata"
                       class="media-library-modal-video">
                    <source x-ref="templatePreviewVideoSource"
                            x-bind:src="videoSrc"
                            x-bind:type="preview.mimeType">
                </video>
                <iframe class="media-library-modal-video course-template-embed-preview"
                        x-show="previewLoaded && preview.mediaType === 'embed'"
                        x-bind:src="preview.mediaType === 'embed' ? preview.url : ''"
                        x-bind:title="preview.name"
                        loading="lazy"
                        sandbox="allow-scripts allow-same-origin allow-presentation"
                        allow="fullscreen; picture-in-picture"
                        referrerpolicy="strict-origin-when-cross-origin"
                        allowfullscreen></iframe>
            </div>
        </div>
    </div>
</div>
