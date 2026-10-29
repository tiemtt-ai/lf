{{-- Client-side search and fold-all of one structure panel; the panel holds courseTemplateContentTools(). --}}
<div class="course-template-content-tools">
    <label class="course-template-content-search">
        <x-backend-icon name="search" class="ui-icon" />
        <span class="ui-sr-only">{{ __('lf.LF_course_template_content_search_label') }}</span>
        <input type="search"
               x-model="query"
               x-on:input="filter()"
               placeholder="{{ $placeholder }}">
    </label>
    <button type="button"
            class="admin-link-button admin-text-action ui-row-action course-template-content-fold-all"
            x-on:click="toggleAll()"
            aria-label="{{ __('lf.LF_course_template_content_toggle_all') }}"
            data-tooltip="{{ __('lf.LF_course_template_content_toggle_all') }}">
        <x-backend-icon name="chevrons-up-down" class="ui-icon" />
    </button>
</div>
