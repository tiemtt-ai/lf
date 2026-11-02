<x-learning-framework-dialog :title="__('lf.LF_learning_simple_edit_framework')" :action="route('admin.learning-frameworks.update', $framework->id)">
    <x-slot:hidden>
        @method('PUT')
        {{-- Only name and description change here; the code and default scale travel unchanged. --}}
        <input type="hidden" name="code" value="{{ $framework->code }}">
        <input type="hidden" name="mastery_scale_key" value="{{ $framework->default_mastery_scale_key }}">
        <input type="hidden" name="mastery_scale_version" value="{{ $framework->default_mastery_scale_version }}">
        @foreach ($masteryScale['levels'] as $i => $level)
            <input type="hidden" name="mastery_scale[levels][{{ $i }}][key]" value="{{ $level['key'] }}">
            <input type="hidden" name="mastery_scale[levels][{{ $i }}][threshold]" value="{{ $level['threshold'] }}">
        @endforeach
    </x-slot:hidden>
    <div class="ui-modal__grid">
        <div class="ui-field ui-field--full">
            <label class="ui-field__label" for="framework-form-name"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_learning_name') }}</label>
            <input id="framework-form-name" type="text" name="name" required maxlength="255" value="{{ $framework->name }}">
        </div>
        <div class="ui-field ui-field--full">
            <label class="ui-field__label" for="framework-form-description">{{ __('lf.LF_learning_description') }}</label>
            <textarea id="framework-form-description" name="description" rows="3" maxlength="5000">{{ $framework->description }}</textarea>
        </div>
    </div>
</x-learning-framework-dialog>
