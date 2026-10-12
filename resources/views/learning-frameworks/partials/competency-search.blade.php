<form class="lf-framework-index__filter-grid lf-framework__search lf-framework__search--with-type" role="search" @submit.prevent>
    <div class="lf-form-group">
        <label class="lf-form-label" for="{{ $searchId }}">{{ __('lf.LF_common_button_search') }}</label>
        <input id="{{ $searchId }}" type="search" class="lf-form-control" x-model="keyword" maxlength="255"
               placeholder="{{ __('lf.LF_learning_competency_search_placeholder') }}">
    </div>
    <div class="lf-form-group">
        <label class="lf-form-label" for="{{ $searchId }}-type">{{ __('lf.LF_learning_type') }}</label>
        <select id="{{ $searchId }}-type" class="lf-form-control" x-model="nodeType">
            <option value="">{{ __('lf.LF_learning_filter_all_types') }}</option>
            @foreach (['objective', 'competency', 'concept'] as $type)
                <option value="{{ $type }}">{{ $typeLabel($type) }}</option>
            @endforeach
        </select>
    </div>
    <div class="admin-form-actions lf-framework-index__filter-actions">
        <button type="button" class="admin-link-button admin-text-action" x-show="keyword !== '' || nodeType !== ''" x-cloak
                @click="keyword = ''; nodeType = ''; $nextTick(() => document.getElementById(@js($searchId)).focus())">{{ __('lf.LF_learning_filter_clear') }}</button>
        <span class="lf-framework__meta" role="status" aria-live="polite"
              x-text="`${resultCount} / ${nodes.length}`"></span>
    </div>
</form>
<div class="admin-form-empty-state" role="status" x-show="resultCount === 0" x-cloak>
    <strong>{{ __('lf.LF_learning_competency_search_empty') }}</strong>
    <span>{{ __('lf.LF_learning_competency_search_help') }}</span>
</div>
