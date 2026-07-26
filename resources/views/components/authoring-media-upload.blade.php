@props([
    'id',
    'name',
    'label',
])

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
