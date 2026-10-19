@props([
    'id',
    'name',
    'label',
    'uiKit' => false,
])

@if ($uiKit)
    <div x-data="{ selectedFileName: '' }">
        <label class="ui-upload cursor-interaction" for="{{ $id }}">
            <svg class="ui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2" /><circle cx="8.5" cy="8.5" r="1.5" /><path d="m21 15-5-5L5 21" /></svg>
            <span>{{ $label }}</span>
            <input id="{{ $id }}" type="file" name="{{ $name }}" {{ $attributes }}
                   @change="selectedFileName = $event.target.files?.[0]?.name || ''"
                   aria-invalid="{{ $errors->has($name) ? 'true' : 'false' }}"
                   @error($name) aria-describedby="{{ $id }}-error" @enderror>
        </label>
        <span class="ui-caption" x-show="selectedFileName" x-text="selectedFileName"></span>
        @error($name)<span class="ui-field__error" id="{{ $id }}-error" role="alert">{{ $message }}</span>@enderror
    </div>
@else
<div class="authoring-media-upload-tile"
     x-data="{ selectedFileName: '' }"
     x-on:change="selectedFileName = $event.target.files?.[0]?.name || ''">
    <input id="{{ $id }}"
           type="file"
           name="{{ $name }}"
           {{ $attributes->class(['authoring-media-upload', 'admin-file-upload']) }}>
    <label for="{{ $id }}" class="authoring-media-upload-trigger">
        <x-backend-icon name="plus" class="authoring-media-upload-icon" />
        <span>{{ $label }}</span>
        <small x-show="selectedFileName" x-text="selectedFileName"></small>
    </label>
</div>

@endif
