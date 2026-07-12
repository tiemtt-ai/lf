@props(['presentation', 'alt' => '', 'variant' => 'compact'])
@php
    $state = $presentation['state'];
    $kind = $presentation['kind'];
    $url = $presentation['url'];
    $imageStates = ['image', 'generated_video_poster', 'provider_video_thumbnail', 'generated_pdf_preview'];
    $icon = match ($kind) {
        'image' => 'image', 'video' => 'video', 'pdf' => 'file-pdf',
        'word' => 'file-text', 'spreadsheet' => 'file-spreadsheet',
        'presentation' => 'file-presentation', default => 'document',
    };
@endphp
<span {{ $attributes->class(['media-thumbnail', 'media-thumbnail-'.$variant, 'media-thumbnail-'.$kind]) }} data-media-thumbnail-kind="{{ $kind }}" data-media-thumbnail-state="{{ $state }}">
    @if ($url && in_array($state, $imageStates, true))
        <img class="media-thumbnail-image" src="{{ $url }}" alt="{{ $alt }}" loading="lazy" decoding="async" x-on:error="$el.hidden = true; $el.nextElementSibling.hidden = false">
    @endif
    <span class="media-thumbnail-fallback" @if ($url) hidden @endif aria-hidden="true"><x-backend-icon :name="$icon" class="media-thumbnail-icon" /></span>
</span>
