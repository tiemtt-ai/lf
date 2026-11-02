@php
    // Fixed starting scale; the codes and thresholds stay editable and the service validates the result.
    $levels = [
        ['key' => 'not_started', 'threshold' => 0],
        ['key' => 'developing', 'threshold' => 50],
        ['key' => 'proficient', 'threshold' => 75],
        ['key' => 'mastered', 'threshold' => 90],
    ];
@endphp
<form class="ui-modal__form" method="POST" novalidate action="{{ route('admin.learning-frameworks.store') }}">
    @csrf
    <header class="ui-modal__header">
        <div>
            <h3 id="framework-dialog-title">{{ __('lf.LF_learning_create_framework') }}</h3>
            <p class="ui-caption">{{ __('lf.LF_learning_simple_create_help') }}</p>
        </div>
        <button class="ui-modal__close cursor-interaction" type="button" data-ui-dialog-form-cancel
                aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
    </header>
    <div class="ui-modal__body">
        <div class="ui-modal__grid">
            <div class="ui-field ui-field--full">
                <label class="ui-field__label" for="framework-form-name"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_learning_name') }}</label>
                <input id="framework-form-name" type="text" name="name" required maxlength="255"
                       placeholder="{{ __('lf.LF_learning_simple_create_name_placeholder') }}">
            </div>

            <div class="ui-field ui-field--full">
                <label class="ui-field__label" for="framework-form-description">{{ __('lf.LF_learning_description') }}</label>
                <textarea id="framework-form-description" name="description" rows="2" maxlength="5000"></textarea>
            </div>

            <div class="ui-field ui-field--full">
                <span class="ui-field__label ui-framework-scale__label">
                    {{ __('lf.LF_learning_simple_scale_title') }}
                    <x-info-tip :text="__('lf.LF_learning_threshold_percent_help')" />
                </span>
                <div class="ui-framework-scale" role="group" aria-label="{{ __('lf.LF_learning_simple_scale_title') }}" data-ui-error-for="mastery_scale">
                    <div class="ui-framework-scale__row ui-framework-scale__row--head" aria-hidden="true">
                        <span>{{ __('lf.LF_learning_simple_scale_col_level') }}</span>
                        <span>{{ __('lf.LF_learning_simple_scale_level_code') }}</span>
                        <span>{{ __('lf.LF_learning_threshold_percent') }}</span>
                    </div>
                    @foreach ($levels as $i => $level)
                        @php($levelName = __('lf.LF_learning_simple_level_'.$level['key']))
                        <div class="ui-framework-scale__row" x-data="{ threshold: @js((string) $level['threshold']) }">
                            <span class="ui-framework-scale__name">{{ $levelName }}</span>
                            <div class="ui-field">
                                <label class="ui-sr-only" for="framework-form-level-key-{{ $i }}">{{ __('lf.LF_learning_simple_scale_level_code') }}: {{ $levelName }}</label>
                                <input id="framework-form-level-key-{{ $i }}" type="text" name="mastery_scale[levels][{{ $i }}][key]"
                                       required maxlength="100" value="{{ $level['key'] }}">
                            </div>
                            <div class="ui-field" data-ui-error-for="mastery_scale.levels.{{ $i }}.threshold">
                                <label class="ui-sr-only" for="framework-form-level-threshold-{{ $i }}">{{ __('lf.LF_learning_threshold_percent') }}: {{ $levelName }}</label>
                                <input id="framework-form-level-threshold-{{ $i }}" type="number" min="0" max="100" step="any"
                                       x-model="threshold" value="{{ $level['threshold'] }}">
                                <input type="hidden" name="mastery_scale[levels][{{ $i }}][threshold]" value="{{ $level['threshold'] / 100 }}"
                                       :value="threshold === '' ? '' : Number((Number(threshold) / 100).toFixed(12))">
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <details class="ui-field--full ui-framework-codes">
                <summary class="cursor-interaction">{{ __('lf.LF_learning_create_codes') }}</summary>
                <p class="ui-caption">{{ __('lf.LF_learning_create_codes_help') }}</p>
                <div class="ui-modal__grid">
                    <div class="ui-field">
                        <label class="ui-field__label" for="framework-form-code">{{ __('lf.LF_learning_simple_field_code') }}</label>
                        <input id="framework-form-code" type="text" name="code" maxlength="100" autocomplete="off">
                    </div>
                    <div class="ui-field">
                        <label class="ui-field__label" for="framework-form-scale-key"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_learning_scale_key') }}</label>
                        <input id="framework-form-scale-key" type="text" name="mastery_scale_key" required maxlength="100" value="lf-default">
                        <input type="hidden" name="mastery_scale_version" value="1">
                    </div>
                </div>
            </details>
        </div>
    </div>
    <footer class="ui-modal__footer">
        <span class="ui-caption ui-field__error" role="alert" data-ui-dialog-form-feedback></span>
        <button type="button" class="ui-button cursor-interaction" data-ui-dialog-form-cancel>{{ __('lf.LF_common_button_cancel') }}</button>
        <button type="submit" class="ui-button ui-button--primary cursor-interaction" data-ui-dialog-form-submit>
            <x-backend-icon name="check" class="ui-icon" />
            {{ __('lf.LF_common_button_confirm') }}
        </button>
    </footer>
</form>
