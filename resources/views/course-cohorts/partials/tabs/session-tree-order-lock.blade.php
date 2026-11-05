{{-- In place of the drag handle: this row keeps its place, and the tooltip says why. --}}
<span class="ui-bulk-lock ui-cohort-session-tree__order-lock" tabindex="0" data-tooltip="{{ __('lf.LF_course_cohort_order_lock_'.$lockReason) }}">
    <button type="button" class="ux-row-edit" disabled aria-label="{{ __('lf.LF_course_cohort_order_lock_'.$lockReason) }}">
        <x-backend-icon name="lock" class="ui-icon" />
    </button>
</span>
