{{-- Edit popup for the class's own fields; product, version and registration window stay read-only on the overview. --}}
@php
    $editRetry = old('_form') === 'cohort_edit';
    $editValue = fn (string $field, mixed $saved): mixed => $editRetry ? old($field) : $saved;
@endphp
<div class="cohort-lifecycle-action"
     x-data="{
         submitting: false, showErrors: @js($editRetry),
         discard() {
             for (const field of this.$refs.editForm.querySelectorAll('[data-original]')) field.value = field.dataset.original
             // The kit date field shows dd/mm/yyyy and keeps the submitted yyyy-mm-dd in its hidden sibling.
             for (const field of this.$refs.editForm.querySelectorAll('[data-original-date]')) {
                 const iso = field.dataset.originalDate
                 field.value = iso ? iso.split('-').reverse().join('/') : ''
                 field.dataset.iso = iso
                 const hidden = field.closest('.ui-field').querySelector('input[type=hidden]')
                 if (hidden) hidden.value = iso
             }
             this.showErrors = false
         },
         dismiss() {
             this.$refs.editDialog.close()
             this.discard()
         }
     }"
     x-init="if (@js($editRetry || request()->boolean('edit'))) $refs.editDialog.showModal()">
    <button type="button" class="ui-button ui-button--outline cursor-interaction" aria-haspopup="dialog" x-on:click="$refs.editDialog.showModal()">
        <x-backend-icon name="square-pen" class="ui-icon" />
        {{ __('lf.LF_course_cohort_action_edit_overview') }}
    </button>
    <dialog x-ref="editDialog" class="ui-modal" data-columns="2" aria-labelledby="cohort-edit-title"
            x-on:close="discard()" x-on:cancel="if (submitting) $event.preventDefault()">
        <form method="POST" action="{{ route($routePrefix.'.update', $cohort->id) }}" class="ui-modal__form" x-ref="editForm"
              x-on:submit="if (submitting) { $event.preventDefault(); return } submitting = true">
            @csrf
            @method('PUT')
            <input type="hidden" name="_form" value="cohort_edit">
            <header class="ui-modal__header">
                <div><h3 id="cohort-edit-title">{{ __('lf.LF_course_cohort_common_edit') }}</h3></div>
                <button type="button" class="ui-modal__close cursor-interaction" x-on:click="dismiss()" :disabled="submitting" aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
            </header>
            <div class="ui-modal__body">
                <div class="ui-modal__grid">
                    @if ($cohort->version_id)
                        <div class="ui-field ui-field--full">
                            <span class="ui-field__label">{{ __('lf.LF_course_cohort_create_content_version') }}</span>
                            <div class="ui-enroll-readonly ui-cohort-version">
                                <span>{{ str_replace(':code', $cohort->version_code, __('lf.LF_course_cohort_create_version_prefix')) }}</span>
                                @if ($cohort->version_template_id)
                                    <a href="{{ route('admin.course-templates.versions.show', [$cohort->version_template_id, $cohort->version_id]) }}" class="ui-enroll-link ui-cohort-version-link" target="_blank" rel="noopener" aria-label="{{ __('lf.LF_product_v2_view_version_new_tab') }}">
                                        <x-backend-icon name="eye" class="ui-icon" />{{ __('lf.LF_course_product_item_common_view_version') }}
                                    </a>
                                @endif
                            </div>
                        </div>
                    @endif
                    <div class="ui-field">
                        <label class="ui-field__label" for="cohort-edit-name"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_course_cohort_common_name') }}</label>
                        <input id="cohort-edit-name" type="text" name="name" autocomplete="off" value="{{ $editValue('name', $cohort->name) }}" data-original="{{ $cohort->name }}" maxlength="255" required>
                        @if ($editRetry) @error('name')<p class="ui-field__error" x-show="showErrors">{{ $message }}</p>@enderror @endif
                    </div>
                    <div class="ui-field">
                        <label class="ui-field__label" for="cohort-edit-capacity">{{ __('lf.LF_course_cohort_common_capacity') }}</label>
                        <input id="cohort-edit-capacity" type="number" name="capacity" value="{{ $editValue('capacity', $cohort->capacity) }}" data-original="{{ $cohort->capacity }}" min="{{ max(1, $activeMembershipCount) }}" step="1" inputmode="numeric" placeholder="{{ __('lf.LF_course_cohort_create_capacity_placeholder') }}">
                        <p class="ui-caption">{{ $activeMembershipCount > 0 ? __('lf.LF_course_cohort_edit_capacity_min', ['count' => $activeMembershipCount]) : __('lf.LF_course_cohort_create_capacity_help') }}</p>
                        @if ($editRetry) @error('capacity')<p class="ui-field__error" x-show="showErrors">{{ $message }}</p>@enderror @endif
                    </div>
                    <div class="ui-field">
                        <label class="ui-field__label" for="cohort-edit-start_date">{{ __('lf.LF_course_cohort_common_start_date') }}</label>
                        <x-ui-date-input id="cohort-edit-start_date" name="start_date" :value="$editValue('start_date', $cohort->start_date)" data-original-date="{{ $cohort->start_date }}" />
                        @if ($editRetry) @error('start_date')<p class="ui-field__error" x-show="showErrors">{{ $message }}</p>@enderror @endif
                    </div>
                    <div class="ui-field">
                        <label class="ui-field__label" for="cohort-edit-end_date">{{ __('lf.LF_course_cohort_common_end_date') }}</label>
                        <x-ui-date-input id="cohort-edit-end_date" name="end_date" :value="$editValue('end_date', $cohort->end_date)" data-original-date="{{ $cohort->end_date }}" />
                        @if ($editRetry) @error('end_date')<p class="ui-field__error" x-show="showErrors">{{ $message }}</p>@enderror @endif
                    </div>
                    <div class="ui-field ui-field--full">
                        <label class="ui-field__label" for="cohort-edit-notes">{{ __('lf.LF_course_cohort_common_notes') }}</label>
                        <textarea id="cohort-edit-notes" name="notes" rows="3" data-original="{{ $cohort->notes }}" placeholder="{{ __('lf.LF_course_cohort_create_notes_placeholder') }}">{{ $editValue('notes', $cohort->notes) }}</textarea>
                        <p class="ui-caption">{{ __('lf.LF_course_cohort_create_notes_help') }}</p>
                        @if ($editRetry) @error('notes')<p class="ui-field__error" x-show="showErrors">{{ $message }}</p>@enderror @endif
                    </div>
                </div>
            </div>
            <footer class="ui-modal__footer">
                <span></span>
                <button type="button" class="ui-button cursor-interaction" x-on:click="dismiss()" :disabled="submitting">{{ __('lf.LF_common_button_cancel') }}</button>
                <button type="submit" class="ui-button ui-button--primary cursor-interaction" :disabled="submitting" :aria-busy="submitting">
                    <x-backend-icon name="check" class="ui-icon" x-show="!submitting" />
                    <span x-show="!submitting">{{ __('lf.LF_common_button_save_changes') }}</span>
                    <span x-cloak x-show="submitting">{{ __('lf.LF_course_cohort_edit_saving') }}</span>
                </button>
            </footer>
        </form>
    </dialog>
</div>
