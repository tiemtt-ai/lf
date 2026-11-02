<x-learning-framework-dialog :title="__('lf.LF_learning_simple_add')" :help="__('lf.LF_learning_simple_add_help')"
    :action="route('admin.learning-frameworks.competencies.store', $framework->id)" data-lf-framework-add>
    <div class="ui-modal__grid">
        <div class="ui-field ui-field--full">
            <label class="ui-field__label" for="competency-name"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_learning_simple_field_name') }}</label>
            <input id="competency-name" type="text" name="canonical_name" required maxlength="255">
        </div>
        <div class="ui-field">
            <label class="ui-field__label" for="competency-type"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_learning_type') }}</label>
            <select id="competency-type" name="node_type" required data-ui-kit-select>
                @foreach (['competency', 'objective', 'concept'] as $type)
                    <option value="{{ $type }}">{{ __('lf.LF_learning_simple_type_'.$type) }}</option>
                @endforeach
            </select>
        </div>
        <div class="ui-field">
            <label class="ui-field__label" for="competency-code">{{ __('lf.LF_learning_simple_field_code') }}</label>
            <input id="competency-code" type="text" name="code" maxlength="120" autocomplete="off">
        </div>
        <div class="ui-field ui-field--full">
            <label class="ui-field__label" for="competency-description">{{ __('lf.LF_learning_description') }}</label>
            <textarea id="competency-description" name="description" rows="3" maxlength="5000"></textarea>
        </div>
    </div>
</x-learning-framework-dialog>
