@props([
    'presentation',
    'alt' => '',
    'removeName' => null,
    'removeLabel' => null,
])

<div {{ $attributes->class(['authoring-media-current-row']) }} data-authoring-media-current-row>
    <x-media-thumbnail :presentation="$presentation" :alt="$alt" />
    <div class="authoring-media-actions">
        {{ $slot }}
        @if ($removeName)
            <label class="authoring-media-remove" for="{{ $removeName }}">
                <input id="{{ $removeName }}" type="checkbox" name="{{ $removeName }}" value="1">
                {{ $removeLabel }}
            </label>
        @endif
    </div>
</div>
