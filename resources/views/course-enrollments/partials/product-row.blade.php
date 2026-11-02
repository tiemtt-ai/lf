{{-- One product in the create wizard's product column; lives inside <template x-for="item in …">. --}}
<li :class="{ 'is-invalid-selected': isInvalidSelected(item) }">
    <div class="enr-row" :class="{ 'is-disabled': productDisabled(item) }">
        <input type="checkbox" :id="`enr-p-${item.id}`" :checked="hasProduct(item.id)" :disabled="productDisabled(item)"
               x-on:change="toggleProduct(item, $event.target.checked); $event.target.checked = hasProduct(item.id)"
               :aria-describedby="item.eligibility === 'ineligible' ? `enr-why-${item.id}` : `enr-window-${item.id}`">
        <span class="enr-row__body">
            <span class="enr-row__title">
                <label class="enr-row__name" :for="`enr-p-${item.id}`" x-text="item.title"></label>
                <span x-show="productEligibilityReady && item.eligibility === 'eligible'" x-cloak class="enr-badge enr-badge--success"><x-backend-icon name="check" class="ui-icon" />{{ __('lf.LF_bulk_enrollment_eligible') }}</span>
                <span x-show="productEligibilityReady && item.eligibility === 'ineligible'" x-cloak class="enr-badge enr-badge--danger">{{ __('lf.LF_bulk_enrollment_ineligible') }}</span>
            </span>
            <span class="enr-row__sub" x-text="productMeta(item)"></span>
            <span class="enr-row__window" :id="`enr-window-${item.id}`">
                <x-backend-icon name="calendar-range" class="ui-icon" />
                <span x-text="@js(__('lf.LF_bulk_enrollment_design_window')).replace(':window', windowState(item).label)"></span>
                <span x-show="windowState(item).note" :class="`enr-window--${windowState(item).tone}`" x-text="`· ${windowState(item).note}`"></span>
            </span>
            <span class="enr-reason" :id="`enr-why-${item.id}`" x-show="productEligibilityReady && item.eligibility === 'ineligible' && !windowState(item).blocks" x-cloak x-text="eligibilityReason(item)"></span>
            <span class="enr-row__actions" x-show="isInvalidSelected(item)" x-cloak>
                <button type="button" class="ui-button ui-button--sm cursor-interaction" x-on:click="toggleProduct(item, false)"><x-backend-icon name="x" class="ui-icon" />{{ __('lf.LF_bulk_enrollment_deselect') }}</button>
            </span>
        </span>
    </div>
</li>
