{{-- Edit popup of one assignment: role and period change, the teacher does not (remove and assign to change the person). --}}
@php
    $editId = 'cohort-teacher-edit-'.$assignment->id;
    $editRetry = old('_form') === 'teacher_edit_'.$assignment->id;
    $editErrors = $editRetry ? $errors->getBag('teacherEdit') : new \Illuminate\Support\MessageBag;
    $savedFrom = $assignment->assigned_from ? substr((string) $assignment->assigned_from, 0, 10) : '';
    $savedTo = $assignment->assigned_to ? substr((string) $assignment->assigned_to, 0, 10) : '';
@endphp
<div x-data="{
         submitting: false,
         showErrors: @js($editRetry),
         savedRole: @js($assignment->role),
         role: @js($editRetry ? old('role', $assignment->role) : $assignment->role),
         discard() {
             const form = this.$refs.editForm
             const select = form.querySelector('select[name=role]')
             select.value = this.savedRole
             select.dispatchEvent(new Event('change', { bubbles: true }))
             this.role = this.savedRole
             // The kit date field shows dd/mm/yyyy and keeps the submitted yyyy-mm-dd in its hidden sibling.
             for (const field of form.querySelectorAll('[data-original-date]')) {
                 const iso = field.dataset.originalDate
                 field.value = iso ? iso.split('-').reverse().join('/') : ''
                 field.dataset.iso = iso
                 const hidden = field.closest('.ui-field').querySelector('input[type=hidden]')
                 if (hidden) hidden.value = iso
             }
             this.showErrors = false
         }
     }"
     x-init="if (@js($editRetry)) $refs.editDialog.showModal()">
    <dialog id="{{ $editId }}" x-ref="editDialog" class="ui-modal ui-cohort-teacher-modal" data-columns="2" aria-labelledby="{{ $editId }}-title"
            x-on:close="discard()" x-on:cancel="if (submitting) $event.preventDefault()">
        <form method="POST" action="{{ route('admin.course-cohorts.teachers.update', [$cohort->id, $assignment->id]) }}"
              class="ui-modal__form" x-ref="editForm"
              x-on:submit="if (submitting) { $event.preventDefault(); return } submitting = true">
            @csrf
            @method('PUT')
            <input type="hidden" name="_form" value="teacher_edit_{{ $assignment->id }}">
            <header class="ui-modal__header">
                <div><h3 id="{{ $editId }}-title">{{ __('lf.LF_course_cohort_teacher_edit') }}</h3></div>
                <button type="button" class="ui-modal__close cursor-interaction" x-on:click="$refs.editDialog.close()" :disabled="submitting" aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
            </header>
            <div class="ui-modal__body">
                <div class="ui-modal__grid">
                    <div class="ui-field">
                        <span class="ui-field__label">{{ __('lf.LF_course_cohort_teacher_teacher') }}</span>
                        <div class="ui-enroll-readonly">{{ $assignment->teacher_name }} · {{ $assignment->teacher_email }}</div>
                    </div>
                    <div class="ui-field">
                        <label class="ui-field__label" for="{{ $editId }}-role"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_course_cohort_teacher_role') }}</label>
                        <select id="{{ $editId }}-role" name="role" data-ui-kit-select x-model="role" required>
                            @foreach (['primary_teacher', 'teacher', 'assistant'] as $role)
                                <option value="{{ $role }}" @selected(($editRetry ? old('role', $assignment->role) : $assignment->role) === $role) @disabled($role === 'primary_teacher' && ! $hasOperatingPeriod)>{{ __('lf.LF_course_cohort_teacher_role_'.$role) }}</option>
                            @endforeach
                        </select>
                        @if ($editErrors->has('role'))<p class="ui-field__error" role="alert" x-show="showErrors">{{ $editErrors->first('role') }}</p>@endif
                    </div>
                    <div class="ui-field" x-show="role !== 'primary_teacher'">
                        <label class="ui-field__label" for="{{ $editId }}-from">{{ __('lf.LF_course_cohort_teacher_from') }}</label>
                        <x-ui-date-input id="{{ $editId }}-from" name="assigned_from" :value="$editRetry ? old('assigned_from') : $savedFrom" :min="$cohort->start_date" :max="$cohort->end_date" data-original-date="{{ $savedFrom }}" />
                        @if ($editErrors->has('assigned_from'))<p class="ui-field__error" role="alert" x-show="showErrors">{{ $editErrors->first('assigned_from') }}</p>@endif
                    </div>
                    <div class="ui-field" x-show="role !== 'primary_teacher'">
                        <label class="ui-field__label" for="{{ $editId }}-to">{{ __('lf.LF_course_cohort_teacher_to') }}</label>
                        <x-ui-date-input id="{{ $editId }}-to" name="assigned_to" :value="$editRetry ? old('assigned_to') : $savedTo" :min="$cohort->start_date" :max="$cohort->end_date" data-original-date="{{ $savedTo }}" />
                        @if ($editErrors->has('assigned_to'))<p class="ui-field__error" role="alert" x-show="showErrors">{{ $editErrors->first('assigned_to') }}</p>@endif
                    </div>
                    <p class="ui-caption ui-field--full" x-show="role !== 'primary_teacher'">{{ __('lf.LF_course_cohort_teacher_operating_period', $classPeriod) }}</p>
                    <p class="ui-caption ui-field--full" x-show="role === 'primary_teacher'" x-cloak>{{ __('lf.LF_course_cohort_teacher_primary_period_summary', $classPeriod) }}</p>
                    @if ($currentPrimary && (int) $currentPrimary->id !== (int) $assignment->id)
                        <p class="ui-field__hint ui-field__hint--notice ui-field--full" role="status" x-show="role === 'primary_teacher'" x-cloak>{{ __('lf.LF_course_cohort_teacher_primary_replace_notice', ['name' => $currentPrimary->teacher_name]) }}</p>
                    @endif
                    @if ($editErrors->has('assigned_period'))
                        <div class="ui-field--full" role="alert" x-show="showErrors">
                            <p class="ui-field__error">{{ $editErrors->first('assigned_period') }}</p>
                            <ul class="ui-cohort-teacher-blocked">
                                @foreach ($editErrors->get('blocked_sessions') as $blockedSession)
                                    <li>{{ $blockedSession }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
            </div>
            <footer class="ui-modal__footer">
                <span></span>
                <button type="button" class="ui-button cursor-interaction" x-on:click="$refs.editDialog.close()" :disabled="submitting">{{ __('lf.LF_common_button_cancel') }}</button>
                <button class="ui-button ui-button--primary cursor-interaction" type="submit" :disabled="submitting" :aria-busy="submitting">
                    <x-backend-icon name="check" class="ui-icon" />
                    {{ __('lf.LF_common_button_save') }}
                </button>
            </footer>
        </form>
    </dialog>
</div>
