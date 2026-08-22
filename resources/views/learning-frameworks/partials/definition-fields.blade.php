@php
    $definitionFieldId = $definition ? 'definition-'.$definition->id : 'definition-new';
@endphp

<div class="admin-form-field-grid">
    <div class="lf-form-group admin-form-field">
        <x-form-label :for="$definitionFieldId.'-code'" :value="__('lf.LF_learning_code')" required />
        <input id="{{ $definitionFieldId }}-code" name="code" class="lf-form-control" required maxlength="120"
               autocomplete="off" value="{{ old('code', $definition->code ?? '') }}" @disabled($identityLocked)>
        @error('code')<p class="lf-form-error">{{ $message }}</p>@enderror
    </div>

    <div class="lf-form-group admin-form-field">
        <x-form-label :for="$definitionFieldId.'-type'" :value="__('lf.LF_learning_type')" required />
        <select id="{{ $definitionFieldId }}-type" name="node_type" class="lf-form-control" @disabled($identityLocked)>
            @foreach (['objective', 'concept', 'competency'] as $type)
                <option value="{{ $type }}" @selected(old('node_type', $definition->node_type ?? '') === $type)>
                    {{ $type }}
                </option>
            @endforeach
        </select>
        @error('node_type')<p class="lf-form-error">{{ $message }}</p>@enderror
    </div>

    <div class="lf-form-group admin-form-field">
        <x-form-label :for="$definitionFieldId.'-name'" :value="__('lf.LF_learning_name')" required />
        <input id="{{ $definitionFieldId }}-name" name="canonical_name" class="lf-form-control" required maxlength="255"
               value="{{ old('canonical_name', $definition->canonical_name ?? '') }}" @disabled($identityLocked)>
        @error('canonical_name')<p class="lf-form-error">{{ $message }}</p>@enderror
    </div>

    <div class="lf-form-group admin-form-field--full">
        <x-form-label :for="$definitionFieldId.'-description'" :value="__('lf.LF_learning_description')" />
        <textarea id="{{ $definitionFieldId }}-description" name="description" class="lf-form-control" rows="3"
                  maxlength="5000">{{ old('description', $definition->description ?? '') }}</textarea>
        @error('description')<p class="lf-form-error">{{ $message }}</p>@enderror
    </div>
</div>

@if ($identityLocked)
    {{-- Disabled inputs are not submitted; the frozen identity travels as hidden fields so the
         update carries the unchanged values the service compares against. --}}
    <input type="hidden" name="code" value="{{ $definition->code }}">
    <input type="hidden" name="node_type" value="{{ $definition->node_type }}">
    <input type="hidden" name="canonical_name" value="{{ $definition->canonical_name }}">
    <p class="admin-form-inline-notice">
        <span class="admin-form-inline-notice-icon" aria-hidden="true">i</span>
        <span>{{ __('lf.LF_learning_definition_identity_locked') }}</span>
    </p>
@endif
