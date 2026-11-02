{{-- Client-side filter fields of a Competency table; the parent x-data holds keyword and nodeType. --}}
<div class="ui-field mk-search">
    <label class="ui-field__label ui-sr-only" for="{{ $searchId }}">{{ __('lf.LF_common_button_search') }}</label>
    <div class="ui-search">
        <input id="{{ $searchId }}" type="search" x-model="keyword" maxlength="255"
               placeholder="{{ __('lf.LF_learning_competency_search_placeholder') }}">
        <span class="ui-button" aria-hidden="true">
            <svg class="ui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><circle cx="11" cy="11" r="8" /><path d="m21 21-4.3-4.3" /></svg>
        </span>
    </div>
</div>
<div class="ui-field mk-search">
    <label class="ui-field__label ui-sr-only" for="{{ $searchId }}-type">{{ __('lf.LF_learning_type') }}</label>
    <select id="{{ $searchId }}-type" x-model="nodeType" data-ui-kit-select
            data-ui-empty-label="{{ __('lf.LF_learning_competency_search_empty') }}"
            data-ui-clear-label="{{ __('lf.LF_learning_filter_clear') }}">
        <option value="">{{ __('lf.LF_learning_filter_all_types') }}</option>
        @foreach (['objective', 'competency', 'concept'] as $type)
            <option value="{{ $type }}">{{ __('lf.LF_learning_simple_type_'.$type) }}</option>
        @endforeach
    </select>
</div>
<button type="reset" class="ui-button ui-button--outline cursor-interaction" x-show="keyword !== '' || nodeType !== ''" x-cloak
        @click="keyword = ''; nodeType = ''; $nextTick(() => $el.closest('form').querySelector('select').dispatchEvent(new Event('change')))">{{ __('lf.LF_learning_filter_clear') }}</button>
