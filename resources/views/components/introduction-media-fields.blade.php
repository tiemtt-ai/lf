@props([
    'imageMedia' => null,
    'videoMedia' => null,
    'documentMedia' => null,
    'imageThumbnail' => null,
    'videoThumbnail' => null,
    'documentThumbnail' => null,
    'videoEmbedUrl' => null,
    'embedValue' => null,
    'selectedVideoSource' => null,
])

<div class="course-template-information-grid product-introduction-media admin-form-field-grid admin-form-field-grid--three"
     x-data="{
         selectedVideoSource: @js($selectedVideoSource),
         previewOpen: false,
         previewLoaded: false,
         videoSrc: '',
         preview: { name: '', url: '', mimeType: '', mediaType: '' },
         openIntroductionPreview(name, url, mimeType, mediaType) {
             this.resetIntroductionPreview();
             this.preview = { name, url: ['image', 'embed'].includes(mediaType) ? url : '', mimeType, mediaType };
             this.previewLoaded = true;
             if (mediaType === 'video') {
                 this.videoSrc = url;
                 this.$refs.introductionPreviewVideoSource?.setAttribute('src', url);
                 this.$refs.introductionPreviewVideoPlayer?.load();
             }
             this.previewOpen = true;
         },
         resetIntroductionPreview() {
             const player = this.$refs.introductionPreviewVideoPlayer;
             if (player) {
                 player.pause();
                 player.removeAttribute('src');
                 player.querySelectorAll('source').forEach(source => source.removeAttribute('src'));
                 player.load();
             }
             this.previewLoaded = false;
             this.videoSrc = '';
             this.preview = { name: '', url: '', mimeType: '', mediaType: '' };
         },
         closeIntroductionPreview() {
             this.resetIntroductionPreview();
             this.previewOpen = false;
         },
     }"
     x-on:keydown.escape.window="closeIntroductionPreview()">
    <div class="lf-form-group admin-form-field course-template-information-media">
        <p class="authoring-media-field-title">{{ __('lf.LF_course_template_intro_image') }}</p>
        <div class="authoring-media-picker-row">
        @if($imageMedia)
            <x-authoring-media-row :presentation="$imageThumbnail" :alt="__('lf.LF_course_template_intro_image')" :current-label="__('lf.LF_media_current_image')" :display-name="$imageMedia->display_name" remove-name="remove_intro_image" :remove-label="__('lf.LF_course_template_remove_current_image')">
                <button type="button" class="authoring-media-overlay-action" x-on:click.stop="openIntroductionPreview(@js($imageMedia->display_name), @js($imageMedia->signed_url), @js($imageMedia->mime_type), 'image')"><x-backend-icon name="eye" class="authoring-media-action-icon" /><span class="sr-only">{{ __('lf.LF_media_file_common_preview_action') }}</span></button>
            </x-authoring-media-row>
        @endif
        <x-authoring-media-upload
            id="intro_image_file"
            name="intro_image_file"
            :label="$imageMedia ? __('lf.LF_media_replace_image') : __('lf.LF_media_upload_image')"
            accept="image/*"
            :aria-invalid="$errors->has('intro_image_file') ? 'true' : null"
            :aria-describedby="$errors->has('intro_image_file') ? 'intro_image_file_error' : null" />
        </div>
        @error('intro_image_file')<p id="intro_image_file_error" class="lf-form-error">{{ $message }}</p>@enderror
        <x-upload-hint :formats="['JPG', 'PNG', 'GIF', 'WEBP', 'SVG']" />
    </div>

    <div class="lf-form-group admin-form-field course-template-information-media">
        <p class="authoring-media-field-title">{{ __('lf.LF_course_template_intro_video') }}</p>
        <label for="intro_video_source" class="sr-only">{{ __('lf.LF_course_template_video_source') }}</label>
        <select id="intro_video_source" name="intro_video_source" class="lf-form-control" x-model="selectedVideoSource" :class="{ 'lf-select-placeholder': !selectedVideoSource }" @error('intro_video_source') aria-invalid="true" aria-describedby="intro_video_source_error" @enderror>
            <option value="">{{ __('lf.LF_course_template_select_video_source') }}</option>
            <option value="upload">{{ __('lf.LF_course_template_video_upload') }}</option>
            <option value="embed">{{ __('lf.LF_course_template_video_embed') }}</option>
        </select>
        @error('intro_video_source')<p id="intro_video_source_error" class="lf-form-error">{{ $message }}</p>@enderror
        <div class="authoring-media-picker-row" x-show="selectedVideoSource === 'upload' || @js((bool) ($videoMedia || $videoEmbedUrl))">
        @if($videoMedia || $videoEmbedUrl)
            <x-authoring-media-row :presentation="$videoThumbnail" :alt="__('lf.LF_course_template_intro_video')" :current-label="__('lf.LF_media_current_video')" :display-name="$videoMedia?->display_name ?? 'Video'" remove-name="remove_intro_video" :remove-label="__('lf.LF_course_template_remove_current_video')">
                @if($videoMedia)
                    <button type="button" class="authoring-media-overlay-action" x-on:click.stop="openIntroductionPreview(@js($videoMedia->display_name), @js($videoMedia->signed_url), @js($videoMedia->mime_type), 'video')"><x-backend-icon name="eye" class="authoring-media-action-icon" /><span class="sr-only">{{ __('lf.LF_media_file_common_preview_action') }}</span></button>
                @else
                    <button type="button" class="authoring-media-overlay-action" x-on:click.stop="openIntroductionPreview(@js(ucfirst((string) $videoMedia?->display_name ?: 'Video')), @js($videoEmbedUrl), 'text/html', 'embed')"><x-backend-icon name="eye" class="authoring-media-action-icon" /><span class="sr-only">{{ __('lf.LF_media_file_common_preview_action') }}</span></button>
                @endif
            </x-authoring-media-row>
        @endif
        <div class="authoring-media-upload-wrapper" x-show="selectedVideoSource === 'upload'">
            <x-authoring-media-upload
                id="intro_video_file"
                name="intro_video_file"
                :label="($videoMedia || $videoEmbedUrl) ? __('lf.LF_media_replace_video') : __('lf.LF_media_upload_video')"
                accept="video/*"
                x-bind:disabled="selectedVideoSource !== 'upload'"
                :aria-invalid="$errors->has('intro_video_file') ? 'true' : null"
                :aria-describedby="$errors->has('intro_video_file') ? 'intro_video_file_error' : null" />
        </div>
        </div>
        @error('intro_video_file')<p id="intro_video_file_error" class="lf-form-error">{{ $message }}</p>@enderror
        <x-upload-hint :formats="['MP4', 'WEBM', 'MOV', 'AVI']" x-show="selectedVideoSource === 'upload'" />
        <input id="intro_video_embed_url" type="url" name="intro_video_embed_url" class="lf-form-control" value="{{ old('intro_video_embed_url', $embedValue) }}" placeholder="{{ __('lf.LF_course_template_placeholder_embed_url') }}" x-show="selectedVideoSource === 'embed'" :disabled="selectedVideoSource !== 'embed'" @error('intro_video_embed_url') aria-invalid="true" aria-describedby="intro_video_embed_url_error" @enderror>
        @error('intro_video_embed_url')<p id="intro_video_embed_url_error" class="lf-form-error">{{ $message }}</p>@enderror
    </div>

    <div class="lf-form-group admin-form-field course-template-information-media">
        <p class="authoring-media-field-title">{{ __('lf.LF_course_template_intro_document') }}</p>
        <div class="authoring-media-picker-row">
        @if($documentMedia)
            <x-authoring-media-row :presentation="$documentThumbnail" :alt="__('lf.LF_course_template_intro_document')" :current-label="__('lf.LF_media_current_document')" :display-name="$documentMedia->display_name" remove-name="remove_intro_document" :remove-label="__('lf.LF_course_template_remove_current_document')">
                <a class="authoring-media-overlay-action" href="{{ $documentMedia->signed_url }}" target="_blank" rel="noopener noreferrer"><x-backend-icon name="eye" class="authoring-media-action-icon" /><span class="sr-only">{{ __('lf.LF_media_file_common_preview_action') }}</span></a>
            </x-authoring-media-row>
        @endif
        <x-authoring-media-upload
            id="intro_document_file"
            name="intro_document_file"
            :label="$documentMedia ? __('lf.LF_media_replace_document') : __('lf.LF_media_upload_document')"
            accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx"
            :aria-invalid="$errors->has('intro_document_file') ? 'true' : null"
            :aria-describedby="$errors->has('intro_document_file') ? 'intro_document_file_error' : null" />
        </div>
        @error('intro_document_file')<p id="intro_document_file_error" class="lf-form-error">{{ $message }}</p>@enderror
        <x-upload-hint :formats="['PDF', 'DOC', 'DOCX', 'PPT', 'PPTX', 'XLS', 'XLSX']" />
    </div>

    <div class="media-library-modal" x-cloak x-show="previewOpen" x-transition.opacity role="dialog" aria-modal="true" aria-labelledby="product-introduction-preview-title">
        <button type="button" class="media-library-modal-backdrop" aria-label="{{ __('lf.LF_common_button_cancel') }}" x-on:click="closeIntroductionPreview()"></button>
        <div class="media-library-modal-panel">
            <div class="media-library-modal-header">
                <h2 id="product-introduction-preview-title" x-text="preview.name"></h2>
                <button type="button" class="admin-link-button admin-text-action" x-on:click="closeIntroductionPreview()">{{ __('lf.LF_common_button_cancel') }}</button>
            </div>
            <div class="media-library-modal-body">
                <template x-if="previewLoaded && preview.mediaType === 'image'"><img :src="preview.url" :alt="preview.name" class="media-library-modal-image"></template>
                <video x-ref="introductionPreviewVideoPlayer" x-show="previewLoaded && preview.mediaType === 'video'" controls preload="metadata" class="media-library-modal-video"><source x-ref="introductionPreviewVideoSource" :src="videoSrc" :type="preview.mimeType"></video>
                <iframe class="media-library-modal-video" x-show="previewLoaded && preview.mediaType === 'embed'" :src="preview.mediaType === 'embed' ? preview.url : ''" :title="preview.name" loading="lazy" sandbox="allow-scripts allow-same-origin allow-presentation" allow="fullscreen; picture-in-picture" referrerpolicy="strict-origin-when-cross-origin"></iframe>
            </div>
        </div>
    </div>
</div>
