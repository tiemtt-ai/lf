<x-learning-framework-dialog :title="__('lf.LF_learning_simple_edit_framework')" :action="route('admin.learning-frameworks.update', $framework->id)">
    <x-slot:hidden>
        @method('PUT')
        {{-- Only name and description change here; the server keeps the code and scale as stored. --}}
        <input type="hidden" name="section" value="identity">
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
