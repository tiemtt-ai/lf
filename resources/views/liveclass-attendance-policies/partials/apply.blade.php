{{-- "Áp dụng cho lớp": tick the Cohorts that use this policy. On the default policy its own Cohorts are ticked and locked;
     to move one elsewhere, open the other policy and tick it there. --}}
@php
    $isDefault = (bool) $policy->is_default;
    $rows = $cohorts->map(fn (object $cohort): array => [
        'id' => (int) $cohort->id,
        'name' => $cohort->name,
        'code' => (string) ($cohort->code ?? ''),
        'status' => $cohort->status,
        'statusLabel' => __('lf.LF_course_cohort_common_'.$cohort->status),
        'policyId' => $cohort->policy_id,
        'policyName' => $cohort->policy_name,
        'own' => $cohort->policy_id === (int) $policy->id,
    ])->values();
    $policyOptions = $cohorts->pluck('policy_name', 'policy_id')->sort();
    $closeUrl = route('admin.attendance-policies.index');
@endphp
<dialog class="ui-modal ui-attendance-policy-apply" data-columns="2" aria-labelledby="attendance-policy-apply-title"
        x-data="{
            submitting: false,
            isDefault: @js($isDefault),
            cohorts: @js($rows),
            ticked: @js($rows->where('own', true)->pluck('id')->values()),
            keyword: '', policyFilter: '', statusFilter: '',
            locked(cohort) { return this.isDefault && cohort.own },
            visible() {
                const needle = this.keyword.trim().toLowerCase()
                return this.cohorts.filter(cohort => (! needle || cohort.name.toLowerCase().includes(needle) || cohort.code.toLowerCase().includes(needle))
                    && (! this.policyFilter || String(cohort.policyId) === this.policyFilter)
                    && (! this.statusFilter || cohort.status === this.statusFilter))
            },
            joining() { return this.cohorts.filter(cohort => ! cohort.own && this.ticked.includes(cohort.id)) },
            leaving() { return this.cohorts.filter(cohort => cohort.own && ! this.ticked.includes(cohort.id)) },
            names(list) { return list.slice(0, 5).map(cohort => cohort.name).join(', ') + (list.length > 5 ? '…' : '') },
        }"
        x-init="$nextTick(() => $el.showModal())" x-on:close="window.location.assign(@js($closeUrl))"
        x-on:cancel="if (submitting) $event.preventDefault()">
    <form method="POST" action="{{ route('admin.attendance-policies.apply', $policy->id) }}" class="ui-modal__form"
          x-on:submit="if (submitting) { $event.preventDefault(); return } submitting = true">
        @csrf @method('PUT')
        <header class="ui-modal__header">
            <div>
                <h3 id="attendance-policy-apply-title">{{ __('lf.LF_attendance_policy_apply_title', ['name' => $policy->name]) }}</h3>
                <p class="ui-caption">{{ $isDefault ? __('lf.LF_attendance_policy_apply_help_default') : __('lf.LF_attendance_policy_apply_help') }}</p>
            </div>
            <button type="button" class="ui-modal__close cursor-interaction" x-on:click="$root.close()" :disabled="submitting" aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
        </header>
        <div class="ui-modal__body">
            <template x-for="cohort in cohorts" :key="'shown-' + cohort.id"><input type="hidden" name="shown_cohort_ids[]" :value="cohort.id"></template>
            <template x-for="id in ticked" :key="'ticked-' + id"><input type="hidden" name="cohort_ids[]" :value="id"></template>
            @if ($cohorts->isEmpty())
                <p class="ui-caption" role="status">{{ __('lf.LF_attendance_policy_apply_empty') }}</p>
            @else
                <div class="ui-attendance-policy-apply__filters">
                    <div class="ui-search">
                        <input type="search" x-model="keyword" aria-label="{{ __('lf.LF_attendance_policy_apply_search') }}" placeholder="{{ __('lf.LF_attendance_policy_apply_search') }}">
                    </div>
                    <label class="ui-sr-only" for="attendance-policy-apply-policy">{{ __('lf.LF_attendance_policy_apply_filter_policy') }}</label>
                    <select id="attendance-policy-apply-policy" x-model="policyFilter" data-ui-kit-select
                            data-ui-clear-label="{{ __('lf.LF_attendance_policy_apply_filter_policy') }}">
                        <option value="">{{ __('lf.LF_attendance_policy_apply_filter_policy') }}</option>
                        @foreach ($policyOptions as $policyId => $policyName)
                            <option value="{{ $policyId }}">{{ $policyName }}</option>
                        @endforeach
                    </select>
                    <label class="ui-sr-only" for="attendance-policy-apply-status">{{ __('lf.LF_course_cohort_common_all_statuses') }}</label>
                    <select id="attendance-policy-apply-status" x-model="statusFilter" data-ui-kit-select
                            data-ui-clear-label="{{ __('lf.LF_course_cohort_common_all_statuses') }}">
                        <option value="">{{ __('lf.LF_course_cohort_common_all_statuses') }}</option>
                        @foreach ($cohorts->pluck('status')->unique() as $status)
                            <option value="{{ $status }}">{{ __('lf.LF_course_cohort_common_'.$status) }}</option>
                        @endforeach
                    </select>
                </div>
                <ul class="ui-attendance-policy-apply__list" aria-label="{{ __('lf.LF_course_cohort_common_title') }}">
                    <template x-for="cohort in visible()" :key="cohort.id">
                        <li>
                            <label class="ui-attendance-policy-apply__row" x-bind:class="{ 'is-locked': locked(cohort) }">
                                <input type="checkbox" :value="cohort.id" x-model.number="ticked" x-bind:disabled="locked(cohort)">
                                <span class="ui-attendance-policy-apply__copy">
                                    <b x-text="cohort.name"></b>
                                    <small><span x-text="cohort.code || '—'"></span> · <span x-text="cohort.statusLabel"></span></small>
                                </span>
                                <span class="ui-caption" x-text="cohort.policyName"></span>
                            </label>
                        </li>
                    </template>
                    <li x-show="visible().length === 0" x-cloak><p class="ui-caption" role="status">{{ __('lf.LF_attendance_policy_apply_no_match') }}</p></li>
                </ul>
                <div class="ui-attendance-policy-apply__summary" role="status" aria-live="polite">
                    <strong>{{ __('lf.LF_attendance_policy_apply_summary') }}</strong>
                    <p x-show="joining().length === 0 && leaving().length === 0">{{ __('lf.LF_attendance_policy_apply_summary_none') }}</p>
                    <p x-show="joining().length > 0" x-cloak>
                        <span x-text="@js(__('lf.LF_attendance_policy_apply_summary_join', ['name' => $policy->name])).replace(':count', joining().length)"></span>
                        <span class="ui-caption" x-text="names(joining())"></span>
                    </p>
                    <p x-show="leaving().length > 0" x-cloak>
                        <span x-text="@js(__('lf.LF_attendance_policy_apply_summary_leave')).replace(':count', leaving().length)"></span>
                        <span class="ui-caption" x-text="names(leaving())"></span>
                    </p>
                </div>
            @endif
        </div>
        <footer class="ui-modal__footer">
            <span></span>
            <button type="button" class="ui-button cursor-interaction" x-on:click="$root.close()" :disabled="submitting">{{ __('lf.LF_common_button_cancel') }}</button>
            <button type="submit" class="ui-button ui-button--primary cursor-interaction"
                    :disabled="submitting || (joining().length === 0 && leaving().length === 0)">
                <x-backend-icon name="check" class="ui-icon" />
                {{ __('lf.LF_attendance_policy_apply_confirm') }}
            </button>
        </footer>
    </form>
</dialog>
