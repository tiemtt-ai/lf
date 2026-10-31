@props(['type', 'accept', 'formats', 'maxSize' => null, 'current' => null, 'unavailable' => false])

{{--
    The file of a media activity (video, audio, document): a drop zone with "Upload" and "Choose from library" while it is
    empty, then one file card for whichever source holds the file: a new upload (sent with the form), a Media picked in the
    library view of the dialog (sent as `existing_media_id`) or the Media already saved on the activity (removable through
    `remove_activity_media`). Sits inside the activity form, whose Alpine scope provides `activityType`, `libraryChosen`
    and `fileChanged()`; the library view answers with the `library-media` window event.
--}}
@php
    $inputName = 'activity_'.$type.'_file';
    $inputId = 'activity_'.$type.'_file';
    $icon = ['video' => 'video', 'audio' => 'audio', 'document' => 'file-text'][$type] ?? 'file-text';
    $tone = ['video' => 'violet', 'audio' => 'teal', 'document' => 'blue'][$type] ?? 'blue';
    // A saved document says what kind of file it is (pdf, word, spreadsheet...), as the media tiles do.
    $fileKind = $type === 'document' ? ($current['thumbnail']['kind'] ?? 'document') : $type;
    $icon = match ($fileKind) {
        'pdf' => 'file-pdf', 'spreadsheet' => 'file-spreadsheet', 'presentation' => 'file-presentation', default => $icon,
    };
    $saved = $current ? [
        'name' => (string) ($current['media']->display_name ?? ''),
        'size' => (int) ($current['media']->file_size_bytes ?? 0),
        'url' => (string) ($current['preview_url'] ?? ''),
        'mime' => (string) ($current['media']->mime_type ?? ''),
    ] : null;
