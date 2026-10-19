@props([
    'id',
    'name',
    'accept' => null,
    'kind' => 'image',
    'labelledby' => null,
    'presentation' => null,
    'displayName' => null,
    'previewUrl' => null,
    'mime' => null,
    'removeName' => null,
    'uploadLabel' => null,
    'viewLabel' => null,
    'removeLabel' => null,
])

{{--
    UI Kit media slot: one tile per file. Empty = upload tile; filled (saved or just chosen) = content
    inside the tile with View/Remove on hover or focus. Behaviour: initUiKitMediaSlot() in
    resources/js/admin-ui-kit-controls.js. Remove only marks the saved usage for removal; the server detaches
    it on a successful save and a new file chosen in the same submit replaces it.
--}}
@php
    $isSaved = is_array($presentation) && ($presentation['state'] ?? 'empty') !== 'empty';
    $imageStates = ['image', 'generated_video_poster', 'provider_video_thumbnail', 'generated_pdf_preview'];
    $savedImageUrl = $isSaved && ($presentation['url'] ?? null) && in_array($presentation['state'], $imageStates, true)
        ? $presentation['url']
        : null;
    $icon = match ($kind) {
        'image' => 'image', 'video' => 'video', 'pdf' => 'file-pdf', 'word' => 'file-text',
        'spreadsheet' => 'file-spreadsheet', 'presentation' => 'file-presentation', 'audio' => 'audio',
        default => 'document',
    };
    $pendingPreviewable = in_array($kind, ['image', 'video', 'audio', 'pdf'], true);
    $uploadText = $uploadLabel ?? __('lf.LF_media_slot_upload');
    $viewText = $viewLabel ?? __('lf.LF_media_slot_view');
    $removeText = $removeLabel ?? __('lf.LF_media_slot_remove');
@endphp

<div class="ui-media-slot"
     role="group"
     @if ($labelledby) aria-labelledby="{{ $labelledby }}" @endif
     data-ui-media-slot
     data-kind="{{ $kind }}"
     data-state="{{ $isSaved ? 'filled' : 'empty' }}"
     data-saved="{{ $isSaved ? 'true' : 'false' }}"
     data-pending-previewable="{{ $pendingPreviewable ? 'true' : 'false' }}"
     data-saved-name="{{ $displayName }}"
     data-saved-preview-url="{{ $previewUrl }}"
     data-saved-mime="{{ $mime }}"
     data-status-selected="{{ __('lf.LF_media_slot_selected', ['name' => ':name']) }}"
     data-status-removed="{{ __('lf.LF_media_slot_removed') }}">
    <div class="ui-media-tile" data-ui-media-tile>
        <div class="ui-media-tile__filled" data-ui-media-filled @unless ($isSaved) hidden @endunless>
            <img class="ui-media-tile__image" data-ui-media-image alt="{{ $displayName }}"
                 @if ($savedImageUrl) src="{{ $savedImageUrl }}" @else hidden @endif>
            <span class="ui-media-tile__fallback" data-ui-media-fallback aria-hidden="true"
                  @if ($savedImageUrl) hidden @endif>
                <x-backend-icon :name="$icon" class="ui-icon" />
            </span>
            <div class="ui-media-tile__actions">
                <button type="button" class="ui-media-tile__action cursor-interaction" data-ui-media-view
                        aria-label="{{ $viewText }}" title="{{ $viewText }}">
                    <x-backend-icon name="eye" class="ui-icon" />
                </button>
                <button type="button" class="ui-media-tile__action cursor-interaction" data-ui-media-remove
                        aria-label="{{ $removeText }}" title="{{ $removeText }}">
                    <x-backend-icon name="trash" class="ui-icon" />
                </button>
            </div>
        </div>
        <label class="ui-media-tile__empty cursor-interaction" for="{{ $id }}" data-ui-media-empty @if ($isSaved) hidden @endif>
            <x-backend-icon name="plus" class="ui-icon" />
            <span>{{ $uploadText }}</span>
        </label>
        <input class="ui-media-tile__input" type="file" id="{{ $id }}" name="{{ $name }}"
               data-ui-media-input @if ($accept) accept="{{ $accept }}" @endif
               aria-invalid="{{ $errors->has($name) ? 'true' : 'false' }}"
               @php($describedBy = trim(($slot->isNotEmpty() ? $id.'-hint ' : '').($errors->has($name) ? $id.'-error' : '')))
               @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
               @if ($isSaved) tabindex="-1" @endif>
    </div>
    @if ($removeName)
        <input type="hidden" name="{{ $removeName }}" value="0" data-ui-media-remove-flag>
    @endif
    <span class="ui-sr-only" role="status" aria-live="polite" data-ui-media-status></span>
    @error($name)<span class="ui-field__error" id="{{ $id }}-error" role="alert">{{ $message }}</span>@enderror
    @if ($slot->isNotEmpty())
        <div class="ui-media-slot__hint" id="{{ $id }}-hint">{{ $slot }}</div>
    @endif
</div>
