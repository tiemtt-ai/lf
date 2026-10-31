{{-- Page links of lfLessonPager(): shown only when the matching lessons need more than one page. --}}
<div class="mk-table-foot ui-lesson-pager" x-show="pages > 1" x-cloak>
    <span class="mk-footnote" role="status" x-text="summary()"></span>
    <nav class="mk-pages" aria-label="{{ __('lf.LF_course_template_lesson_pager_label') }}">
        <button type="button" class="mk-page cursor-interaction" x-bind:disabled="page === 1" x-on:click="go(page - 1)"
                aria-label="{{ html_entity_decode(__('pagination.previous'), ENT_QUOTES, 'UTF-8') }}">‹</button>
        <template x-for="number in pages" x-bind:key="number">
            <button type="button" class="mk-page cursor-interaction" x-on:click="go(number)"
                    x-bind:aria-current="number === page ? 'page' : null" x-text="number"></button>
        </template>
        <button type="button" class="mk-page cursor-interaction" x-bind:disabled="page === pages" x-on:click="go(page + 1)"
                aria-label="{{ html_entity_decode(__('pagination.next'), ENT_QUOTES, 'UTF-8') }}">›</button>
    </nav>
</div>
