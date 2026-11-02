@php($locked = (bool) $definition->identity_locked)
<x-learning-framework-dialog :title="__('lf.LF_learning_simple_edit_named', ['name' => $definition->canonical_name])"
    :help="$locked ? __('lf.LF_learning_simple_locked_hint') : null"
    :action="route('admin.learning-frameworks.definitions.update', [$definition->framework_id, $definition->id])">
    <x-slot:hidden>
        @method('PUT')
        <input type="hidden" name="framework_id" value="{{ $definition->framework_id }}">
        @if ($locked)
            {{-- The identity is frozen once a published Version uses it; it travels unchanged. --}}
            <input type="hidden" name="code" value="{{ $definition->code }}">
            <input type="hidden" name="node_type" value="{{ $definition->node_type }}">
            <input type="hidden" name="canonical_name" value="{{ $definition->canonical_name }}">
        @endif
    </x-slot:hidden>
    <div class="ui-modal__grid">
        @if ($locked)
            <div class="ui-field ui-field--full">
                <label class="ui-field__label" for="competency-{{ $definition->id }}-name-readonly">{{ __('lf.LF_learning_simple_field_name') }}</label>
                <input id="competency-{{ $definition->id }}-name-readonly" type="text" readonly value="{{ $definition->canonical_name }}">
            </div>
        @else
            <div class="ui-field ui-field--full">
                <label class="ui-field__label" for="competency-{{ $definition->id }}-name"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_learning_simple_field_name') }}</label>
                <input id="competency-{{ $definition->id }}-name" type="text" name="canonical_name" required maxlength="255" value="{{ $definition->canonical_name }}">
            </div>
            <div class="ui-field">
                <label class="ui-field__label" for="competency-{{ $definition->id }}-type"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_learning_type') }}</label>
                <select id="competency-{{ $definition->id }}-type" name="node_type" required data-ui-kit-select>
                    @foreach (['competency', 'objective', 'concept'] as $type)
                        <option value="{{ $type }}" @selected($definition->node_type === $type)>{{ __('lf.LF_learning_simple_type_'.$type) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="ui-field">
                <label class="ui-field__label" for="competency-{{ $definition->id }}-code"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_learning_code') }}</label>
                <input id="competency-{{ $definition->id }}-code" type="text" name="code" required maxlength="120" autocomplete="off" value="{{ $definition->code }}">
            </div>
        @endif
        <div class="ui-field ui-field--full">
            <label class="ui-field__label" for="competency-{{ $definition->id }}-description">{{ __('lf.LF_learning_description') }}</label>
            <textarea id="competency-{{ $definition->id }}-description" name="description" rows="3" maxlength="5000">{{ $definition->description }}</textarea>
        </div>
    </div>
</x-learning-framework-dialog>
