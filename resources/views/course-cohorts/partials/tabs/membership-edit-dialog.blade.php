{{-- Edit popup of one learner in the class (ADR-0034): the class it sits in, the join date, the reason and the note. The
     learner and the Enrollment do not change here. A changed join date asks first, since it decides which sessions count. --}}
@php
    $memberId = 'cohort-membership-edit-'.$student->membership_id;
    $memberRetry = old('_form') === 'membership_edit_'.$student->membership_id;
    $memberErrors = $memberRetry ? $errors->getBag('membershipEdit') : new \Illuminate\Support\MessageBag;
    $memberOpen = $memberRetry || (int) request()->query('member') === (int) $student->membership_id;
    $savedJoinedAt = $student->joined_at ? \Illuminate\Support\Carbon::parse($student->joined_at)->format('Y-m-d\TH:i') : '';
    $memberValue = fn (string $field, mixed $saved): mixed => $memberRetry ? old($field, $saved) : $saved;
    $memberGeneral = collect(['enrollment_id', 'status'])->flatMap(fn (string $key): array => $memberErrors->get($key));
@endphp
<div x-data="{
         submitting: false,
         showErrors: @js($memberRetry),
         saved: @js(['cohort_id' => (string) $cohort->id, 'joined_at' => $savedJoinedAt, 'transfer_reason' => (string) ($student->transfer_reason ?? ''), 'note' => (string) ($student->note ?? '')]),
         serverNow: @js(now()->format('Y-m-d\TH:i')),
         loadedAt: Date.now(),
         // A transfer joins the new class now: its earlier sessions take no attendance (ADR-0034). The date can still
         // be changed after choosing the class; going back to the current class restores the saved date.
         cohortChanged(value) {
             const joined = this.$refs.memberForm.elements.joined_at
             if (value === this.saved.cohort_id) { joined.value = this.saved.joined_at; return }
             const now = new Date(Date.parse(this.serverNow + ':00Z') + (Date.now() - this.loadedAt))
             joined.value = now.toISOString().slice(0, 16)
         },
         async save() {
             if (this.submitting) return
             const form = this.$refs.memberForm
             if (form.elements.joined_at.value !== this.saved.joined_at) {
                 const confirmed = await window.LFConfirm.open({ message: @js(__('lf.LF_course_cohort_student_joined_at_confirm')), trigger: form.querySelector('[type=submit]') })
                 if (!confirmed) return
             }
             this.submitting = true
             form.submit()
         },
         discard() {
             const form = this.$refs.memberForm
             const select = form.elements.cohort_id
             select.value = this.saved.cohort_id
             select.dispatchEvent(new Event('change', { bubbles: true }))
             form.elements.joined_at.value = this.saved.joined_at
             form.elements.transfer_reason.value = this.saved.transfer_reason
             form.elements.note.value = this.saved.note
             this.showErrors = false
         }
     }"
     x-init="if (@js($memberOpen)) $refs.memberDialog.showModal()">
    <dialog id="{{ $memberId }}" x-ref="memberDialog" class="ui-modal ui-cohort-select-modal" data-columns="2" aria-labelledby="{{ $memberId }}-title"
            data-membership-dialog="{{ $student->membership_id }}"
            x-on:close="discard()" x-on:cancel="if (submitting) $event.preventDefault()">
        <form method="POST" action="{{ route('admin.course-cohort-students.update', $student->membership_id) }}"
              class="ui-modal__form" x-ref="memberForm" novalidate x-on:submit.prevent="save()">
            @csrf
            @method('PUT')
            <input type="hidden" name="_form" value="membership_edit_{{ $student->membership_id }}">
            <input type="hidden" name="status" value="active">
            <header class="ui-modal__header">
                <div><h3 id="{{ $memberId }}-title">{{ __('lf.LF_course_cohort_student_membership_edit_title') }}</h3></div>
                <button type="button" class="ui-modal__close cursor-interaction" x-on:click="$refs.memberDialog.close()" :disabled="submitting" aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
            </header>
            <div class="ui-modal__body">
                <div class="ui-modal__grid">
                    @if ($memberGeneral->isNotEmpty())
                        <div class="ui-field--full" role="alert" x-show="showErrors">
                            @foreach ($memberGeneral as $message)
                                <p class="ui-field__error">{{ $message }}</p>
                            @endforeach
                        </div>
                    @endif
                    <div class="ui-field">
                        <span class="ui-field__label">{{ __('lf.LF_course_cohort_student_common_student') }}</span>
                        <div class="ui-enroll-readonly">{{ $student->student_name }} · {{ $student->student_email }}</div>
                    </div>
                    <div class="ui-field">
                        <span class="ui-field__label">{{ __('lf.LF_course_cohort_student_common_enrollment') }}</span>
                        <div class="ui-enroll-readonly">ENR-{{ str_pad((string) $student->enrollment_id, 6, '0', STR_PAD_LEFT) }}</div>
                    </div>
                    <div class="ui-field">
                        <label class="ui-field__label" for="{{ $memberId }}-cohort"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_course_cohort_student_membership_cohort') }}</label>
                        <select id="{{ $memberId }}-cohort" name="cohort_id" data-ui-kit-select required x-on:change="cohortChanged($event.target.value)">
                            @foreach ($membershipCohorts as $target)
                                <option value="{{ $target->id }}" @selected((string) $memberValue('cohort_id', $cohort->id) === (string) $target->id)>{{ $target->name }}@if ($target->code) · {{ $target->code }}@endif</option>
                            @endforeach
                        </select>
                        <p class="ui-field__hint">{{ __('lf.LF_course_cohort_student_transfer_help') }}</p>
                        @if ($memberErrors->has('cohort_id'))<p class="ui-field__error" role="alert" x-show="showErrors">{{ $memberErrors->first('cohort_id') }}</p>@endif
                    </div>
                    <div class="ui-field">
                        <label class="ui-field__label" for="{{ $memberId }}-joined"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_course_cohort_student_common_joined_at') }}</label>
                        <x-ui-date-input id="{{ $memberId }}-joined" name="joined_at" :time="true" :value="$memberValue('joined_at', $savedJoinedAt)" :max="now()->format('Y-m-d\TH:i')" required />
                        <p class="ui-field__hint">{{ __('lf.LF_course_cohort_student_joined_at_help') }}</p>
                        @if ($memberErrors->has('joined_at'))<p class="ui-field__error" role="alert" x-show="showErrors">{{ $memberErrors->first('joined_at') }}</p>@endif
                    </div>
                    <div class="ui-field ui-field--full">
                        <label class="ui-field__label" for="{{ $memberId }}-reason">{{ __('lf.LF_course_cohort_student_common_transfer_reason') }}</label>
                        <input id="{{ $memberId }}-reason" type="text" name="transfer_reason" maxlength="500"
                               value="{{ $memberValue('transfer_reason', $student->transfer_reason ?? '') }}"
                               placeholder="{{ __('lf.LF_course_cohort_student_transfer_reason_placeholder') }}">
                        <p class="ui-field__hint">{{ __('lf.LF_course_cohort_student_transfer_reason_help') }}</p>
                        @if ($memberErrors->has('transfer_reason'))<p class="ui-field__error" role="alert" x-show="showErrors">{{ $memberErrors->first('transfer_reason') }}</p>@endif
                    </div>
                    <div class="ui-field ui-field--full">
                        <label class="ui-field__label" for="{{ $memberId }}-note">{{ __('lf.LF_course_cohort_student_common_note') }}</label>
                        <textarea id="{{ $memberId }}-note" name="note" rows="3" placeholder="{{ __('lf.LF_course_cohort_student_note_placeholder') }}">{{ $memberValue('note', $student->note ?? '') }}</textarea>
                        @if ($memberErrors->has('note'))<p class="ui-field__error" role="alert" x-show="showErrors">{{ $memberErrors->first('note') }}</p>@endif
                    </div>
                </div>
            </div>
            <footer class="ui-modal__footer">
                <span></span>
                <button type="button" class="ui-button cursor-interaction" x-on:click="$refs.memberDialog.close()" :disabled="submitting">{{ __('lf.LF_common_button_cancel') }}</button>
                <button class="ui-button ui-button--primary cursor-interaction" type="submit" :disabled="submitting" :aria-busy="submitting">
                    <x-backend-icon name="check" class="ui-icon" />
                    {{ __('lf.LF_common_button_save') }}
                </button>
            </footer>
        </form>
    </dialog>
</div>
