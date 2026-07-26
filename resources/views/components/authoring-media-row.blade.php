@props([
    'presentation',
    'alt' => '',
    'removeName' => null,
    'removeLabel' => null,
    'currentLabel' => null,
    'displayName' => null,
])

<div class="authoring-media-current" data-authoring-media-current-row>
    @if ($currentLabel)
        <p class="authoring-media-current-label">{{ $currentLabel }}</p>
    @endif
    <div {{ $attributes->class(['authoring-media-current-row']) }}>
        <x-media-thumbnail :presentation="$presentation" :alt="$alt" />
        <div class="authoring-media-current-content">
            @if ($displayName)
                <span class="authoring-media-current-name">{{ $displayName }}</span>
            @endif
            <div class="authoring-media-actions">
                {{ $slot }}
                @if ($removeName)
                    <label class="authoring-media-remove"
                           for="{{ $removeName }}"
                           x-on:click="$nextTick(() => $el.closest('[data-authoring-media-current-row]').hidden = true)">
                        <input id="{{ $removeName }}" type="checkbox" name="{{ $removeName }}" value="1">
                        <x-backend-icon name="trash" class="authoring-media-action-icon" />
                        <span class="sr-only">{{ $removeLabel }}</span>
                    </label>
                @endif
            </div>
        </div>
    </div>
</div>
