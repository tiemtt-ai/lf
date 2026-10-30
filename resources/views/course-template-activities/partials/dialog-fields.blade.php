@php
    $formActivity = $activity ?? null;
    $isRequired = static fn (string $field): bool => in_array($field, $requiredFields, true);
    $star = '<span class="ui-field__required" aria-hidden="true">*</span>';
    $selectedActivityType = $formActivity?->activity_type;
    $selectedRequired = (string) ($formActivity?->is_required ?? 1);
    $selectedCompletionRule = (string) ($formActivity?->completion_rule ?? '');
    $selectedPreview = (string) ($formActivity?->is_preview ?? 0);
    $selectedUnlockRule = $formActivity?->unlock_rule ?? 'none';
    $selectedPrerequisiteId = $formActivity?->unlock_after_activity_id;
    $currentMedia = $currentActivityMedia ?? [];
    $currentUsageMetadata = json_decode((string) ($currentMedia['media']->usage_metadata ?? ''), true) ?: [];
    $storedProcessingLocales = $currentUsageMetadata['processing_locales']
        ?? (($currentMedia['media']->processing_locale ?? '') !== '' ? [$currentMedia['media']->processing_locale] : []);
    $storedSpeechToText = array_key_exists('speech_to_text_requested', $currentUsageMetadata)
        ? (bool) $currentUsageMetadata['speech_to_text_requested']
        : (bool) ($currentUsageMetadata['speech_to_text'] ?? true);
    $selectedVideoSpeechToText = (bool) ($currentUsageMetadata['speech_to_text_requested'] ?? false);
    $videoSttQualification ??= [
        'qualified' => false,
        'code' => 'evidence_missing',
        'message_key' => 'lf.LF_course_template_activity_video_stt_qualification_evidence_missing',
    ];
    $selectedLearningPhases = $formActivity
        ? array_keys(array_filter([
            'anytime' => (bool) ($formActivity->available_anytime ?? true),
            'before_session' => (bool) ($formActivity->available_before_session ?? false),
            'during_session' => (bool) ($formActivity->available_during_session ?? false),
            'after_session' => (bool) ($formActivity->available_after_session ?? false),
        ]))
        : [($presetPhase ?? null) ?: 'anytime'];
    $storedEstimateMinutes = $formActivity?->estimated_duration_seconds !== null
        ? (int) ceil($formActivity->estimated_duration_seconds / 60)
        : null;
    $storedDurationSeconds = (int) ($formActivity?->duration_seconds ?? 0);
    $completionRules = [
        'video' => ['view', 'watch_percent', 'manual'], 'audio' => ['view', 'watch_percent', 'manual'],
        'document' => ['view', 'manual'], 'embedded_video' => ['view', 'manual'],
        'quiz' => ['submit', 'pass', 'manual'], 'live_class' => ['join', 'manual'],
    ];
    $allCompletionRules = ['view', 'watch_percent', 'submit', 'pass', 'join', 'manual'];
    $completionRuleLabels = collect($allCompletionRules)
        ->mapWithKeys(fn (string $rule) => [$rule => __('lf.LF_course_template_activity_common_completion_'.$rule)])
        ->all();
    $completionRuleLabelsByType = [
        'audio' => [
            'view' => __('lf.LF_course_template_activity_common_completion_audio_view'),
            'watch_percent' => __('lf.LF_course_template_activity_common_completion_audio_watch_percent'),
        ],
    ];
    // The saved media is shown in the slot of its own type only; only that slot can mark it for removal.
    $slotMedia = static fn (string $type): ?array => ($currentMedia['type'] ?? null) === $type
        && ($currentMedia['state'] ?? 'empty') === 'available' ? $currentMedia : null;
    $selectAttributes = 'data-ui-kit-select data-ui-empty-label="'.e(__('lf.LF_course_template_lesson_dialog_no_options')).'" data-ui-clear-label="'.e(__('lf.LF_common_button_clear')).'"';
    $localeOptions = ['vi' => 'Tiếng Việt (vi)', 'ko' => 'Tiếng Hàn (ko)', 'en' => 'Tiếng Anh (en)'];
@endphp

