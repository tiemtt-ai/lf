{{-- Drag handle of a row in a class's programme; hidden while searching, when the visible rows are only part of the list. --}}
<button type="button"
        class="admin-link-button admin-text-action ui-row-action course-template-order-handle"
        x-sort:handle
        x-show="{{ $handleShow }}"
        aria-label="{{ $handleLabel }}"
        aria-keyshortcuts="ArrowUp ArrowDown"
        data-tooltip="{{ __('lf.LF_course_template_lesson_reorder_hint') }}"
        x-on:keydown.up.prevent="courseTemplateOrder($el.closest('[data-reorder-url]')).move($el, -1)"
        x-on:keydown.down.prevent="courseTemplateOrder($el.closest('[data-reorder-url]')).move($el, 1)">
    <x-backend-icon name="grip-vertical" class="ui-icon" />
</button>
