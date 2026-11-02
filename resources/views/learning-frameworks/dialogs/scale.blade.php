@php
    // Saving a changed scale gets the next scale version, so new Versions can be told apart from old ones.
    $currentScaleVersion = (string) $framework->default_mastery_scale_version;
    $nextScaleVersion = ctype_digit($currentScaleVersion) ? (string) ((int) $currentScaleVersion + 1) : $currentScaleVersion.'-2';
    $editLevels = collect($masteryScale['levels'])
        ->map(fn ($level) => ['key' => (string) $level['key'], 'threshold' => (string) round((float) $level['threshold'] * 100, 10)])
        ->values();
    $levelNames = collect($masteryScale['levels'])->mapWithKeys(fn ($level) => [
        $level['key'] => \Illuminate\Support\Facades\Lang::has('lf.LF_learning_simple_level_'.$level['key'])
            ? __('lf.LF_learning_simple_level_'.$level['key'])
            : $level['key'],
    ]);
@endphp
<x-learning-framework-dialog :title="__('lf.LF_learning_simple_scale_edit')" :help="__('lf.LF_learning_simple_scale_edit_help')"
    :action="route('admin.learning-frameworks.update', $framework->id)" data-lf-framework-scale-form x-data="{ levels: {{ \Illuminate\Support\Js::from($editLevels) }} }">
    <x-slot:hidden>
        @method('PUT')
        <input type="hidden" name="code" value="{{ $framework->code }}">
        <input type="hidden" name="name" value="{{ $framework->name }}">
        <input type="hidden" name="description" value="{{ $framework->description }}">
        <input type="hidden" name="mastery_scale_key" value="{{ $framework->default_mastery_scale_key }}">
        <input type="hidden" name="mastery_scale_version" value="{{ $nextScaleVersion }}">
    </x-slot:hidden>
    <div class="ui-field">
        <span class="ui-field__label ui-framework-scale__label">
            {{ __('lf.LF_learning_simple_scale_title') }}
            <x-info-tip :text="__('lf.LF_learning_threshold_percent_help')" />
        </span>
        <div class="ui-framework-scale ui-framework-scale--edit" role="group" aria-label="{{ __('lf.LF_learning_simple_scale_title') }}" data-ui-error-for="mastery_scale">
            <div class="ui-framework-scale__row ui-framework-scale__row--head" aria-hidden="true">
                <span>{{ __('lf.LF_learning_simple_scale_col_level') }}</span>
                <span>{{ __('lf.LF_learning_simple_scale_level_code') }}</span>
                <span>{{ __('lf.LF_learning_threshold_percent') }}</span>
                <span></span>
            </div>
            <template x-for="(level, index) in levels" :key="index">
                <div class="ui-framework-scale__row">
                    <span class="ui-framework-scale__name" x-text="({{ \Illuminate\Support\Js::from($levelNames) }})[level.key] || {{ \Illuminate\Support\Js::from(__('lf.LF_learning_simple_scale_col_level')) }} + ' ' + (index + 1)"></span>
                    <div class="ui-field">
                        <label class="ui-sr-only" :for="'scale-level-key-' + index" x-text="{{ \Illuminate\Support\Js::from(__('lf.LF_learning_simple_scale_level_code')) }} + ' ' + (index + 1)"></label>
                        <input type="text" :id="'scale-level-key-' + index" :name="'mastery_scale[levels][' + index + '][key]'" x-model="level.key" required maxlength="100" autocomplete="off">
                    </div>
                    <div class="ui-field" :data-ui-error-for="'mastery_scale.levels.' + index + '.threshold'">
                        <label class="ui-sr-only" :for="'scale-level-threshold-' + index" x-text="{{ \Illuminate\Support\Js::from(__('lf.LF_learning_threshold_percent')) }} + ' ' + (index + 1)"></label>
                        <input type="number" min="0" max="100" step="any" :id="'scale-level-threshold-' + index" x-model="level.threshold">
                        <input type="hidden" :name="'mastery_scale[levels][' + index + '][threshold]'"
                               :value="level.threshold === '' ? '' : Number((Number(level.threshold) / 100).toFixed(12))">
                    </div>
                    <button type="button" class="ux-row-delete cursor-interaction" x-on:click="levels.splice(index, 1)" x-bind:disabled="levels.length <= 2"
                            :aria-label="{{ \Illuminate\Support\Js::from(__('lf.LF_learning_simple_scale_remove')) }} + ' ' + (index + 1)">
                        <x-backend-icon name="trash" class="ui-icon" />
                    </button>
                </div>
            </template>
        </div>
        <div>
            <button type="button" class="ui-button ui-button--outline cursor-interaction" x-on:click="levels.push({ key: '', threshold: '' })">
                <svg class="ui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
                {{ __('lf.LF_learning_simple_scale_add') }}
            </button>
        </div>
    </div>
</x-learning-framework-dialog>
