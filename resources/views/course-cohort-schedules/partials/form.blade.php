@php
    $initialSlots = old('slots', $slots->map(fn ($slot) => [
        'weekday' => (string) $slot->weekday,
        'start_time' => substr($slot->start_time, 0, 5),
        'end_time' => substr($slot->end_time, 0, 5),
        'persisted' => true,
    ])->all());
    if ($initialSlots === []) $initialSlots = [['weekday' => '1', 'start_time' => '', 'end_time' => '']];
    $initialSlots = collect($initialSlots)->values()->map(fn ($slot, $index) => array_merge($slot, [
        'persisted' => (bool) ($slot['persisted'] ?? ($editing && $slots->has($index))),
    ]))->all();
    $initialSlotErrors = collect($initialSlots)->mapWithKeys(fn ($slot, $index) => [$index => [
        'weekday' => $errors->first("slots.$index.weekday"),
        'start_time' => $errors->first("slots.$index.start_time"),
        'end_time' => $errors->first("slots.$index.end_time"),
    ]])->all();
    $initialExclusions = old('exclusions', $exclusions->map(fn ($item) => [
        'excluded_on' => $item->excluded_on,
        'reason' => $item->reason,
    ])->all());
    $scheduleName = old('name', $schedule->name ?? '');
    $scheduleStartsOn = old('starts_on', $schedule->starts_on ?? $cohort->start_date ?? '');
    $scheduleEndsOn = old('ends_on', $schedule->ends_on ?? $cohort->end_date ?? '');
    $scheduleTimezone = old('timezone', $schedule->timezone ?? config('app.timezone'));
@endphp

