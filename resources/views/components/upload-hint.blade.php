@props([
    'formats' => [],
    'maxSize' => null,
    'note' => null,
])

@php
    $formatsText = \App\Support\UploadLimit::formatList((array) $formats);
    $sizeText = \App\Support\UploadLimit::humanReadable(
        \App\Support\UploadLimit::effectiveKilobytes(
            $maxSize === null ? null : (int) $maxSize
        )
    );
    $hintText = __('lf.upload_hint_combined', [
        'formats' => $formatsText,
        'size' => $sizeText,
    ]);
@endphp

<div {{ $attributes->merge(['class' => 'admin-upload-hint']) }}>
    {{ $hintText }}@if ($note) <span>{{ $note }}</span>@endif
</div>
