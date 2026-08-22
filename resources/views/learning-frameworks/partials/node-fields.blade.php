@php
    $nodeFieldId = $editing ? 'node-'.$node->id : 'node-new';
    $criteriaValue = isset($node)
        ? json_encode(json_decode($node->criteria_snapshot ?? 'null', true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        : '';
@endphp

<div class="admin-form-field-grid">
    <div class="lf-form-group admin-form-field">
        <x-form-label :for="$nodeFieldId.'-definition'" :value="__('lf.LF_learning_definition')" required />
        @if ($editing)
            <input type="hidden" name="node_definition_id" value="{{ $node->node_definition_id }}">
            <input id="{{ $nodeFieldId }}-definition" class="lf-form-control" disabled
                   value="{{ $definitions->firstWhere('id', $node->node_definition_id)?->canonical_name ?? $node->name_snapshot }}">
            <p class="lf-form-help">{{ __('lf.LF_learning_node_definition_immutable_help') }}</p>
        @else
            <select id="{{ $nodeFieldId }}-definition" name="node_definition_id" class="lf-form-control" required>
                @foreach ($definitions->where('status', 'active') as $definition)
                    <option value="{{ $definition->id }}" @selected(old('node_definition_id') == $definition->id)>
                        {{ $definition->canonical_name }} ({{ $definition->node_type }})
                    </option>
                @endforeach
            </select>
            <p class="lf-form-help">{{ __('lf.LF_learning_node_definition_help') }}</p>
        @endif
        @error('node_definition_id')<p class="lf-form-error">{{ $message }}</p>@enderror
    </div>

    <div class="lf-form-group admin-form-field">
        <x-form-label :for="$nodeFieldId.'-sequence'" :value="__('lf.LF_learning_sequence')" />
        <input id="{{ $nodeFieldId }}-sequence" type="number" min="1" name="sequence" class="lf-form-control"
               value="{{ old('sequence', $node->sequence ?? 1) }}">
        <p class="lf-form-help">{{ __('lf.LF_learning_sequence_help') }}</p>
        @error('sequence')<p class="lf-form-error">{{ $message }}</p>@enderror
    </div>

    <div class="lf-form-group admin-form-field--full">
        <x-form-label :for="$nodeFieldId.'-criteria'" :value="__('lf.LF_learning_criteria_json')" />
        <textarea id="{{ $nodeFieldId }}-criteria" name="criteria_json" class="lf-form-control lf-form-control--code"
                  rows="5" spellcheck="false"
                  placeholder="{{ __('lf.LF_learning_criteria_placeholder') }}">{{ old('criteria_json', $criteriaValue) }}</textarea>
        <p class="lf-form-help">{{ __('lf.LF_learning_criteria_help') }}</p>
        @error('criteria_json')<p class="lf-form-error">{{ $message }}</p>@enderror
    </div>
</div>