<div class="course-cohort-schedule-form"
     x-data="{
        slots: @js(array_values($initialSlots)), exclusions: @js(array_values($initialExclusions)),
        name: @js($scheduleName), startsOn: @js($scheduleStartsOn), endsOn: @js($scheduleEndsOn), timezone: @js($scheduleTimezone),
        preview: [], previewCount: 0, previewLoading: false, previewError: '', previewTimer: null,
        maxSlots: 50, maxExclusions: 366, slotErrors: @js($initialSlotErrors),
        addSlot() {
            if (this.slots.length >= this.maxSlots) return
            this.slots.push({ weekday: '', start_time: '', end_time: '', persisted: false })
            const index = this.slots.length - 1
            this.slotErrors[index] = { weekday: '', start_time: '', end_time: '' }
            this.$nextTick(() => {
                const field = document.getElementById(`slot_weekday_${index}`)
                field?.focus()
                field?.closest('.course-cohort-schedule-form__slot-row')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
            })
        },
        async removeSlot(index) {
            if (this.slots.length <= 1) return
            const slot = this.slots[index]
            if (slot.persisted || slot.weekday || slot.start_time || slot.end_time) {
                const confirmed = window.LFConfirm
                    ? await window.LFConfirm.open({
                        title: @js(__('lf.LF_course_cohort_schedule_remove_slot_title')),
                        message: @js(__('lf.LF_course_cohort_schedule_remove_slot_confirm')),
                        confirmLabel: @js(__('lf.LF_common_button_remove')),
                        tone: 'danger'
                    })
                    : window.confirm(@js(__('lf.LF_course_cohort_schedule_remove_slot_confirm')))
                if (!confirmed) return
            }
            this.slots.splice(index, 1)
            const nextErrors = {}
            Object.entries(this.slotErrors).forEach(([key, value]) => {
                const current = Number(key)
                if (current < index) nextErrors[current] = value
                if (current > index) nextErrors[current - 1] = value
            })
            this.slotErrors = nextErrors
            this.queuePreview()
        },
        addExclusion() {
            if (this.exclusions.length >= this.maxExclusions) return
            this.exclusions.push({ excluded_on: '', reason: '' })
            const index = this.exclusions.length - 1
            this.$nextTick(() => {
                const field = document.getElementById(`excluded_on_${index}`)
                field?.focus()
                field?.closest('.course-cohort-schedule-form__exclusion-row')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
            })
        },
        removeExclusion(index) { this.exclusions.splice(index, 1); this.queuePreview() },
        queuePreview() { clearTimeout(this.previewTimer); this.previewTimer = setTimeout(() => this.loadPreview(), 250) },
        async loadPreview() {
            if (!this.name || !this.startsOn || !this.endsOn || !this.timezone || this.slots.some(slot => !slot.weekday || !slot.start_time || !slot.end_time)) {
                this.preview = []; this.previewCount = 0; return
            }
            this.previewLoading = true; this.previewError = ''
            try {
                const response = await fetch(@js(route('admin.course-cohorts.schedules.preview', $cohort->id)), {
                    method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': @js(csrf_token()) },
                    body: JSON.stringify({ name: this.name, starts_on: this.startsOn, ends_on: this.endsOn, timezone: this.timezone, slots: this.slots, exclusions: this.exclusions.filter(item => item.excluded_on) })
                })
                const payload = await response.json()
                if (!response.ok) { this.preview = []; this.previewCount = 0; this.previewError = Object.values(payload.errors || {}).flat()[0] || @js(__('lf.LF_course_cohort_schedule_preview_invalid')); return }
                this.preview = payload.data; this.previewCount = payload.count
            } catch (error) { this.previewError = @js(__('lf.LF_course_cohort_schedule_preview_failed')) }
            finally { this.previewLoading = false }
        }
     }" x-init="$nextTick(() => loadPreview())" x-on:input.debounce.250ms="queuePreview()" x-on:change="queuePreview()">
    @if(empty($inline))
    <section class="admin-form-standard-section">
        <div class="cohort-student-class-summary course-cohort-schedule-form__cohort-summary">
            <div><span class="cohort-student-class-eyebrow">{{ __('lf.LF_course_cohort_student_section_class') }}</span><strong>{{ $cohort->name }}</strong></div>
            <span>{{ $cohort->code }} · {{ $cohort->product_title ?? '—' }}</span>
            <span><strong>{{ __('lf.LF_course_cohort_create_group_dates') }}:</strong> {{ $cohort->start_date ? \Illuminate\Support\Carbon::parse($cohort->start_date)->format('d/m/Y') : '—' }} → {{ $cohort->end_date ? \Illuminate\Support\Carbon::parse($cohort->end_date)->format('d/m/Y') : '—' }}</span>
        </div>
    </section>
    @endif

    <section class="admin-form-standard-section course-cohort-schedule-form__section" aria-labelledby="schedule-general-title">
        <header class="admin-form-section-header"><div><h2 id="schedule-general-title" class="admin-form-section-title">{{ __('lf.LF_course_cohort_schedule_general') }}</h2>@if(empty($inline))<p class="admin-form-section-help">{{ __('lf.LF_course_cohort_schedule_general_help') }}</p>@endif</div></header>
        <div class="admin-form-fields course-cohort-schedule-form__general-grid">
            <div class="lf-form-group course-cohort-schedule-form__name"><x-form-label for="schedule_name" :value="__('lf.LF_course_cohort_schedule_name')" :required="true" /><input id="schedule_name" name="name" class="lf-form-control" maxlength="255" required x-model="name" placeholder="{{ __('lf.LF_course_cohort_schedule_name_placeholder') }}"></div>
            <div class="lf-form-group course-cohort-schedule-form__timezone"><x-form-label for="schedule_timezone" :value="__('lf.LF_course_cohort_schedule_timezone')" :required="true" /><select id="schedule_timezone" name="timezone" class="lf-form-control" required x-model="timezone">@foreach($timezones as $item)<option value="{{ $item }}">{{ $item }}</option>@endforeach</select></div>
            <div class="lf-form-group"><x-form-label for="schedule_starts_on" :value="__('lf.LF_course_cohort_schedule_starts_on')" :required="true" /><input id="schedule_starts_on" type="date" name="starts_on" class="lf-form-control" required x-model="startsOn" x-bind:class="{ 'is-lf-placeholder': !startsOn }" min="{{ $cohort->start_date }}" max="{{ $cohort->end_date }}" lang="{{ str_replace('_', '-', app()->getLocale()) }}"></div>
            <div class="lf-form-group"><x-form-label for="schedule_ends_on" :value="__('lf.LF_course_cohort_schedule_ends_on')" :required="true" /><input id="schedule_ends_on" type="date" name="ends_on" class="lf-form-control" required x-model="endsOn" x-bind:class="{ 'is-lf-placeholder': !endsOn }" x-bind:min="startsOn || @js($cohort->start_date)" max="{{ $cohort->end_date }}" lang="{{ str_replace('_', '-', app()->getLocale()) }}"></div>
        </div>
    </section>

    <section class="admin-form-standard-section course-cohort-schedule-form__section" aria-labelledby="schedule-slots-title">
        <header class="admin-form-section-header"><div><h2 id="schedule-slots-title" class="admin-form-section-title">{{ __('lf.LF_course_cohort_schedule_slots') }}</h2><p class="admin-form-section-help">{{ __('lf.LF_course_cohort_schedule_slots_help') }}</p></div></header>
        <div class="course-cohort-schedule-form__repeaters">
            <template x-for="(slot, index) in slots" :key="`slot-${index}`">
                <div class="course-cohort-schedule-form__slot-row">
                    <span class="course-cohort-schedule-form__row-number" x-text="index + 1" aria-hidden="true"></span>
                    <div class="lf-form-group"><label class="lf-form-label" x-bind:for="`slot_weekday_${index}`">{{ __('lf.LF_course_cohort_schedule_weekday') }} <span aria-hidden="true">*</span></label><select class="lf-form-control" x-bind:id="`slot_weekday_${index}`" x-bind:name="`slots[${index}][weekday]`" x-model="slot.weekday" required><option value="">{{ __('lf.LF_course_cohort_schedule_select_weekday') }}</option>@foreach(range(1,7) as $weekday)<option value="{{ $weekday }}">{{ __('lf.LF_course_cohort_schedule_weekday_'.$weekday) }}</option>@endforeach</select><p class="lf-form-error" x-show="slotErrors[index]?.weekday" x-text="slotErrors[index]?.weekday"></p></div>
                    <div class="lf-form-group"><label class="lf-form-label" x-bind:for="`slot_start_${index}`">{{ __('lf.LF_course_cohort_schedule_start_time') }} <span aria-hidden="true">*</span></label><input type="time" class="lf-form-control" x-bind:id="`slot_start_${index}`" x-bind:name="`slots[${index}][start_time]`" x-model="slot.start_time" required><p class="lf-form-error" x-show="slotErrors[index]?.start_time" x-text="slotErrors[index]?.start_time"></p></div>
                    <div class="lf-form-group"><label class="lf-form-label" x-bind:for="`slot_end_${index}`">{{ __('lf.LF_course_cohort_schedule_end_time') }} <span aria-hidden="true">*</span></label><input type="time" class="lf-form-control" x-bind:id="`slot_end_${index}`" x-bind:name="`slots[${index}][end_time]`" x-bind:min="slot.start_time" x-model="slot.end_time" required><p class="lf-form-error" x-show="slotErrors[index]?.end_time" x-text="slotErrors[index]?.end_time"></p></div>
                    <button type="button" class="course-cohort-schedule-form__row-action course-cohort-schedule-form__row-action--remove" x-show="slots.length > 1" x-cloak x-on:click="removeSlot(index)" x-bind:aria-label="`${@js(__('lf.LF_common_button_remove'))} ${index + 1}`" title="{{ __('lf.LF_common_button_remove') }}"><x-backend-icon name="trash" /></button>
                </div>
            </template>
            <div class="course-cohort-schedule-form__slot-footer">
                <button type="button" class="course-cohort-schedule-form__append" x-on:click="addSlot()" x-bind:disabled="slots.length >= maxSlots"><x-backend-icon name="plus" />{{ __('lf.LF_course_cohort_schedule_add_slot') }}</button>
                <p class="admin-form-section-help" x-show="slots.length >= maxSlots" x-cloak>{{ __('lf.LF_course_cohort_schedule_slot_limit', ['count' => 50]) }}</p>
            </div>
        </div>
    </section>

    <section class="admin-form-standard-section course-cohort-schedule-form__section" aria-labelledby="schedule-exclusions-title">
        <header class="admin-form-section-header"><div><h2 id="schedule-exclusions-title" class="admin-form-section-title">{{ __('lf.LF_course_cohort_schedule_exclusions') }}</h2><p class="admin-form-section-help">{{ __('lf.LF_course_cohort_schedule_exclusions_help') }}</p></div></header>
        <div class="course-cohort-schedule-form__repeaters">
            <template x-for="(exclusion, index) in exclusions" :key="`exclusion-${index}`">
                <div class="course-cohort-schedule-form__exclusion-row">
                    <span class="course-cohort-schedule-form__row-number" x-text="index + 1" aria-hidden="true"></span>
                    <div class="lf-form-group"><label class="lf-form-label" x-bind:for="`excluded_on_${index}`">{{ __('lf.LF_course_cohort_schedule_excluded_on') }} <span aria-hidden="true">*</span></label><input type="date" class="lf-form-control" x-bind:id="`excluded_on_${index}`" x-bind:name="`exclusions[${index}][excluded_on]`" x-model="exclusion.excluded_on" x-bind:min="startsOn" x-bind:max="endsOn" required></div>
                    <div class="lf-form-group"><label class="lf-form-label" x-bind:for="`exclusion_reason_${index}`">{{ __('lf.LF_course_cohort_schedule_reason') }}</label><input class="lf-form-control" maxlength="500" x-bind:id="`exclusion_reason_${index}`" x-bind:name="`exclusions[${index}][reason]`" x-model="exclusion.reason" placeholder="{{ __('lf.LF_course_cohort_schedule_reason_placeholder') }}"></div>
                    <button type="button" class="course-cohort-schedule-form__row-action course-cohort-schedule-form__row-action--remove" x-on:click="removeExclusion(index)" x-bind:aria-label="`${@js(__('lf.LF_common_button_remove'))} ${index + 1}`" title="{{ __('lf.LF_common_button_remove') }}"><x-backend-icon name="trash" /></button>
                </div>
            </template>
            <p class="course-cohort-schedule-form__empty-repeater" x-show="exclusions.length === 0">{{ __('lf.LF_course_cohort_schedule_no_exclusions') }}</p>
            <div class="course-cohort-schedule-form__slot-footer">
                <button type="button" class="course-cohort-schedule-form__append course-cohort-schedule-form__append--exclusion" x-on:click="addExclusion()" x-bind:disabled="exclusions.length >= maxExclusions"><x-backend-icon name="plus" />{{ __('lf.LF_course_cohort_schedule_add_exclusion') }}</button>
                <p class="admin-form-section-help" x-show="exclusions.length >= maxExclusions" x-cloak>{{ __('lf.LF_course_cohort_schedule_exclusion_limit', ['count' => 366]) }}</p>
            </div>
        </div>
    </section>

    <section class="admin-form-standard-section course-cohort-schedule-form__section course-cohort-schedule-form__preview" aria-labelledby="schedule-preview-title">
        <header class="admin-form-section-header"><div><h2 id="schedule-preview-title" class="admin-form-section-title">{{ __('lf.LF_course_cohort_schedule_preview') }}</h2><p class="admin-form-section-help">{{ __('lf.LF_course_cohort_schedule_preview_notice') }}</p></div><div class="course-cohort-schedule-preview__summary"><span class="course-cohort-schedule-preview__timezone"><span>{{ __('lf.LF_course_cohort_schedule_timezone') }}:</span> <strong x-text="timezone"></strong></span><span class="course-cohort-schedule-preview__count" x-text="previewLoading ? @js(__('lf.LF_course_cohort_schedule_preview_loading')) : @js(__('lf.LF_course_cohort_schedule_expected_count_value')).replace(':count', previewCount)"></span></div></header>
        <p class="lf-form-error" role="alert" x-show="previewError" x-text="previewError" x-cloak></p>
        <div class="course-cohort-schedule-preview__list" x-show="preview.length" x-cloak>
            <template x-for="item in preview" :key="`${item.starts_at}-${item.ends_at}`"><div class="course-cohort-schedule-preview__item"><strong x-text="new Intl.DateTimeFormat(@js(app()->getLocale()), { weekday: 'long', day: '2-digit', month: '2-digit', year: 'numeric', timeZone: item.timezone }).format(new Date(item.starts_at))"></strong><span x-text="`${item.start_time}–${item.end_time}`"></span></div></template>
        </div>
        <p class="course-cohort-schedule-preview__empty" x-show="!previewLoading && !preview.length && !previewError">{{ __('lf.LF_course_cohort_schedule_preview_empty') }}</p>
    </section>

    <footer class="admin-form-footer" data-actions-align="end">
        <div class="admin-form-footer-primary">
            @if(! empty($inline))
                <button class="btn btn-secondary" type="button" x-on:click="$dispatch('schedule-editor-close')">{{ __('lf.LF_common_button_cancel') }}</button>
            @else
                <a class="btn btn-secondary" href="{{ route('admin.course-cohorts.show', ['id' => $cohort->id, 'tab' => 'schedules']) }}">{{ __('lf.LF_common_button_cancel') }}</a>
            @endif
            <button class="btn btn-primary" type="submit">{{ $editing ? __('lf.LF_common_button_save_changes') : __('lf.LF_course_cohort_schedule_save') }}</button>
        </div>
    </footer>
</div>