<div x-data="{
         activityType: @js($selectedActivityType ?? ''),
         libraryChosen: { video: false, audio: false, document: false },
         get libraryMedia() { return !! this.libraryChosen[this.activityType]; },
         initialActivityType: @js($formActivity?->activity_type),
         storedDurationSeconds: @js($storedDurationSeconds),
         storedEstimateMinutes: @js($storedEstimateMinutes),
         estimatedDurationMinutes: @js($storedEstimateMinutes),
         mediaDurationSeconds: @js($storedDurationSeconds > 0 ? $storedDurationSeconds : null),
         mediaDurationState: @js($storedDurationSeconds > 0 ? 'ready' : 'empty'),
         mediaTypeError: '',
         documentIsPdf: false,
         processingLocaleRequired: false,
         audioFileSelected: false,
         speechToTextEnabled: @js($storedSpeechToText),
         videoSpeechToTextEnabled: @js($selectedVideoSpeechToText),
         videoSttQualified: @js((bool) $videoSttQualification['qualified']),
         completionRule: @js($selectedCompletionRule),
         unlockRule: @js($selectedUnlockRule),
         learningMode: @js(in_array('anytime', $selectedLearningPhases, true) ? 'anytime' : 'session'),
         sessionPhases: @js(array_values(array_diff($selectedLearningPhases, ['anytime']))),
         completionRules: @js($completionRules),
         completionRuleLabels: @js($completionRuleLabels),
         completionRuleLabelsByType: @js($completionRuleLabelsByType),
         completionPlaceholder: @js(__('lf.LF_course_template_activity_placeholder_completion_rule')),
         completionPlaceholderFirst: @js(__('lf.LF_course_template_activity_common_select_type_first')),
         previewOpen: false,
         preview: { name: '', url: '', mimeType: '', mediaType: '' },
         init() { this.$nextTick(() => this.syncCompletionOptions(false)); },
         completionRuleLabel(rule) {
             return this.completionRuleLabelsByType[this.activityType]?.[rule] ?? this.completionRuleLabels[rule];
         },
         // The rules offered depend on the type. The options are rendered once by the server; the type enables
         // the allowed ones and the combobox of the select adapter re-reads them when it opens.
         syncCompletionOptions(clear) {
             const select = this.$refs.completionRule;
             if (! select) return;
             const allowed = this.completionRules[this.activityType] || [];
             for (const option of select.options) {
                 if (option.value === '') {
                     option.textContent = this.activityType ? this.completionPlaceholder : this.completionPlaceholderFirst;
                     continue;
                 }
                 option.disabled = ! allowed.includes(option.value);
                 option.textContent = this.completionRuleLabel(option.value);
             }
             if (clear || ! allowed.includes(select.value)) select.value = '';
             this.completionRule = select.value;
             select.dispatchEvent(new Event('change', { bubbles: true }));
         },
         activityTypeChanged() {
             const automatic = ['video', 'audio'].includes(this.activityType);
             const unchanged = this.activityType === this.initialActivityType;
             this.mediaDurationSeconds = automatic && unchanged && this.storedDurationSeconds > 0 ? this.storedDurationSeconds : null;
             this.mediaDurationState = this.mediaDurationSeconds ? 'ready' : 'empty';
             this.mediaTypeError = '';
             this.documentIsPdf = false;
             this.processingLocaleRequired = false;
             this.audioFileSelected = false;
             this.videoSpeechToTextEnabled = false;
             this.estimatedDurationMinutes = unchanged ? this.storedEstimateMinutes : null;
             this.syncCompletionOptions(true);
         },
         documentFileChanged(event) {
             const file = event.target.files?.[0];
             const extension = file?.name.split('.').pop()?.toLowerCase() || '';
             this.processingLocaleRequired = Boolean(file);
             this.documentIsPdf = extension === 'pdf';
             if (! this.documentIsPdf) {
                 const checkbox = this.$root.querySelector('#activity-form-structured_extraction');
                 if (checkbox) checkbox.checked = false;
             }
         },
         readMediaDuration(event, expectedType) {
             const file = event.target.files?.[0];
             if (! file) {
                 // A cleared picker. A wrong file that was just discarded keeps its message.
                 if (this.mediaTypeError === '') this.activityTypeChanged();
                 return;
             }
             this.audioFileSelected = expectedType === 'audio';
             this.processingLocaleRequired = expectedType === 'audio' ? this.speechToTextEnabled : this.videoSpeechToTextEnabled;
             const extension = file.name.split('.').pop()?.toLowerCase() || '';
             const allowedExtensions = { video: ['mp4', 'webm', 'mov', 'avi'], audio: ['mp3', 'wav', 'm4a', 'aac', 'ogg'] };
             const matchesType = file.type.startsWith(`${expectedType}/`) && allowedExtensions[expectedType].includes(extension);
             if (! matchesType) {
                 this.processingLocaleRequired = false;
                 this.audioFileSelected = false;
                 this.mediaDurationSeconds = null;
                 this.mediaDurationState = 'invalid_type';
                 this.mediaTypeError = expectedType;
                 // Empty the picker and tell the tile (it hears a cancelled choice and returns to its base state).
                 event.target.value = '';
                 event.target.dispatchEvent(new Event('change', { bubbles: true }));
                 return;
             }
             this.mediaTypeError = '';
             this.mediaDurationState = 'probing';
             this.mediaDurationSeconds = null;
             const objectUrl = URL.createObjectURL(file);
             const media = document.createElement(this.activityType === 'audio' ? 'audio' : 'video');
             media.preload = 'metadata';
             media.onloadedmetadata = () => {
                 const duration = Number.isFinite(media.duration) && media.duration > 0 ? Math.ceil(media.duration) : null;
                 this.mediaDurationSeconds = duration;
                 this.mediaDurationState = duration ? 'ready' : 'unavailable';
                 this.estimatedDurationMinutes = duration ? Math.ceil(duration / 60) : null;
                 URL.revokeObjectURL(objectUrl);
             };
             media.onerror = () => {
                 this.mediaDurationSeconds = null;
                 this.mediaDurationState = 'unavailable';
                 URL.revokeObjectURL(objectUrl);
             };
             media.src = objectUrl;
         },
         speechToTextChanged() {
             this.processingLocaleRequired = this.activityType === 'audio' && this.audioFileSelected && this.speechToTextEnabled;
         },
         videoSpeechToTextChanged() {
             this.processingLocaleRequired = this.activityType === 'video' && this.mediaDurationState !== 'invalid_type' && this.videoSpeechToTextEnabled;
         },
         languageProfileEnabled() {
             // A Media picked from the library keeps what was already processed: no language or processing choice applies.
             if (this.libraryMedia) return false;

             return this.activityType === 'document'
                 || (this.activityType === 'audio' && this.speechToTextEnabled)
                 || (this.activityType === 'video' && this.videoSpeechToTextEnabled);
         },
         formattedMediaDuration() {
             if (! this.mediaDurationSeconds) return '—';
             const hours = Math.floor(this.mediaDurationSeconds / 3600);
             const minutes = Math.floor((this.mediaDurationSeconds % 3600) / 60);
             const seconds = this.mediaDurationSeconds % 60;
             return [
                 hours ? `${hours} giờ` : '',
                 minutes ? `${minutes} phút` : '',
                 seconds || (! hours && ! minutes) ? `${seconds} giây` : '',
             ].filter(Boolean).join(' ');
         },
         openPreview(detail) {
             if (! ['video', 'audio'].includes(detail.kind)) {
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
     x-on:ui-media-preview="openPreview($event.detail)"
     x-on:change="
        if ($event.target.name === 'activity_video_file') readMediaDuration($event, 'video');
        else if ($event.target.name === 'activity_audio_file') readMediaDuration($event, 'audio');
        else if ($event.target.name === 'activity_document_file') documentFileChanged($event);
     ">
    <span hidden x-on:library-media.window="libraryChosen[$event.detail.type] = $event.detail.chosen"></span>
    <div class="ui-modal__grid">
        <h4 class="ui-modal__section-title ui-field--full">{{ __('lf.LF_course_template_activity_section_information') }}</h4>

        <div class="ui-field ui-field--full">
            <label class="ui-field__label" for="activity-form-title">{!! $star !!}{{ __('lf.LF_course_template_activity_common_name') }}</label>
            <input id="activity-form-title" type="text" name="title" value="{{ $formActivity?->title }}" maxlength="255" required
                   placeholder="{{ __('lf.LF_course_template_activity_placeholder_name') }}">
        </div>

        <div class="ui-field ui-field--full">
            <label class="ui-field__label" for="activity-form-description">{{ __('lf.LF_course_template_activity_common_description') }}</label>
            <textarea id="activity-form-description" name="description" rows="3"
                      placeholder="{{ __('lf.LF_course_template_activity_placeholder_description') }}">{{ $formActivity?->description }}</textarea>
        </div>

        <div class="ui-field">
            <label class="ui-field__label" for="activity-form-activity_type">{!! $star !!}{{ __('lf.LF_course_template_activity_common_type') }}</label>
            <select id="activity-form-activity_type" name="activity_type" required {!! $selectAttributes !!}
                    x-on:change="activityType = $event.target.value; activityTypeChanged()">
                <option value="">{{ __('lf.LF_course_template_activity_common_select_type') }}</option>
                @foreach ($activityTypes as $activityTypeOption)
                    <option value="{{ $activityTypeOption }}" @selected($selectedActivityType === $activityTypeOption)>{{ __('lf.LF_course_template_activity_common_type_'.$activityTypeOption) }}</option>
                @endforeach
            </select>
        </div>

        <div class="ui-field">
            <label class="ui-field__label" for="activity-form-estimated_duration_minutes">{{ __('lf.LF_course_template_activity_common_estimated_duration_minutes') }}</label>
            <input id="activity-form-estimated_duration_minutes" type="number" min="1" step="1" name="estimated_duration_minutes"
                   x-model.number="estimatedDurationMinutes"
                   placeholder="{{ __('lf.LF_course_template_activity_placeholder_estimated_duration') }}">
            <span class="ui-field__hint" x-show="! ['video', 'audio'].includes(activityType)">{{ __('lf.LF_course_template_activity_common_estimated_duration_help') }}</span>
            <span class="ui-field__hint" aria-live="polite" x-show="['video', 'audio'].includes(activityType) && mediaDurationState === 'ready'" x-cloak>
                {{ __('lf.LF_course_template_activity_media_actual_duration') }}
                <strong x-text="formattedMediaDuration()"></strong>.
                {{ __('lf.LF_course_template_activity_media_estimated_duration_help') }}
            </span>
            <span class="ui-field__hint" x-show="['video', 'audio'].includes(activityType) && mediaDurationState === 'probing'" x-cloak>{{ __('lf.LF_course_template_activity_media_duration_probing') }}</span>
            <span class="ui-field__hint" x-show="['video', 'audio'].includes(activityType) && ['empty', 'unavailable'].includes(mediaDurationState)" x-cloak>{{ __('lf.LF_course_template_activity_media_duration_unknown') }}</span>
        </div>

        {{-- Video: an uploaded file and, when the platform allows it, speech-to-text. --}}
        <div class="ui-field ui-field--full" x-show="activityType === 'video'" x-cloak>
            <p class="ui-field__label" id="activity-video-label">{{ __('lf.LF_course_template_activity_common_type_video') }}</p>
            @php
                $videoMedia = $slotMedia('video');
            @endphp
            <x-ui-media-slot id="activity_video_file" name="activity_video_file"
                accept=".mp4,.webm,.mov,.avi,video/mp4,video/webm,video/quicktime,video/x-msvideo" kind="video"
                labelledby="activity-video-label"
                :presentation="$videoMedia ? $videoMedia['thumbnail'] : null"
                :display-name="$videoMedia['media']->display_name ?? null"
                :preview-url="$videoMedia['preview_url'] ?? null"
                :mime="$videoMedia['media']->mime_type ?? null"
                :remove-name="$videoMedia ? 'remove_activity_media' : null"
                :view-label="__('lf.LF_media_file_common_preview_action')"
                :remove-label="__('lf.LF_course_template_activity_media_remove_current')">
                <x-upload-hint ui-kit :formats="['MP4', 'WEBM', 'MOV', 'AVI']" :max-size="\App\Support\UploadLimit::effectiveVideoKilobytes()" />
            </x-ui-media-slot>
            <x-media-library-picker type="video" />
            @if (($currentMedia['type'] ?? null) === 'video' && ($currentMedia['state'] ?? '') === 'unavailable')
                <span class="ui-field__hint">{{ __('lf.LF_course_template_activity_media_unavailable') }}</span>
            @endif
            <span class="ui-field__error" role="alert" x-show="mediaDurationState === 'invalid_type' && mediaTypeError === 'video'" x-cloak>{{ __('lf.LF_course_template_activity_media_invalid_video') }}</span>
            <label class="ui-check" x-show="! libraryMedia">
                <input type="hidden" name="video_speech_to_text" value="0" :disabled="activityType !== 'video'">
                <input id="activity-form-video_speech_to_text" type="checkbox" name="video_speech_to_text" value="1"
                       x-model="videoSpeechToTextEnabled" x-on:change="videoSpeechToTextChanged()"
                       :disabled="activityType !== 'video' || !videoSttQualified"
                       @disabled(! $videoSttQualification['qualified'])
                       @checked($selectedVideoSpeechToText && $videoSttQualification['qualified'])>
                <span>{{ __('lf.LF_course_template_activity_video_stt_option') }}</span>
            </label>
            <span x-show="! libraryMedia" @class(['ui-field__hint', 'ui-field__error' => ! $videoSttQualification['qualified']])>{{ __($videoSttQualification['message_key']) }}</span>
            <details class="ui-details" x-show="! libraryMedia">
                <summary>{{ __('lf.LF_media_processing_details') }}</summary>
                <p>{{ __('lf.LF_course_template_activity_video_stt_help') }}</p>
                <p>{{ __('lf.LF_media_processing_video_short_warning') }}</p>
                <p>{{ __('lf.LF_media_processing_processing_requirements') }}</p>
                <p>{{ __('lf.LF_media_processing_video_limits', [
                    'minutes' => config('media.processing.speech_to_text.max_video_duration_seconds') / 60,
                    'gib' => config('media.processing.speech_to_text.max_video_source_bytes') / 1073741824,
                ]) }}</p>
            </details>
        </div>

        {{-- Video from a link. --}}
        <div class="ui-field ui-field--full" x-show="activityType === 'embedded_video'" x-cloak>
            <label class="ui-field__label" for="activity-form-external_video_url">{{ __('lf.LF_course_template_activity_common_external_url') }}</label>
            <input id="activity-form-external_video_url" type="url" name="external_video_url" value="{{ $formActivity?->external_video_url }}"
                   placeholder="{{ __('lf.LF_course_template_activity_placeholder_video_url') }}" :disabled="activityType !== 'embedded_video'">
        </div>

        {{-- Audio: a file and optional transcription. --}}
        <div class="ui-field ui-field--full" x-show="activityType === 'audio'" x-cloak>
            <p class="ui-field__label" id="activity-audio-label">{{ __('lf.LF_course_template_activity_common_type_audio') }}</p>
            @php
                $audioMedia = $slotMedia('audio');
            @endphp
            <x-ui-media-slot id="activity_audio_file" name="activity_audio_file"
                accept=".mp3,.wav,.m4a,.aac,.ogg" kind="audio"
                labelledby="activity-audio-label"
                :presentation="$audioMedia ? $audioMedia['thumbnail'] : null"
                :display-name="$audioMedia['media']->display_name ?? null"
                :preview-url="$audioMedia['preview_url'] ?? null"
                :mime="$audioMedia['media']->mime_type ?? null"
                :remove-name="$audioMedia ? 'remove_activity_media' : null"
                :view-label="__('lf.LF_media_file_common_preview_action')"
                :remove-label="__('lf.LF_course_template_activity_media_remove_current')">
                <x-upload-hint ui-kit :formats="['MP3', 'WAV', 'M4A', 'AAC', 'OGG']" />
            </x-ui-media-slot>
            <x-media-library-picker type="audio" />
            @if (($currentMedia['type'] ?? null) === 'audio' && ($currentMedia['state'] ?? '') === 'unavailable')
                <span class="ui-field__hint">{{ __('lf.LF_course_template_activity_media_unavailable') }}</span>
            @endif
            <span class="ui-field__error" role="alert" x-show="mediaDurationState === 'invalid_type' && mediaTypeError === 'audio'" x-cloak>{{ __('lf.LF_course_template_activity_media_invalid_audio') }}</span>
            <label class="ui-check" x-show="! libraryMedia">
                <input type="hidden" name="speech_to_text" value="0" :disabled="activityType !== 'audio'">
                <input id="activity-form-speech_to_text" type="checkbox" name="speech_to_text" value="1"
                       x-model="speechToTextEnabled" x-on:change="speechToTextChanged()"
                       :disabled="activityType !== 'audio'" @checked($storedSpeechToText)>
                <span>Tự động phiên âm nội dung audio</span>
            </label>
            <details class="ui-details" x-show="! libraryMedia">
                <summary>{{ __('lf.LF_media_processing_details') }}</summary>
                <p>{{ __('lf.LF_media_processing_audio_limits', ['minutes' => config('media.processing.speech_to_text.max_duration_seconds') / 60, 'gib' => config('media.processing.speech_to_text.max_bytes') / 1073741824]) }}</p>
                <p>{{ __('lf.LF_media_processing_processing_requirements') }}</p>
                <p>Có tick: hệ thống chạy phiên âm nền và lưu transcript theo từng khoảng thời gian để chuẩn bị cho tìm kiếm và tính năng AI ở phase sau. Nếu phiên âm thất bại, file audio vẫn nghe được bình thường.</p>
                <p>Không tick: hệ thống chỉ lưu và quét an toàn file; không tạo transcript, vì vậy audio chưa thể dùng làm nguồn nội dung cho AI. Có thể khởi tạo phiên âm sau mà không cần tải file lại.</p>
            </details>
        </div>

        {{-- Document: a file and, for a PDF, structure extraction. --}}
        <div class="ui-field ui-field--full" x-show="activityType === 'document'" x-cloak>
            <p class="ui-field__label" id="activity-document-label">{{ __('lf.LF_course_template_activity_common_type_document') }}</p>
            @php
                $documentMedia = $slotMedia('document');
            @endphp
            <x-ui-media-slot id="activity_document_file" name="activity_document_file"
                accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,application/pdf" :kind="$documentMedia['thumbnail']['kind'] ?? 'document'"
                labelledby="activity-document-label"
                :presentation="$documentMedia ? $documentMedia['thumbnail'] : null"
                :display-name="$documentMedia['media']->display_name ?? null"
                :preview-url="$documentMedia['preview_url'] ?? null"
                :mime="$documentMedia['media']->mime_type ?? null"
                :remove-name="$documentMedia ? 'remove_activity_media' : null"
                :view-label="__('lf.LF_media_file_common_preview_action')"
                :remove-label="__('lf.LF_course_template_activity_media_remove_current')">
                <x-upload-hint ui-kit :formats="['PDF', 'DOC', 'DOCX', 'XLS', 'XLSX', 'PPT', 'PPTX', 'TXT']" />
            </x-ui-media-slot>
            <x-media-library-picker type="document" />
            @if (($currentMedia['type'] ?? null) === 'document' && ($currentMedia['state'] ?? '') === 'unavailable')
                <span class="ui-field__hint">{{ __('lf.LF_course_template_activity_media_unavailable') }}</span>
            @endif
            <label class="ui-check" x-show="documentIsPdf && ! libraryMedia" x-cloak>
                <input id="activity-form-structured_extraction" type="checkbox" name="structured_extraction" value="1" :disabled="! documentIsPdf">
                <span>Trích xuất cấu trúc tài liệu (bảng, tiêu đề, thứ tự đọc)</span>
            </label>
            <details class="ui-details" x-show="! libraryMedia">
                <summary>{{ __('lf.LF_media_processing_details') }}</summary>
                <p>Tệp phải đồng thời không vượt quá giới hạn dung lượng và, nếu là PDF, không vượt quá 100 trang. Giới hạn 100 trang áp dụng cả khi không bật phân tích cấu trúc tài liệu.</p>
                <p>Không tick trích xuất cấu trúc: hệ thống vẫn trích xuất toàn bộ text như bình thường. Tick: chạy thêm một bước phân tích cấu trúc để nhận diện bảng, vai trò từng khối và thứ tự đọc — phục vụ AI đọc tài liệu. Bước này nặng hơn và chạy nền; nếu nó thất bại thì tài liệu vẫn dùng được bình thường. Tùy chọn này chỉ xuất hiện với tệp PDF; với Word, Excel hoặc PowerPoint hãy xuất ra PDF rồi tải bản PDF lên. Ảnh và biểu đồ chỉ được đánh dấu vị trí, chưa được diễn giải nội dung.</p>
                <p>{{ __('lf.LF_media_processing_document_limits', ['pages' => config('media.processing.structured_extraction.max_pages'), 'ocr_pages' => config('media.processing.local_document.max_pages'), 'regions' => config('media.processing.structured_extraction.max_regions_per_page'), 'total' => config('media.processing.structured_extraction.max_regions_per_document')]) }}</p>
                <p>{{ __('lf.LF_media_processing_processing_requirements') }}</p>
            </details>
        </div>

        {{-- Content languages (documents, and audio/video with transcription). --}}
        <fieldset class="ui-field ui-field--full ui-fieldset-reset" x-show="languageProfileEnabled()" x-cloak data-ui-error-for="processing_locales">
            <legend class="ui-field__label">
                Ngôn ngữ nội dung (chọn 1–3)
                <span class="ui-badge" x-show="processingLocaleRequired" x-cloak>Bắt buộc cho tệp mới</span>
            </legend>
            <div class="ui-check-list">
                @foreach ($localeOptions as $localeCode => $localeLabel)
                    <label class="ui-check">
                        <input type="checkbox" name="processing_locales[]" value="{{ $localeCode }}"
                               id="activity-form-processing_locales-{{ $localeCode }}"
                               :disabled="!languageProfileEnabled()"
                               @checked(in_array($localeCode, $storedProcessingLocales, true))>
                        <span>{{ $localeLabel }}</span>
                    </label>
                @endforeach
            </div>
            <span class="ui-field__hint" x-show="activityType === 'document'">Dùng cho OCR và phân tích cấu trúc; thứ tự chọn không ảnh hưởng revision.</span>
            <span class="ui-field__hint" x-show="['audio', 'video'].includes(activityType)">Chỉ dùng khi bật phiên âm. Một timeline giữ nguyên phần chuyển đổi giữa các ngôn ngữ; hệ thống không dịch nội dung.</span>
        </fieldset>

        <div class="ui-field ui-field--full" x-show="activityType === 'quiz'" x-cloak>
            <label class="ui-field__label" for="activity-form-assessment_quiz_id">Assessment Quiz ID</label>
            <input id="activity-form-assessment_quiz_id" type="number" min="1" name="assessment_quiz_id" value="{{ $formActivity?->assessment_quiz_id }}"
                   placeholder="{{ __('lf.LF_course_template_activity_placeholder_assessment') }}" :disabled="activityType !== 'quiz'">
        </div>

        <div class="ui-field ui-field--full" x-show="activityType === 'live_class'" x-cloak>
            <label class="ui-field__label" for="activity-form-live_class_url">Live class URL</label>
            <input id="activity-form-live_class_url" type="url" name="live_class_url" value="{{ $formActivity?->live_class_url }}"
                   placeholder="{{ __('lf.LF_course_template_activity_placeholder_live_class_url') }}" :disabled="activityType !== 'live_class'">
        </div>

        <h4 class="ui-modal__section-title ui-field--full">{{ __('lf.LF_course_template_activity_section_availability') }}</h4>

        <fieldset class="ui-field ui-field--full ui-fieldset-reset" data-ui-error-for="learning_phases">
            <input type="hidden" name="learning_phases_present" value="1">
            <legend class="ui-field__label">{!! $star !!}{{ __('lf.LF_course_template_activity_learning_availability') }}</legend>
            {{-- Anytime and the session phases are exclusive: pick the mode first, then the phases of a session. --}}
            <div class="ui-radio-group__options">
                @foreach (['anytime', 'session'] as $mode)
                    <label class="ui-check">
                        <input type="radio" name="learning_mode" value="{{ $mode }}" id="activity-form-learning_mode-{{ $mode }}" x-model="learningMode">
                        <span>{{ __('lf.LF_course_template_activity_learning_mode_'.$mode) }}</span>
                    </label>
                @endforeach
            </div>
            <input type="hidden" name="learning_phases[]" value="anytime" x-bind:disabled="learningMode !== 'anytime'">
            <div class="ui-check-list ui-check-list--inline" x-show="learningMode === 'session'" x-cloak>
                @foreach (['before_session', 'during_session', 'after_session'] as $phase)
                    <label class="ui-check">
                        <input type="checkbox" name="learning_phases[]" value="{{ $phase }}" id="activity-form-learning_phases-{{ $phase }}"
                               x-model="sessionPhases" x-bind:disabled="learningMode !== 'session'">
                        <span>{{ __('lf.LF_course_template_activity_learning_availability_'.$phase) }}</span>
                    </label>
                @endforeach
            </div>
            <span class="ui-field__hint">{{ __('lf.LF_course_template_activity_learning_availability_help') }}</span>
        </fieldset>

        <h4 class="ui-modal__section-title ui-field--full">{{ __('lf.LF_course_template_activity_section_rules') }}</h4>

        <div class="ui-field">
            <label class="ui-field__label" for="activity-form-completion_rule">{!! $star !!}{{ __('lf.LF_course_template_activity_common_completion_rule') }}</label>
            <select id="activity-form-completion_rule" name="completion_rule" required x-ref="completionRule" {!! $selectAttributes !!}
                    x-on:change="completionRule = $event.target.value">
                <option value="">{{ $selectedActivityType ? __('lf.LF_course_template_activity_placeholder_completion_rule') : __('lf.LF_course_template_activity_common_select_type_first') }}</option>
                @foreach ($allCompletionRules as $rule)
                    <option value="{{ $rule }}" @selected($selectedCompletionRule === $rule)
                            @disabled($selectedActivityType && ! in_array($rule, $completionRules[$selectedActivityType] ?? [], true))>{{ $completionRuleLabelsByType[$selectedActivityType][$rule] ?? $completionRuleLabels[$rule] }}</option>
                @endforeach
            </select>
        </div>

        <div class="ui-field" x-show="['watch_percent', 'pass'].includes(completionRule)" x-cloak>
            <label class="ui-field__label" for="activity-form-completion_threshold">{{ __('lf.LF_course_template_activity_common_completion_threshold') }}</label>
            <input id="activity-form-completion_threshold" type="number" min="1" max="100" name="completion_threshold"
                   value="{{ $formActivity?->completion_threshold }}"
                   placeholder="{{ __('lf.LF_course_template_activity_placeholder_completion_threshold_percent') }}">
        </div>

        <div class="ui-field">
            <label class="ui-field__label" for="activity-form-is_preview">{!! $star !!}{{ __('lf.LF_course_template_activity_common_preview') }}</label>
            <select id="activity-form-is_preview" name="is_preview" required {!! $selectAttributes !!}>
                <option value="0" @selected($selectedPreview === '0')>{{ __('lf.LF_course_template_activity_common_no') }}</option>
                <option value="1" @selected($selectedPreview === '1')>{{ __('lf.LF_course_template_activity_common_yes') }}</option>
            </select>
        </div>

        <div class="ui-field">
            <label class="ui-field__label" for="activity-form-is_required">{!! $star !!}{{ __('lf.LF_course_template_activity_common_required') }}</label>
            <select id="activity-form-is_required" name="is_required" required {!! $selectAttributes !!}>
                <option value="1" @selected($selectedRequired === '1')>{{ __('lf.LF_course_template_activity_common_yes') }}</option>
                <option value="0" @selected($selectedRequired === '0')>{{ __('lf.LF_course_template_activity_common_no') }}</option>
            </select>
        </div>

        <div class="ui-field">
            <label class="ui-field__label" for="activity-form-unlock_rule">{!! $star !!}{{ __('lf.LF_course_template_activity_common_unlock_rule') }}</label>
            <select id="activity-form-unlock_rule" name="unlock_rule" required {!! $selectAttributes !!}
                    x-on:change="unlockRule = $event.target.value">
                @foreach (['none', 'previous_activity_completed'] as $rule)
                    <option value="{{ $rule }}" @selected($selectedUnlockRule === $rule)>{{ __('lf.LF_course_template_activity_common_unlock_'.$rule) }}</option>
                @endforeach
            </select>
        </div>

        <div class="ui-field" x-show="unlockRule === 'previous_activity_completed'" x-cloak>
            <label class="ui-field__label" for="activity-form-unlock_after_activity_id">{{ __('lf.LF_course_template_activity_common_unlock_after_activity') }}</label>
            <select id="activity-form-unlock_after_activity_id" name="unlock_after_activity_id" {!! $selectAttributes !!}>
                <option value="">{{ __('lf.LF_course_template_activity_placeholder_prerequisite') }}</option>
                @foreach ($prerequisiteActivities as $item)
                    <option value="{{ $item->id }}" @selected((string) $selectedPrerequisiteId === (string) $item->id)>{{ $item->title }}</option>
                @endforeach
            </select>
        </div>

        <div class="ui-field">
            <label class="ui-field__label" for="activity-form-sort_order">{{ __('lf.LF_course_template_activity_common_sort_order') }}</label>
            <input id="activity-form-sort_order" type="number" min="0" name="sort_order"
                   value="{{ $formActivity?->sort_order ?? $suggestedSortOrder ?? null }}"
                   placeholder="{{ __('lf.LF_course_template_activity_placeholder_sort_order') }}">
        </div>
    </div>

    <x-media-preview-modal ui-kit id="activity-form-preview-title" show="previewOpen" close="closePreview()" title="preview.name" compact="false">
        <template x-if="previewOpen && preview.mediaType === 'video'">
            <video controls preload="metadata" autoplay x-bind:src="preview.url"></video>
        </template>
        <template x-if="previewOpen && preview.mediaType === 'audio'">
            <audio controls preload="metadata" autoplay x-bind:src="preview.url"></audio>
        </template>
    </x-media-preview-modal>
</div>
