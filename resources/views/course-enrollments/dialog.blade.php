@php
    $usesDurationPolicy = \App\Support\CourseType::usesAccessDuration($enrollment->course_type);
    $canEditEnrollmentDate = ! $usesDurationPolicy || $enrollment->access_duration_days !== null;
    $originalEnrolledAt = \Illuminate\Support\Carbon::parse($enrollment->enrolled_at)->format('Y-m-d\TH:i');
    $localDate = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->format('Y-m-d\TH:i') : null;
    $storedWindows = collect(['access_starts_at', 'access_ends_at', 'review_starts_at', 'review_ends_at'])
        ->mapWithKeys(fn (string $field) => [$field => $enrollment->{$field} ? \Illuminate\Support\Carbon::parse($enrollment->{$field})->format('d/m/Y H:i') : '—']);
@endphp
<form class="ui-modal__form" method="POST" novalidate action="{{ route($routePrefix.'.update', $enrollment->id) }}"
      x-data="{
          enrolledAt: @js($originalEnrolledAt),
          originalEnrolledAt: @js($originalEnrolledAt),
          accessDays: @js($enrollment->access_duration_days),
          reviewDays: @js($enrollment->review_duration_days),
          registrationStart: @js($localDate($enrollment->registration_starts_at)),
          registrationEnd: @js($localDate($enrollment->registration_ends_at)),
          get registrationIssue() {
              if (this.enrolledAt === this.originalEnrolledAt) return '';
              if (Boolean(this.registrationStart) !== Boolean(this.registrationEnd) || (this.registrationStart && new Date(this.registrationStart) >= new Date(this.registrationEnd))) return @js(__('lf.LF_course_enrollment_registration_invalid'));
              if (this.registrationStart && new Date(this.enrolledAt) < new Date(this.registrationStart)) return @js(__('lf.LF_course_enrollment_registration_not_open', ['start' => ':start', 'enrolled_at' => ':enrolled_at'])).replace(':start', this.display(this.registrationStart)).replace(':enrolled_at', this.display(this.enrolledAt));
              if (this.registrationEnd && new Date(this.enrolledAt) > new Date(this.registrationEnd)) return @js(__('lf.LF_course_enrollment_registration_ended', ['end' => ':end', 'enrolled_at' => ':enrolled_at'])).replace(':end', this.display(this.registrationEnd)).replace(':enrolled_at', this.display(this.enrolledAt));
              return '';
          },
          addDays(value, days) { const date = new Date(value); date.setDate(date.getDate() + Number(days)); return date },
          display(value) { return value ? new Intl.DateTimeFormat(document.documentElement.lang || 'vi', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(value)) : '—' },
          preview(field) {
              if (this.accessDays === null) return @js($storedWindows)[field];
              const accessEnd = this.addDays(this.enrolledAt, this.accessDays);
              if (field === 'access_starts_at') return this.display(this.enrolledAt);
              if (field === 'access_ends_at') return this.display(accessEnd);
              if (!this.reviewDays) return '—';
              if (field === 'review_starts_at') return this.display(accessEnd);
              return this.display(this.addDays(accessEnd, this.reviewDays));
          },
      }">
    @csrf
    @method('PUT')
    <header class="ui-modal__header">
        <div>
            <h3 id="enrollment-dialog-title">{{ __('lf.LF_course_enrollment_common_edit') }}</h3>
            <p class="ui-caption">{{ $enrollment->student_name }} · {{ $enrollment->product_title }} · {{ __('lf.LF_course_product_item_common_version_number', ['number' => $enrollment->version_number]) }}</p>
        </div>
        <button class="ui-modal__close cursor-interaction" type="button" data-ui-dialog-form-cancel aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
    </header>
    <div class="ui-modal__body">
        <div class="ui-enroll-dialog">
            <div class="ui-field ui-enroll-dialog__date">
                <label class="ui-field__label" for="enrolled_at"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_course_enrollment_common_enrolled_at') }}</label>
                @if ($canEditEnrollmentDate)
                    <input id="enrolled_at" name="enrolled_at" type="datetime-local" value="{{ $originalEnrolledAt }}" x-model="enrolledAt" required>
                    <span x-show="registrationIssue" x-text="registrationIssue" class="ui-field__error" role="alert" x-cloak></span>
                @else
                    <input id="enrolled_at" type="text" readonly value="{{ \Illuminate\Support\Carbon::parse($enrollment->enrolled_at)->format('d/m/Y H:i') }}">
                    <span class="ui-field__error" role="status">{{ __('lf.LF_course_enrollment_legacy_duration_missing') }}</span>
                @endif
            </div>

            @if ($usesDurationPolicy && $enrollment->access_duration_days !== null)
                <div class="ui-enroll-impact" aria-live="polite">
                    <strong>{{ __('lf.LF_course_enrollment_time_impact_title') }}</strong>
                    <p class="ui-caption">{{ $enrollment->review_duration_days > 0
                        ? __('lf.LF_course_enrollment_time_impact_durations', ['access' => $enrollment->access_duration_days, 'review' => $enrollment->review_duration_days])
                        : __('lf.LF_course_enrollment_time_impact_without_review', ['access' => $enrollment->access_duration_days]) }}</p>
                    <dl class="course-enrollment-time-impact">
                        <div><dt>{{ __('lf.LF_course_enrollment_time_impact_enrolled_and_access_start') }}</dt><dd x-text="preview('access_starts_at')"></dd></div>
                        <div><dt>{{ $enrollment->review_duration_days > 0 ? __('lf.LF_course_enrollment_time_impact_access_end_review_start') : __('lf.LF_course_enrollment_common_access_ends_at') }}</dt><dd x-text="preview('access_ends_at')"></dd></div>
                        @if ($enrollment->review_duration_days > 0)
                            <div><dt>{{ __('lf.LF_course_enrollment_common_review_ends_at') }}</dt><dd x-text="preview('review_ends_at')"></dd></div>
                        @endif
                    </dl>
                    <p class="ui-caption">{{ __('lf.LF_course_enrollment_time_impact_unchanged') }}</p>
                </div>
            @endif

            @if ($usesDurationPolicy && $enrollment->access_duration_days === null)
                <div class="ui-modal__grid">
                    @foreach (['access_starts_at', 'access_ends_at', 'review_starts_at', 'review_ends_at'] as $field)
                        <div class="ui-field">
                            <span class="ui-field__label">{{ __('lf.LF_course_enrollment_common_'.$field) }}</span>
                            <div id="{{ $field }}" class="ui-enroll-readonly" x-text="preview('{{ $field }}')"></div>
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="ui-field">
                <label class="ui-field__label" for="notes">{{ __('lf.LF_course_enrollment_internal_notes') }}</label>
                <textarea id="notes" name="notes" rows="3" placeholder="{{ __('lf.LF_course_enrollment_notes_placeholder') }}">{{ $enrollment->notes }}</textarea>
                <span class="ui-caption">{{ __('lf.LF_course_enrollment_notes_help') }}</span>
            </div>
        </div>
    </div>
    <footer class="ui-modal__footer">
        <span class="ui-caption ui-field__error" role="alert" data-ui-dialog-form-feedback></span>
        <button type="button" class="ui-button cursor-interaction" data-ui-dialog-form-cancel>{{ __('lf.LF_common_button_cancel') }}</button>
        <button type="submit" class="ui-button ui-button--primary cursor-interaction" data-ui-dialog-form-submit :disabled="Boolean(registrationIssue)">
            <x-backend-icon name="check" class="ui-icon" />
            {{ __('lf.LF_common_button_save_changes') }}
        </button>
    </footer>
</form>
