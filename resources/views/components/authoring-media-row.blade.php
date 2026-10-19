@props([
    'presentation',
    'uiKit' => false,
    'alt' => '',
    'removeName' => null,
    'removeLabel' => null,
    'currentLabel' => null,
    'displayName' => null,
])

<div class="{{ $uiKit ? 'ui-form-media' : 'authoring-media-current' }}" data-authoring-media-current-row>
    @if ($currentLabel)
        <p class="{{ $uiKit ? 'ui-caption' : 'authoring-media-current-label' }}">{{ $currentLabel }}</p>
    @endif
    <div {{ $attributes->class([$uiKit ? 'ui-media-upload__file' : 'authoring-media-current-row']) }}>
        <x-media-thumbnail :presentation="$presentation" :alt="$alt" />
        <div class="{{ $uiKit ? 'ui-field' : 'authoring-media-current-content' }}">
            @if ($displayName)
                <span class="{{ $uiKit ? 'ui-media-upload__filename' : 'authoring-media-current-name' }}">{{ $displayName }}</span>
            @endif
            <div class="{{ $uiKit ? 'ui-action-row' : 'authoring-media-actions' }}">
                {{ $slot }}
                @if ($removeName)
                    <label class="{{ $uiKit ? 'ui-check' : 'authoring-media-remove' }}"
                           for="{{ $removeName }}"
                           x-on:click="$nextTick(() => $el.closest('[data-authoring-media-current-row]').hidden = true)">
                        <input id="{{ $removeName }}" type="checkbox" name="{{ $removeName }}" value="1">
                        @unless ($uiKit)<x-backend-icon name="trash" class="authoring-media-action-icon" />@endunless
                        <span class="{{ $uiKit ? 'ui-caption' : 'sr-only' }}">{{ $removeLabel }}</span>
                    </label>
                @endif
            </div>
        </div>
    </div>
</div>
