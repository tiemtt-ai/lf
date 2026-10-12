@php($locked = (bool) $definition->identity_locked)
<details class="lf-framework__disclosure lf-framework__item-edit">
    <summary class="btn btn-secondary" aria-label="{{ __('lf.LF_learning_simple_edit_named', ['name' => $definition->canonical_name]) }}">{{ __('lf.action_edit') }}</summary>
    <form class="lf-framework__panel" method="POST" action="{{ route('admin.learning-frameworks.definitions.update', [$definition->framework_id, $definition->id]) }}">
        @csrf
        @method('PUT')
        <input type="hidden" name="framework_id" value="{{ $definition->framework_id }}">
        @if ($locked)
            {{-- The identity is frozen once a published Version uses it; it travels unchanged. --}}
            <input type="hidden" name="code" value="{{ $definition->code }}">
            <input type="hidden" name="node_type" value="{{ $definition->node_type }}">
            <input type="hidden" name="canonical_name" value="{{ $definition->canonical_name }}">
            <p class="admin-form-inline-notice">
                <span class="admin-form-inline-notice-icon" aria-hidden="true">i</span>
                <span>{{ __('lf.LF_learning_simple_locked_hint') }}</span>
            </p>
        @else
            <div class="lf-form-group">
                <x-form-label :for="'competency-'.$definition->id.'-name'" :value="__('lf.LF_learning_simple_field_name')" required />
                <input id="competency-{{ $definition->id }}-name" name="canonical_name" class="lf-form-control" required maxlength="255" value="{{ $definition->canonical_name }}">
            </div>
            <div class="lf-framework__field-row">
                <div class="lf-form-group">
                    <x-form-label :for="'competency-'.$definition->id.'-type'" :value="__('lf.LF_learning_type')" required />
                    <select id="competency-{{ $definition->id }}-type" name="node_type" class="lf-form-control">
                        @foreach (['competency', 'objective', 'concept'] as $type)
                            <option value="{{ $type }}" @selected($definition->node_type === $type)>{{ __('lf.LF_learning_simple_type_'.$type) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="lf-form-group">
                    <x-form-label :for="'competency-'.$definition->id.'-code'" :value="__('lf.LF_learning_code')" required />
                    <input id="competency-{{ $definition->id }}-code" name="code" class="lf-form-control" required maxlength="120" autocomplete="off" value="{{ $definition->code }}">
                </div>
            </div>
        @endif
        <div class="lf-form-group">
            <x-form-label :for="'competency-'.$definition->id.'-description'" :value="__('lf.LF_learning_description')" />
            <textarea id="competency-{{ $definition->id }}-description" name="description" class="lf-form-control" rows="3" maxlength="5000">{{ $definition->description }}</textarea>
        </div>
        <div class="lf-framework__panel-actions">
            <button class="btn btn-primary" type="submit">{{ __('lf.LF_common_button_save') }}</button>
        </div>
    </form>
</details>