@endphp
<div class="ui-file-slot" data-ui-file-slot data-kind="{{ $fileKind }}" data-saved="{{ $saved ? 'true' : 'false' }}"
     @if ($saved) data-saved-preview-url="{{ $saved['url'] }}" @endif
     x-data="{
         slotType: @js($type),
         saved: @js($saved),
         removed: false,
         upload: null,
         library: null,
         dragging: false,
         get state() {
             if (this.upload) return 'upload';
             if (this.library) return 'library';
             if (this.saved && ! this.removed) return 'saved';
             return 'empty';
         },
         sizeText(bytes) {
             if (! bytes) return '';
             if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + ' GB';
             if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
             return Math.max(1, Math.round(bytes / 1024)) + ' KB';
         },
         get card() {
             if (this.state === 'upload') return { name: this.upload.name, meta: [this.sizeText(this.upload.size)] };
             if (this.state === 'library') return { name: this.library.name, meta: [this.library.file, this.library.size, this.library.duration].filter(Boolean) };
             if (this.state === 'saved') return { name: this.saved.name, meta: [this.sizeText(this.saved.size)].filter(Boolean) };
             return null;
         },
         input() { return this.$refs.file; },
         pick() { this.input().click(); },
         openLibrary() {
             this.$dispatch('open-media-library', { type: this.slotType, chosenId: this.library ? this.library.id : null });
         },
         inputChanged() {
             const file = this.input().files?.[0] ?? null;
             this.upload = file ? { name: file.name, size: file.size } : null;
             if (file) { this.library = null; libraryChosen[this.slotType] = false; }
         },
         dropped(event) {
             this.dragging = false;
             const file = event.dataTransfer?.files?.[0];
             if (! file) return;
             const transfer = new DataTransfer();
             transfer.items.add(file);
             this.input().files = transfer.files;
             this.input().dispatchEvent(new Event('change', { bubbles: true }));
         },
         chooseLibrary(item) {
             // Not a change event: the form would treat it as a cleared upload and reset what it inferred from it.
             this.input().value = '';
             this.upload = null;
             this.library = item;
             libraryChosen[this.slotType] = true;
             fileChanged(this.slotType, { source: 'library', name: item.name, seconds: item.duration_seconds || null });
         },
         clear() {
             if (this.upload) { this.input().value = ''; this.input().dispatchEvent(new Event('change', { bubbles: true })); return; }
             if (this.library) { this.library = null; libraryChosen[this.slotType] = false; fileChanged(this.slotType, null); return; }
             if (this.saved) { this.removed = true; fileChanged(this.slotType, null); }
         },
         preview() {
             if (this.state === 'saved') {
                 this.$dispatch('ui-media-preview', { kind: this.slotType, name: this.saved.name, url: this.saved.url, mimeType: this.saved.mime });
             } else if (this.state === 'upload') {
                 const file = this.input().files?.[0];
                 if (file) this.$dispatch('ui-media-preview', { kind: this.slotType, name: file.name, url: URL.createObjectURL(file), mimeType: file.type });
             }
         },
     }"
     x-on:library-media.window="if ($event.detail.type === slotType && $event.detail.chosen) chooseLibrary($event.detail.chosen)"
     x-on:activity-file-clear.window="if ($event.detail.type === slotType) { upload = null; library = null; libraryChosen[slotType] = false; if (saved) removed = true; if (input().value) input().value = ''; }">
    <input type="file" class="ui-sr-only" id="{{ $inputId }}" name="{{ $inputName }}" accept="{{ $accept }}" tabindex="-1"
           x-ref="file" x-on:change="inputChanged()" aria-labelledby="activity-{{ $type }}-label">
    <input type="hidden" name="existing_media_id" x-bind:value="library ? library.id : ''" x-bind:disabled="! library || activityType !== slotType">
    @if ($saved)
        <input type="hidden" name="remove_activity_media" x-bind:value="removed && ! upload && ! library ? 1 : 0" x-bind:disabled="activityType !== slotType">
    @endif

    <div class="ui-dropzone" x-show="state === 'empty'" x-bind:class="{ 'is-dragging': dragging }"
         x-on:dragover.prevent="dragging = true" x-on:dragleave="dragging = false" x-on:drop.prevent="dropped($event)">
        <span class="ui-dropzone__icon" aria-hidden="true"><x-backend-icon name="upload" class="ui-icon" /></span>
        <span class="ui-dropzone__text">
            <b>{{ __('lf.LF_activity_file_drop') }}</b>
            <x-upload-hint ui-kit :formats="$formats" :max-size="$maxSize" />
        </span>
        <span class="ui-dropzone__actions">
            <button type="button" class="ui-button cursor-interaction" x-on:click="pick()">
                <x-backend-icon name="upload" class="ui-icon" />{{ __('lf.LF_activity_file_upload') }}
            </button>
            <button type="button" class="ui-button cursor-interaction" x-on:click="openLibrary()" aria-haspopup="dialog">
                <x-backend-icon name="folder" class="ui-icon" />{{ __('lf.LF_media_pick_button') }}
            </button>
        </span>
    </div>

    <div class="ui-file-card" x-show="state !== 'empty'" x-cloak>
        <div class="ui-file-card__main">
            <span class="ui-file-card__icon ui-type-card__icon--{{ $tone }}" aria-hidden="true"><x-backend-icon :name="$icon" class="ui-icon" /></span>
            <span class="ui-file-card__text">
                <b x-text="card ? card.name : ''"></b>
                <small x-text="card ? card.meta.join(' · ') : ''"></small>
            </span>
            <button type="button" class="ux-row-edit cursor-interaction" x-show="['saved', 'upload'].includes(state)" x-on:click="preview()"
                    aria-label="{{ __('lf.LF_media_file_common_preview_action') }}" data-tooltip="{{ __('lf.LF_media_file_common_preview_action') }}">
                <x-backend-icon name="eye" class="ui-icon" />
            </button>
            <button type="button" class="ux-row-edit cursor-interaction" x-on:click="openLibrary()"
                    aria-label="{{ __('lf.LF_activity_file_swap') }}" data-tooltip="{{ __('lf.LF_activity_file_swap') }}">
                <x-backend-icon name="folder" class="ui-icon" />
            </button>
            <button type="button" class="ux-row-edit cursor-interaction" x-on:click="clear()"
                    aria-label="{{ __('lf.LF_media_pick_remove') }}" data-tooltip="{{ __('lf.LF_media_pick_remove') }}">
                <x-backend-icon name="trash" class="ui-icon" />
            </button>
        </div>
        <div class="ui-file-card__foot">
            <span x-show="state === 'upload'">{{ __('lf.LF_activity_file_new_upload') }}</span>
            <span x-show="state === 'library'" x-text="library ? (@js(__('lf.LF_activity_file_from_library')) + (library.used ? ' · ' + library.usage : '')) : ''"></span>
            <span x-show="state === 'saved'">{{ __('lf.LF_activity_file_saved') }}</span>
        </div>
    </div>

    <span class="ui-field__hint" x-show="state === 'saved' || state === 'empty'" x-cloak>
        @if ($unavailable){{ __('lf.LF_course_template_activity_media_unavailable') }}@endif
    </span>
    @error($inputName)<span class="ui-field__error" role="alert">{{ $message }}</span>@enderror
</div>
