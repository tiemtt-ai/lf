@props([
    'id',
    'name',
    'labelledby' => null,
    'value' => null,
    'thumbnail' => null,
    'previewUrl' => null,
    'displayName' => null,
])

{{--
    UI Kit embedded-video tile, the same look as a media slot: empty = "+ Nhúng"; a click opens a small link editor and
    OK shows the video in the tile (thumbnail, View/Remove on hover). The committed link is the hidden input `name`.
    Behaviour: initUiKitEmbedSlot() in resources/js/admin-ui-kit-controls.js.
--}}
@php
    $isFilled = filled($value);
@endphp

<div class="ui-media-slot ui-embed-slot"
     role="group"
     @if ($labelledby) aria-labelledby="{{ $labelledby }}" @endif
     data-ui-embed-slot
     data-ui-error-for="{{ $name }}"
     data-state="{{ $isFilled ? 'filled' : 'empty' }}"
     data-saved-image="{{ $thumbnail }}"
     data-saved-preview-url="{{ $previewUrl }}"
     data-saved-name="{{ $displayName }}"
     data-invalid-message="{{ __('lf.LF_course_template_embed_invalid') }}"
     data-status-removed="{{ __('lf.LF_media_slot_removed') }}">
    <div class="ui-media-tile">
        <div class="ui-media-tile__filled" data-ui-embed-filled @unless ($isFilled) hidden @endunless>
            <img class="ui-media-tile__image" data-ui-embed-image alt="{{ $displayName }}"
                 @if ($isFilled && $thumbnail) src="{{ $thumbnail }}" @else hidden @endif>
            <span class="ui-media-tile__fallback" data-ui-embed-fallback aria-hidden="true" @if ($isFilled && $thumbnail) hidden @endif>
                <x-backend-icon name="video" class="ui-icon" />
            </span>
            <div class="ui-media-tile__actions">
                <button type="button" class="ui-media-tile__action cursor-interaction" data-ui-embed-view
                        aria-label="{{ __('lf.LF_media_file_common_preview_action') }}" title="{{ __('lf.LF_media_file_common_preview_action') }}">
                    <x-backend-icon name="eye" class="ui-icon" />
                </button>
                <button type="button" class="ui-media-tile__action cursor-interaction" data-ui-embed-remove
                        aria-label="{{ __('lf.LF_course_template_remove_embed') }}" title="{{ __('lf.LF_course_template_remove_embed') }}">
                    <x-backend-icon name="trash" class="ui-icon" />
                </button>
            </div>
        </div>
        <button type="button" class="ui-media-tile__empty cursor-interaction" id="{{ $id }}" data-ui-embed-add
                aria-haspopup="true" aria-expanded="false" @if ($isFilled) hidden @endif>
            <x-backend-icon name="plus" class="ui-icon" />
            <span>{{ __('lf.LF_course_template_embed_tile_add') }}</span>
        </button>
    </div>
    <input type="hidden" name="{{ $name }}" value="{{ $value }}" data-ui-embed-value>

    <div class="ui-embed-editor" data-ui-embed-editor role="group" aria-label="{{ __('lf.LF_course_template_embed_url_label') }}" hidden>
        <input type="url" data-ui-embed-input autocomplete="off" inputmode="url"
               aria-label="{{ __('lf.LF_course_template_embed_url_label') }}"
               placeholder="{{ __('lf.LF_course_template_placeholder_embed_url') }}">
        <span class="ui-field__error" data-ui-embed-error role="alert" hidden></span>
        <div class="ui-embed-editor__actions">
            <button type="button" class="ui-button cursor-interaction" data-ui-embed-cancel>{{ __('lf.LF_common_button_cancel') }}</button>
            <button type="button" class="ui-button ui-button--primary cursor-interaction" data-ui-embed-ok>{{ __('lf.LF_common_button_confirm') }}</button>
        </div>
    </div>

    <span class="ui-sr-only" role="status" aria-live="polite" data-ui-embed-status></span>
    @if ($slot->isNotEmpty())
        <div class="ui-media-slot__hint" id="{{ $id }}-hint">{{ $slot }}</div>
    @endif
</div>
