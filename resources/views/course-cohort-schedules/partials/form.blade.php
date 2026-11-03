{{-- Schedule add/edit popup: fields on the left, the live preview on the right. --}}
@php
    $editing = $mode === 'edit';
    $formKey = 'schedule_'.$mode;
    $retry = old('_form') === $formKey;
    $idPrefix = $editing ? 'edit_schedule' : 'schedule';
    $dialogTitle = $editing ? __('lf.LF_common_button_edit').': '.$schedule->name : __('lf.LF_course_cohort_schedule_add');
    $savedSlots = $slots->map(fn ($slot) => [
        'weekday' => (string) $slot->weekday,
        'start_time' => substr($slot->start_time, 0, 5),
        'end_time' => substr($slot->end_time, 0, 5),
        'persisted' => true,
    ])->values()->all();
    $savedSlots = $savedSlots ?: [['weekday' => '1', 'start_time' => '', 'end_time' => '', 'persisted' => false]];
    $savedExclusions = $exclusions->map(fn ($item) => [
        'excluded_on' => substr((string) $item->excluded_on, 0, 10),
        'reason' => $item->reason,
    ])->values()->all();
    $savedFields = [
        'name' => $schedule->name ?? '',
        'timezone' => $schedule->timezone ?? config('app.timezone'),
        'startsOn' => $schedule->starts_on ?? $cohort->start_date ?? '',
        'endsOn' => $schedule->ends_on ?? $cohort->end_date ?? '',
    ];
    $initialSlots = collect($retry ? old('slots', $savedSlots) : $savedSlots)->values()->map(fn ($slot, $index) => array_merge($slot, [
        'persisted' => (bool) ($slot['persisted'] ?? ($editing && $slots->values()->has($index))),
        'errors' => $retry ? [
            'weekday' => $errors->first("slots.$index.weekday"),
            'start_time' => $errors->first("slots.$index.start_time"),
            'end_time' => $errors->first("slots.$index.end_time"),
        ] : [],
    ]))->all();
    $initialExclusions = array_values($retry ? old('exclusions', $savedExclusions) : $savedExclusions);
    $summaryErrors = $retry
        ? collect($errors->messages())->reject(fn ($messages, $key) => preg_match('/^slots\.\d+\.(weekday|start_time|end_time)$/', $key))->flatten()
        : collect();
    $initialFields = [
        'name' => $retry ? old('name', $savedFields['name']) : $savedFields['name'],
        'timezone' => $retry ? old('timezone', $savedFields['timezone']) : $savedFields['timezone'],
        'startsOn' => $retry ? old('starts_on', $savedFields['startsOn']) : $savedFields['startsOn'],
        'endsOn' => $retry ? old('ends_on', $savedFields['endsOn']) : $savedFields['endsOn'],
    ];
