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
    $weekdayLabels = collect(range(1, 7))->mapWithKeys(fn (int $weekday): array => [$weekday => __('lf.LF_course_cohort_schedule_weekday_'.$weekday)]);
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
        ? collect($errors->messages())->reject(fn ($messages, $key) => $key === 'slots' || preg_match('/^slots\.\d+\.(weekday|start_time|end_time)$/', $key))->flatten()
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
        weekdayLabels: @js($weekdayLabels),
        slotsError: @js($retry ? (string) $errors->first('slots') : ''), exclusionDraftReason: '', exclusionError: '',
        slotsFor(weekday) { return this.slots.filter(slot => String(slot.weekday) === String(weekday)) },
        addSlot(weekday) {
            if (this.slots.length >= this.maxSlots) return
            const slot = this.withKey({ weekday: String(weekday), start_time: '', end_time: '' })
            this.slots.push(slot)
            this.slotsError = ''
            this.queuePreview()
            this.$nextTick(() => document.getElementById(`{{ $idPrefix }}_start_${slot.key}`)?.focus())
        },
        needsRemovalConfirm(slot) { return slot.persisted || slot.start_time || slot.end_time },
        confirmRemoval() {
            if (!window.LFConfirm) return Promise.resolve(false)
            return window.LFConfirm.open({
                title: @js(__('lf.LF_course_cohort_schedule_remove_slot_title')),
                message: @js(__('lf.LF_course_cohort_schedule_remove_slot_confirm')),
                confirmLabel: @js(__('lf.LF_common_button_remove')),
                tone: 'danger'
            })
        },
        async removeSlot(slot) {
            if (this.needsRemovalConfirm(slot) && !await this.confirmRemoval()) return
            this.slots.splice(this.slots.indexOf(slot), 1)
            this.queuePreview()
        },
        // Ticking a day opens its first time slot; unticking drops every slot of that day.
        async toggleDay(weekday, field) {
            if (field.checked) { this.addSlot(weekday); return }
            const dropped = this.slotsFor(weekday)
            if (dropped.some(slot => this.needsRemovalConfirm(slot)) && !await this.confirmRemoval()) { field.checked = true; return }
            this.slots = this.slots.filter(slot => !dropped.includes(slot))
            this.queuePreview()
        },
        // Same rule as the request validation; the backend still has the last word.
        slotConflict(slot) {
            if (!slot.start_time || !slot.end_time) return false
            return this.slotsFor(slot.weekday).some(other => other !== slot && other.start_time && other.end_time
                && slot.start_time < other.end_time && other.start_time < slot.end_time)
        },
        hasSlotConflict() { return this.slots.some(slot => this.slotConflict(slot)) },
        slotsMessage() { return this.hasSlotConflict() ? @js(__('lf.LF_course_cohort_schedule_validation_overlap')) : this.slotsError },
        slotDuration(slot) {
            if (!slot.start_time || !slot.end_time) return ''
            const minutes = value => { const [hour, minute] = value.split(':').map(Number); return hour * 60 + minute }
            const total = minutes(slot.end_time) - minutes(slot.start_time)
            if (total <= 0) return ''
            const hours = Math.floor(total / 60), rest = total % 60
            const text = hours && rest ? @js(__('lf.LF_course_cohort_schedule_detail_duration_hours_minutes')) : hours ? @js(__('lf.LF_course_cohort_schedule_detail_duration_hours')) : @js(__('lf.LF_course_cohort_schedule_detail_duration_minutes'));
            return text.replace(':hours', hours).replace(':minutes', rest)
        },
        dateFrom(field) { return field.parentElement.querySelector('input[type=hidden]')?.value ?? '' },
        addExclusion() {
            const field = this.$refs.exclusionPick
            const date = this.dateFrom(field)
            this.exclusionError = ''
            if (!date || this.exclusions.length >= this.maxExclusions) return
            if (this.exclusions.some(item => item.excluded_on === date)) {
                this.exclusionError = @js(__('lf.LF_course_cohort_schedule_exclusion_duplicate'));
                return
            }
            this.exclusions.push({ excluded_on: date, reason: this.exclusionDraftReason.trim() })
            this.exclusions.sort((a, b) => a.excluded_on.localeCompare(b.excluded_on))
            this.setDate(field, '')
            this.exclusionDraftReason = ''
            this.queuePreview()
        },
        exclusionWeekday(date) { return this.weekdayLabels[new Date(`${date}T00:00:00Z`).getUTCDay() || 7] },
        exclusionHitsLabel(date) {
            const hits = this.excludedHits(date)
            return (hits === 1 ? @js(trans_choice('lf.LF_course_cohort_schedule_detail_exclusion_hits', 1, ['count' => ':count'])) : @js(trans_choice('lf.LF_course_cohort_schedule_detail_exclusion_hits', 2, ['count' => ':count']))).replace(':count', hits)
        },
        removeExclusion(index) { this.exclusions.splice(index, 1); this.queuePreview() },
        // How many occurrences this date really removes: same local-date rule as the preview service.
        excludedHits(date) {
            if ((this.startsOn && date < this.startsOn) || (this.endsOn && date > this.endsOn)) return 0
            const weekday = new Date(`${date}T00:00:00Z`).getUTCDay() || 7
            return this.slots.filter(slot => String(slot.weekday) === String(weekday)).length
        },
        previewSummary() {
            if (this.previewLoading) return @js(__('lf.LF_course_cohort_schedule_preview_loading'));
            const count = @js(__('lf.LF_course_cohort_schedule_expected_count_value')).replace(':count', this.previewCount)
            const excluded = this.excludedSessionCount()
            return excluded > 0 ? `${count} · ${@js(__('lf.LF_course_cohort_schedule_preview_excluded')).replace(':count', excluded)}` : count
        },
        excludedSessionCount() { return this.exclusions.reduce((total, item) => total + this.excludedHits(item.excluded_on), 0) },
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
            if (!this.startsOn || !this.endsOn || !this.timezone || !this.slots.length || this.slots.some(slot => !slot.weekday || !slot.start_time || !slot.end_time)) {
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
            this.slotsError = ''
            @if ($editing)
                history.replaceState(null, '', @js(route('admin.course-cohorts.show', ['id' => $cohort->id, 'tab' => 'schedules'])))
            @else
                this.name = this.saved.fields.name
                this.timezone = this.saved.fields.timezone
                this.startsOn = this.saved.fields.startsOn
                this.endsOn = this.saved.fields.endsOn
                this.$refs.timezoneSelect.value = this.timezone
                this.$refs.timezoneSelect.dispatchEvent(new Event('change', { bubbles: true }))
                this.setDate(this.$refs.exclusionPick, '')
                this.exclusionDraftReason = ''; this.exclusionError = ''
                this.setDate(this.$refs.startsOnField, this.startsOn)
                this.setDate(this.$refs.endsOnField, this.endsOn)
                this.slots = this.saved.slots.map(slot => this.withKey(slot))
                this.exclusions = this.saved.exclusions.map(item => ({ ...item }))
                this.queuePreview()
            @endif
        },
        // A date picked but not added yet would otherwise be lost silently on save.
        submitSchedule(event) {
            if (this.submitting) { event.preventDefault(); return }
            if (this.dateFrom(this.$refs.exclusionPick)) {
                event.preventDefault()
                this.addExclusion()
                if (!this.exclusionError) this.$nextTick(() => event.target.requestSubmit())
                return
            }
            if (!this.slots.length) {
                event.preventDefault()
                this.slotsError = @js(__('lf.LF_course_cohort_schedule_slots_required'));
                return
            }
            if (this.hasSlotConflict()) { event.preventDefault(); return }
            this.submitting = true
        },
        init() {
            this.slots = @js($initialSlots).map(slot => this.withKey(slot))
            this.exclusions = @js($initialExclusions);
            // A server error about the slots stops being true as soon as they change.
            this.$watch('slots', () => { this.slotsError = '' })
            if (@js($retry || $autoOpen)) this.$nextTick(() => this.open())
        }
     }"
     x-ref="dialog"
     @unless ($editing) x-on:schedule-create-open.window="open()" @endunless
     x-on:close="closed()" x-on:cancel="if (submitting) $event.preventDefault()">
    <form method="POST" action="{{ $action }}" class="ui-modal__form"
          x-on:submit="submitSchedule($event)"
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
                        <div class="ui-chip-list">
                            @foreach ($weekdayLabels as $weekday => $weekdayLabel)
                                <label class="ui-chip">
                                    <input type="checkbox" x-bind:checked="slotsFor({{ $weekday }}).length > 0" x-on:change="toggleDay({{ $weekday }}, $el)">
                                    {{ $weekdayLabel }}
                                </label>
                            @endforeach
                        </div>
                        <p class="ui-field__error" role="alert" x-show="slotsMessage()" x-text="slotsMessage()" x-cloak></p>
                        @foreach ($weekdayLabels as $weekday => $weekdayLabel)
                            <div class="ui-cohort-schedule-day" x-show="slotsFor({{ $weekday }}).length > 0" x-cloak>
                                <p class="ui-cohort-schedule-day__name">{{ $weekdayLabel }}</p>
                                <div class="ui-cohort-schedule-day__rows">
                                    <template x-for="(slot, position) in slotsFor({{ $weekday }})" :key="slot.key">
                                        <div>
                                            <div class="ui-cohort-schedule-slot">
                                                <input type="hidden" x-bind:name="`slots[${slots.indexOf(slot)}][weekday]`" x-bind:value="slot.weekday">
                                                <input type="time" x-bind:id="`{{ $idPrefix }}_start_${slot.key}`" x-bind:name="`slots[${slots.indexOf(slot)}][start_time]`" x-model="slot.start_time" x-on:input="slot.errors = {}" required
                                                       x-bind:aria-invalid="slotConflict(slot).toString()" aria-label="{{ __('lf.LF_course_cohort_schedule_start_time') }}: {{ $weekdayLabel }}">
                                                <span class="ui-cohort-schedule-slot__dash" aria-hidden="true">–</span>
                                                <input type="time" x-bind:id="`{{ $idPrefix }}_end_${slot.key}`" x-bind:name="`slots[${slots.indexOf(slot)}][end_time]`" x-bind:min="slot.start_time" x-model="slot.end_time" x-on:input="slot.errors = {}" required
                                                       x-bind:aria-invalid="slotConflict(slot).toString()" aria-label="{{ __('lf.LF_course_cohort_schedule_end_time') }}: {{ $weekdayLabel }}">
                                                <span class="ui-caption ui-enroll-nowrap" x-text="slotDuration(slot)"></span>
                                                <button type="button" class="ux-row-delete ui-cohort-schedule-row-delete cursor-interaction" x-show="slotsFor({{ $weekday }}).length > 1" x-on:click="removeSlot(slot)"
                                                        aria-label="{{ __('lf.LF_common_button_remove') }}: {{ $weekdayLabel }}" data-tooltip="{{ __('lf.LF_common_button_remove') }}">
                                                    <x-backend-icon name="trash" class="ui-icon" />
                                                </button>
                                                <button type="button" class="ui-cohort-schedule-day__add cursor-interaction" x-show="position === slotsFor({{ $weekday }}).length - 1" x-on:click="addSlot({{ $weekday }})" x-bind:disabled="slots.length >= maxSlots"
                                                        aria-label="{{ __('lf.LF_course_cohort_schedule_add_slot_for_day', ['day' => $weekdayLabel]) }}">
                                                    <x-backend-icon name="plus" class="ui-icon" />{{ __('lf.LF_course_cohort_schedule_add_slot') }}
                                                </button>
                                            </div>
                                            <p class="ui-field__error" x-show="showErrors && slot.errors.weekday" x-text="slot.errors.weekday"></p>
                                            <p class="ui-field__error" x-show="showErrors && slot.errors.start_time" x-text="slot.errors.start_time"></p>
                                            <p class="ui-field__error" x-show="showErrors && slot.errors.end_time" x-text="slot.errors.end_time"></p>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        @endforeach
                        <p class="ui-caption" x-show="slots.length >= maxSlots" x-cloak>{{ __('lf.LF_course_cohort_schedule_slot_limit', ['count' => 50]) }}</p>
                    </div>

                    <div class="ui-cohort-schedule-group" role="group" aria-labelledby="{{ $idPrefix }}_exclusions_label">
                        <p id="{{ $idPrefix }}_exclusions_label" class="ui-field__label">{{ __('lf.LF_course_cohort_schedule_exclusions') }}</p>
                        <p class="ui-caption">{{ __('lf.LF_course_cohort_schedule_exclusions_help') }}</p>
                        <div class="ui-cohort-schedule-exclusion-add">
                            <div class="ui-field">
                                <p id="{{ $idPrefix }}_exclusion_date_label" class="ui-field__label">{{ __('lf.LF_course_cohort_schedule_exclusion_date') }}</p>
                                <x-ui-date-input :id="$idPrefix.'_exclusion_pick'" name="" :min="$cohort->start_date" :max="$cohort->end_date" :aria-labelledby="$idPrefix.'_exclusion_date_label'" x-ref="exclusionPick" />
                            </div>
                            <div class="ui-field">
                                <label class="ui-field__label" for="{{ $idPrefix }}_exclusion_reason">{{ __('lf.LF_course_cohort_schedule_reason') }}</label>
                                <input id="{{ $idPrefix }}_exclusion_reason" maxlength="500" autocomplete="off" x-model="exclusionDraftReason" x-on:keydown.enter.prevent="addExclusion()" placeholder="{{ __('lf.LF_course_cohort_schedule_reason_placeholder') }}">
                            </div>
                            <button type="button" class="ui-button ui-button--outline cursor-interaction" x-on:click="addExclusion()" x-bind:disabled="exclusions.length >= maxExclusions">
                                <x-backend-icon name="plus" class="ui-icon" />{{ __('lf.LF_course_cohort_schedule_exclusion_add') }}
                            </button>
                        </div>
                        <p class="ui-field__error" role="alert" x-show="exclusionError" x-text="exclusionError" x-cloak></p>
                        <ul class="ui-cohort-schedule-exclusions" x-show="exclusions.length" x-cloak>
                            <template x-for="(exclusion, index) in exclusions" :key="exclusion.excluded_on">
                                <li class="ui-cohort-schedule-exclusion">
                                    <span class="ui-cohort-schedule-exclusion__date">
                                        <span x-text="displayDate(exclusion.excluded_on)"></span>
                                        <span class="ui-caption" x-text="exclusionWeekday(exclusion.excluded_on)"></span>
                                    </span>
                                    <span class="ui-status" x-bind:class="excludedHits(exclusion.excluded_on) > 0 ? 'ui-status--inactive' : 'ui-status--archived'"
                                          x-text="excludedHits(exclusion.excluded_on) > 0 ? exclusionHitsLabel(exclusion.excluded_on) : @js(__('lf.LF_course_cohort_schedule_exclusion_no_session'))"></span>
                                    <input type="hidden" x-bind:name="`exclusions[${index}][excluded_on]`" x-bind:value="exclusion.excluded_on">
                                    <input maxlength="500" x-bind:name="`exclusions[${index}][reason]`" x-model="exclusion.reason" x-bind:aria-label="`{{ __('lf.LF_course_cohort_schedule_reason') }} ${displayDate(exclusion.excluded_on)}`" placeholder="{{ __('lf.LF_course_cohort_schedule_reason_placeholder') }}">
                                    <button type="button" class="ux-row-delete ui-cohort-schedule-row-delete cursor-interaction" x-on:click="removeExclusion(index)"
                                            x-bind:aria-label="`${@js(__('lf.LF_common_button_remove'))} ${displayDate(exclusion.excluded_on)}`" data-tooltip="{{ __('lf.LF_common_button_remove') }}">
                                        <x-backend-icon name="trash" class="ui-icon" />
                                    </button>
                                </li>
                            </template>
                        </ul>
                        <p class="ui-caption" x-show="exclusions.length >= maxExclusions" x-cloak>{{ __('lf.LF_course_cohort_schedule_exclusion_limit', ['count' => 366]) }}</p>
                    </div>
                </div>

                <aside class="ui-cohort-schedule-preview" aria-labelledby="{{ $idPrefix }}_preview_label">
                    <div class="ui-cohort-schedule-preview__head">
                        <p id="{{ $idPrefix }}_preview_label" class="ui-cohort-schedule-preview__title">{{ __('lf.LF_course_cohort_schedule_preview') }}</p>
                        <span class="ui-caption" x-text="previewSummary()"></span>
                    </div>
                    <p class="ui-caption" x-show="previewCount > 0 && !previewLoading" x-cloak>
                        <span x-text="@js(__('lf.LF_course_cohort_schedule_preview_lessons', ['lessons' => $liveLessonCount ?? 0])).replace(':days', previewCount)"></span>
                        <a class="ui-link-button" href="{{ route('admin.course-cohorts.show', [$cohort->id, 'tab' => 'sessions']) }}" target="_blank" rel="noopener">{{ __('lf.LF_course_cohort_schedule_preview_lessons_link') }}</a>
                    </p>
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