@endphp
<dialog class="ui-modal ui-cohort-schedule-modal" data-columns="3" aria-labelledby="{{ $idPrefix }}_title"
     x-data="{
        slots: [], exclusions: [], slotKey: 0,
        saved: @js(['fields' => $savedFields, 'slots' => $savedSlots, 'exclusions' => $savedExclusions]),
        name: @js($initialFields['name']), timezone: @js($initialFields['timezone']),
        startsOn: @js($initialFields['startsOn']), endsOn: @js($initialFields['endsOn']),
        preview: [], previewCount: 0, previewLoading: false, previewError: '', previewTimer: null, previewToken: 0,
        maxSlots: 50, maxExclusions: 366, submitting: false, showErrors: true,
        withKey(slot) { return { errors: {}, persisted: false, ...slot, key: ++this.slotKey } },
        open() { this.$refs.dialog.showModal(); this.queuePreview() },
        addSlot() {
            if (this.slots.length >= this.maxSlots) return
            const slot = this.withKey({ weekday: '', start_time: '', end_time: '' })
            this.slots.push(slot)
            this.$nextTick(() => document.getElementById(`{{ $idPrefix }}_start_${slot.key}`)?.focus())
        },
        async removeSlot(index) {
            if (this.slots.length <= 1) return
            const slot = this.slots[index]
            if (slot.persisted || slot.weekday || slot.start_time || slot.end_time) {
                if (!window.LFConfirm) return
                const confirmed = await window.LFConfirm.open({
                    title: @js(__('lf.LF_course_cohort_schedule_remove_slot_title')),
                    message: @js(__('lf.LF_course_cohort_schedule_remove_slot_confirm')),
                    confirmLabel: @js(__('lf.LF_common_button_remove')),
                    tone: 'danger'
                })
                if (!confirmed) return
            }
            this.slots.splice(index, 1)
            this.queuePreview()
        },
        dateFrom(field) { return field.parentElement.querySelector('input[type=hidden]')?.value ?? '' },
        addExclusion(field) {
            const date = this.dateFrom(field)
            this.setDate(field, '')
            if (!date || this.exclusions.length >= this.maxExclusions || this.exclusions.some(item => item.excluded_on === date)) return
            this.exclusions.push({ excluded_on: date, reason: '' })
            this.exclusions.sort((a, b) => a.excluded_on.localeCompare(b.excluded_on))
        },
        removeExclusion(index) { this.exclusions.splice(index, 1); this.queuePreview() },
        // The kit date field shows dd/mm/yyyy and keeps the submitted yyyy-mm-dd in its hidden sibling.
        setDate(field, iso) {
            if (!field) return
            field.value = iso ? iso.split('-').reverse().join('/') : ''
            field.dataset.iso = iso
            const hidden = field.parentElement.querySelector('input[type=hidden]')
            if (hidden) hidden.value = iso
        },
        displayDate(iso) { return iso ? iso.split('-').reverse().join('/') : '' },
        queuePreview() { clearTimeout(this.previewTimer); this.previewTimer = setTimeout(() => this.loadPreview(), 250) },
        async loadPreview() {
            const token = ++this.previewToken
            if (!this.startsOn || !this.endsOn || !this.timezone || this.slots.some(slot => !slot.weekday || !slot.start_time || !slot.end_time)) {
                this.preview = []; this.previewCount = 0; this.previewError = ''; this.previewLoading = false; return
            }
            this.previewLoading = true; this.previewError = ''
            try {
                const response = await fetch(@js(route('admin.course-cohorts.schedules.preview', $cohort->id)), {
                    method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': @js(csrf_token()) },
                    body: JSON.stringify({
                        starts_on: this.startsOn, ends_on: this.endsOn, timezone: this.timezone,
                        slots: this.slots.map(({ weekday, start_time, end_time }) => ({ weekday, start_time, end_time })),
                        exclusions: this.exclusions.filter(item => item.excluded_on)
                    })
                })
                const payload = await response.json()
                if (token !== this.previewToken) return
                if (!response.ok) { this.preview = []; this.previewCount = 0; this.previewError = Object.values(payload.errors || {}).flat()[0] || @js(__('lf.LF_course_cohort_schedule_preview_failed')); return }
                this.preview = payload.data; this.previewCount = payload.count
            } catch (error) {
                if (token === this.previewToken) this.previewError = @js(__('lf.LF_course_cohort_schedule_preview_failed'))
            } finally {
                if (token === this.previewToken) this.previewLoading = false
            }
        },
        dismiss() {
            if (this.$refs.dialog.open) this.$refs.dialog.close()
            this.closed()
        },
        closed() {
            this.showErrors = false
            @if ($editing)
                history.replaceState(null, '', @js(route('admin.course-cohorts.show', ['id' => $cohort->id, 'tab' => 'schedules'])))
            @else
                this.name = this.saved.fields.name
                this.timezone = this.saved.fields.timezone
                this.startsOn = this.saved.fields.startsOn
                this.endsOn = this.saved.fields.endsOn
                this.$refs.timezoneSelect.value = this.timezone
                this.$refs.timezoneSelect.dispatchEvent(new Event('change', { bubbles: true }))
                this.setDate(this.$refs.startsOnField, this.startsOn)
                this.setDate(this.$refs.endsOnField, this.endsOn)
                this.slots = this.saved.slots.map(slot => this.withKey(slot))
                this.exclusions = this.saved.exclusions.map(item => ({ ...item }))
                this.queuePreview()
            @endif
        },
        init() {
            this.slots = @js($initialSlots).map(slot => this.withKey(slot))
            this.exclusions = @js($initialExclusions);
            if (@js($retry || $autoOpen)) this.$nextTick(() => this.open())
        }
     }"
     x-ref="dialog"
     @unless ($editing) x-on:schedule-create-open.window="open()" @endunless
     x-on:close="closed()" x-on:cancel="if (submitting) $event.preventDefault()">
    <form method="POST" action="{{ $action }}" class="ui-modal__form"
          x-on:submit="if (submitting) { $event.preventDefault(); return } submitting = true"
          x-on:input.debounce.250ms="queuePreview()" x-on:change="queuePreview()">
        @csrf
        @if ($editing) @method('PUT') @endif
        <input type="hidden" name="_form" value="{{ $formKey }}">
        <header class="ui-modal__header">
            <div><h3 id="{{ $idPrefix }}_title">{{ $dialogTitle }}</h3></div>
            <button type="button" class="ui-modal__close cursor-interaction" x-on:click="dismiss()" :disabled="submitting" aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
        </header>
        <div class="ui-modal__body">
            @if ($summaryErrors->isNotEmpty())
                <div class="ui-field__error ui-cohort-manage-error" role="alert" x-show="showErrors">
                    @foreach ($summaryErrors as $error)<p>{{ $error }}</p>@endforeach
                </div>
            @endif
            <div class="ui-cohort-schedule-layout">
                <div class="ui-cohort-schedule-fields">
                    <div class="ui-cohort-schedule-grid">
                        <div class="ui-field">
                            <label class="ui-field__label" for="{{ $idPrefix }}_name"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_course_cohort_schedule_name') }}</label>
                            <input id="{{ $idPrefix }}_name" name="name" maxlength="255" required autocomplete="off" x-model="name" placeholder="{{ __('lf.LF_course_cohort_schedule_name_placeholder') }}">
                        </div>
                        <div class="ui-field">
                            <label class="ui-field__label" for="{{ $idPrefix }}_timezone"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_course_cohort_schedule_timezone') }}</label>
                            <select id="{{ $idPrefix }}_timezone" name="timezone" required data-ui-kit-select x-ref="timezoneSelect" x-model="timezone">
                                @foreach ($timezones as $item)<option value="{{ $item }}" @selected($item === $initialFields['timezone'])>{{ $item }}</option>@endforeach
                            </select>
                        </div>
                        <div class="ui-field">
                            <label class="ui-field__label" for="{{ $idPrefix }}_starts_on"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_course_cohort_schedule_starts_on') }}</label>
                            <x-ui-date-input :id="$idPrefix.'_starts_on'" name="starts_on" :value="$initialFields['startsOn']" :min="$cohort->start_date" :max="$cohort->end_date" required
                                             x-ref="startsOnField" x-on:change="startsOn = dateFrom($el)" />
                        </div>
                        <div class="ui-field">
                            <label class="ui-field__label" for="{{ $idPrefix }}_ends_on"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_course_cohort_schedule_ends_on') }}</label>
                            <x-ui-date-input :id="$idPrefix.'_ends_on'" name="ends_on" :value="$initialFields['endsOn']" :min="$cohort->start_date" :max="$cohort->end_date" required
                                             x-ref="endsOnField" x-on:change="endsOn = dateFrom($el)" />
                        </div>
                    </div>

                    <div class="ui-cohort-schedule-group" role="group" aria-labelledby="{{ $idPrefix }}_slots_label">
                        <p id="{{ $idPrefix }}_slots_label" class="ui-field__label"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_course_cohort_schedule_slots') }}</p>
                        <p class="ui-caption">{{ __('lf.LF_course_cohort_schedule_slots_help') }}</p>
                        <template x-for="(slot, index) in slots" :key="slot.key">
                            <div class="ui-cohort-schedule-slot">
                                <div class="ui-field">
                                    <label class="ui-field__label" x-bind:for="`{{ $idPrefix }}_weekday_${slot.key}`">{{ __('lf.LF_course_cohort_schedule_weekday') }}</label>
                                    <select data-ui-kit-select x-bind:id="`{{ $idPrefix }}_weekday_${slot.key}`" x-bind:name="`slots[${index}][weekday]`" x-model="slot.weekday" required
                                            x-init="$nextTick(() => window.LFUiKitSelect?.($el))">
                                        <option value="">{{ __('lf.LF_course_cohort_schedule_select_weekday') }}</option>
                                        @foreach (range(1, 7) as $weekday)<option value="{{ $weekday }}">{{ __('lf.LF_course_cohort_schedule_weekday_'.$weekday) }}</option>@endforeach
                                    </select>
                                    <p class="ui-field__error" x-show="showErrors && slot.errors.weekday" x-text="slot.errors.weekday"></p>
                                </div>
                                <div class="ui-field">
                                    <label class="ui-field__label" x-bind:for="`{{ $idPrefix }}_start_${slot.key}`">{{ __('lf.LF_course_cohort_schedule_start_time') }}</label>
                                    <input type="time" x-bind:id="`{{ $idPrefix }}_start_${slot.key}`" x-bind:name="`slots[${index}][start_time]`" x-model="slot.start_time" required>
                                    <p class="ui-field__error" x-show="showErrors && slot.errors.start_time" x-text="slot.errors.start_time"></p>
                                </div>
                                <div class="ui-field">
                                    <label class="ui-field__label" x-bind:for="`{{ $idPrefix }}_end_${slot.key}`">{{ __('lf.LF_course_cohort_schedule_end_time') }}</label>
                                    <input type="time" x-bind:id="`{{ $idPrefix }}_end_${slot.key}`" x-bind:name="`slots[${index}][end_time]`" x-bind:min="slot.start_time" x-model="slot.end_time" required>
                                    <p class="ui-field__error" x-show="showErrors && slot.errors.end_time" x-text="slot.errors.end_time"></p>
                                </div>
                                <button type="button" class="ux-row-delete cursor-interaction" x-show="slots.length > 1" x-cloak x-on:click="removeSlot(index)"
                                        x-bind:aria-label="`${@js(__('lf.LF_common_button_remove'))} ${index + 1}`" data-tooltip="{{ __('lf.LF_common_button_remove') }}">
                                    <x-backend-icon name="trash" class="ui-icon" />
                                </button>
                            </div>
                        </template>
                        <div class="ui-cohort-schedule-group__foot">
                            <button type="button" class="ui-button ui-button--outline ui-button--sm cursor-interaction" x-on:click="addSlot()" x-bind:disabled="slots.length >= maxSlots">
                                <x-backend-icon name="plus" class="ui-icon" />{{ __('lf.LF_course_cohort_schedule_add_slot') }}
                            </button>
                            <p class="ui-caption" x-show="slots.length >= maxSlots" x-cloak>{{ __('lf.LF_course_cohort_schedule_slot_limit', ['count' => 50]) }}</p>
                        </div>
                    </div>

                    <div class="ui-cohort-schedule-group" role="group" aria-labelledby="{{ $idPrefix }}_exclusions_label">
                        <label id="{{ $idPrefix }}_exclusions_label" class="ui-field__label" for="{{ $idPrefix }}_exclusion_pick">{{ __('lf.LF_course_cohort_schedule_exclusions') }}</label>
                        <p class="ui-caption">{{ __('lf.LF_course_cohort_schedule_exclusions_help') }}</p>
                        <div class="ui-cohort-schedule-exclusion-pick">
                            <x-ui-date-input :id="$idPrefix.'_exclusion_pick'" name="" :min="$cohort->start_date" :max="$cohort->end_date"
                                             x-on:change="addExclusion($el)" />
                        </div>
                        <template x-for="(exclusion, index) in exclusions" :key="exclusion.excluded_on">
                            <div class="ui-cohort-schedule-exclusion">
                                <span class="ui-cohort-schedule-exclusion__date" x-text="displayDate(exclusion.excluded_on)"></span>
                                <input type="hidden" x-bind:name="`exclusions[${index}][excluded_on]`" x-bind:value="exclusion.excluded_on">
                                <input maxlength="500" x-bind:name="`exclusions[${index}][reason]`" x-model="exclusion.reason" x-bind:aria-label="`{{ __('lf.LF_course_cohort_schedule_reason') }} ${displayDate(exclusion.excluded_on)}`" placeholder="{{ __('lf.LF_course_cohort_schedule_reason_placeholder') }}">
                                <button type="button" class="ux-row-delete cursor-interaction" x-on:click="removeExclusion(index)"
                                        x-bind:aria-label="`${@js(__('lf.LF_common_button_remove'))} ${displayDate(exclusion.excluded_on)}`" data-tooltip="{{ __('lf.LF_common_button_remove') }}">
                                    <x-backend-icon name="trash" class="ui-icon" />
                                </button>
                            </div>
                        </template>
                        <p class="ui-caption" x-show="exclusions.length >= maxExclusions" x-cloak>{{ __('lf.LF_course_cohort_schedule_exclusion_limit', ['count' => 366]) }}</p>
                    </div>
                </div>

                <aside class="ui-cohort-schedule-preview" aria-labelledby="{{ $idPrefix }}_preview_label">
                    <div class="ui-cohort-schedule-preview__head">
                        <p id="{{ $idPrefix }}_preview_label" class="ui-field__label">{{ __('lf.LF_course_cohort_schedule_preview') }}</p>
                        <span class="ui-caption" x-text="previewLoading ? @js(__('lf.LF_course_cohort_schedule_preview_loading')) : @js(__('lf.LF_course_cohort_schedule_expected_count_value')).replace(':count', previewCount)"></span>
                    </div>
                    <p class="ui-caption">{{ __('lf.LF_course_cohort_schedule_preview_notice') }}</p>
                    <p class="ui-field__error" role="alert" x-show="previewError" x-text="previewError" x-cloak></p>
                    <ul class="ui-cohort-schedule-preview__list" x-show="preview.length" x-cloak>
                        <template x-for="item in preview" :key="`${item.starts_at}-${item.ends_at}`">
                            <li>
                                <span class="mk-name-text" x-text="new Intl.DateTimeFormat(@js(app()->getLocale()), { weekday: 'long', day: '2-digit', month: '2-digit', year: 'numeric', timeZone: item.timezone }).format(new Date(item.starts_at))"></span>
                                <span class="ui-caption" x-text="`${item.start_time}–${item.end_time}`"></span>
                            </li>
                        </template>
                    </ul>
                    <p class="ui-caption" x-show="!previewLoading && !preview.length && !previewError">{{ __('lf.LF_course_cohort_schedule_preview_empty') }}</p>
                </aside>
            </div>
        </div>
        <footer class="ui-modal__footer">
            <span></span>
            <button type="button" class="ui-button cursor-interaction" x-on:click="dismiss()" :disabled="submitting">{{ __('lf.LF_common_button_cancel') }}</button>
            <button type="submit" class="ui-button ui-button--primary cursor-interaction" :disabled="submitting" :aria-busy="submitting">
                <x-backend-icon name="check" class="ui-icon" />
                {{ $editing ? __('lf.LF_common_button_save_changes') : __('lf.LF_course_cohort_schedule_save') }}
            </button>
        </footer>
    </form>
</dialog>
