@extends('layouts.backend')

@section('title', __('lf.LF_course_enrollment_common_edit'))
@section('page_title', __('lf.LF_course_enrollment_common_edit'))

@section('page_title_hidden', '1')

@section('page_back')
    <x-page-back-link :href="route($routePrefix.'.show', $enrollment->id)" :label="__('lf.LF_course_enrollment_common_back_to_detail')" />
@endsection

@section('vite')
    @parent
    @vite(['resources/css/admin/admin-ui-kit.css', 'resources/js/admin-ui-kit-controls.js'])
@endsection

@section('content')
    @php
        $usesDurationPolicy = \App\Support\CourseType::usesAccessDuration($enrollment->course_type);
        $canEditEnrollmentDate = ! $usesDurationPolicy || $enrollment->access_duration_days !== null;
        $statusTone = match ($enrollment->status) {
            'active' => 'active',
            'pending' => 'draft',
            'suspended' => 'inactive',
            'expired', 'cancelled' => 'danger',
            default => 'archived',
        };
    @endphp
<div class="lf-admin-ui lf-admin-ui--list ui-enroll">
    <x-ui-list-head :title="__('lf.LF_course_enrollment_common_edit')" :text="__('lf.LF_course_enrollment_edit_description')" />

    @if ($errors->any())
        <div class="admin-alert admin-alert-danger" role="alert">
            <ul>
                @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    @include('course-enrollments.partials.access-summary', ['headingId' => 'enrollment-edit-access', 'showMeta' => true])

    <form class="ui-enroll-form" method="POST" action="{{ route($routePrefix.'.update', $enrollment->id) }}"
          x-data="enrollmentTimeEditor({
                  enrolledAt: @js(old('enrolled_at', \Illuminate\Support\Carbon::parse($enrollment->enrolled_at)->format('Y-m-d\TH:i'))),
                  originalEnrolledAt: @js(\Illuminate\Support\Carbon::parse($enrollment->enrolled_at)->format('Y-m-d\TH:i')),
                  accessDays: @js($enrollment->access_duration_days),
                  reviewDays: @js($enrollment->review_duration_days),
                  registrationStart: @js($enrollment->registration_starts_at ? \Illuminate\Support\Carbon::parse($enrollment->registration_starts_at)->format('Y-m-d\TH:i') : null),
                  registrationEnd: @js($enrollment->registration_ends_at ? \Illuminate\Support\Carbon::parse($enrollment->registration_ends_at)->format('Y-m-d\TH:i') : null),
              })" x-on:submit="if (submitting || registrationIssue) { $event.preventDefault(); document.getElementById('enrolled_at')?.focus(); return } submitting = true">
        @csrf
        @method('PUT')

        <section class="mk-section" aria-labelledby="enrollment-edit-information">
            <div class="ui-summary-head"><h2 id="enrollment-edit-information">{{ __('lf.LF_course_enrollment_information') }}</h2></div>
            <div class="ui-enroll-form__body">
                <div class="ui-modal__grid">
                    <div class="ui-field">
                        <label class="ui-field__label" for="enrolled_at">{{ __('lf.LF_course_enrollment_common_enrolled_at') }}</label>
                        @if ($canEditEnrollmentDate)
                            <input id="enrolled_at" name="enrolled_at" type="datetime-local" x-model="enrolledAt" required>
                            <span x-show="registrationIssue" x-text="registrationIssue" class="ui-field__error" role="alert" x-cloak></span>
                        @else
                            <input id="enrolled_at" type="text" readonly value="{{ \Illuminate\Support\Carbon::parse($enrollment->enrolled_at)->format('d/m/Y H:i') }}">
                            <span class="ui-field__error" role="status">{{ __('lf.LF_course_enrollment_legacy_duration_missing') }}</span>
                        @endif
                    </div>
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
                    <textarea id="notes" name="notes" rows="4" placeholder="{{ __('lf.LF_course_enrollment_notes_placeholder') }}">{{ old('notes', $enrollment->notes) }}</textarea>
                    <span class="ui-caption">{{ __('lf.LF_course_enrollment_notes_help') }}</span>
                    @error('notes')<span class="ui-field__error" role="alert">{{ $message }}</span>@enderror
                </div>
            </div>
            <footer class="ui-enroll-form__actions">
                <a href="{{ route($routePrefix.'.show', $enrollment->id) }}" class="ui-button cursor-interaction">{{ __('lf.LF_common_button_cancel') }}</a>
                <button type="submit" class="ui-button ui-button--primary cursor-interaction" :disabled="submitting" :aria-busy="submitting">
                    <x-backend-icon name="check" class="ui-icon" />
                    <span x-show="!submitting">{{ __('lf.LF_common_button_save_changes') }}</span><span x-cloak x-show="submitting">{{ __('lf.LF_course_enrollment_update_saving') }}</span>
                </button>
            </footer>
        </section>
    </form>
</div>

    <script>
        function enrollmentTimeEditor(config) {
            return {
                submitting: false,
                enrolledAt: config.enrolledAt,
                originalEnrolledAt: config.originalEnrolledAt,
                accessDays: config.accessDays,
                reviewDays: config.reviewDays,
                registrationStart: config.registrationStart,
                registrationEnd: config.registrationEnd,
                get registrationIssue() {
                    if (this.enrolledAt === this.originalEnrolledAt) return '';
                    if (Boolean(this.registrationStart) !== Boolean(this.registrationEnd)
                        || (this.registrationStart && new Date(this.registrationStart) >= new Date(this.registrationEnd))) {
                        return @js(__('lf.LF_course_enrollment_registration_invalid'));
                    }
                    if (this.registrationStart && new Date(this.enrolledAt) < new Date(this.registrationStart)) {
                        return @js(__('lf.LF_course_enrollment_registration_not_open', ['start' => ':start', 'enrolled_at' => ':enrolled_at']))
                            .replace(':start', this.display(this.registrationStart)).replace(':enrolled_at', this.display(this.enrolledAt));
                    }
                    if (this.registrationEnd && new Date(this.enrolledAt) > new Date(this.registrationEnd)) {
                        return @js(__('lf.LF_course_enrollment_registration_ended', ['end' => ':end', 'enrolled_at' => ':enrolled_at']))
                            .replace(':end', this.display(this.registrationEnd)).replace(':enrolled_at', this.display(this.enrolledAt));
                    }
                    return '';
                },
                addDays(value, days) { const date = new Date(value); date.setDate(date.getDate() + Number(days)); return date },
                display(value) { return value ? new Intl.DateTimeFormat(document.documentElement.lang || 'vi', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(value)) : '—' },
                preview(field) {
                    if (this.accessDays === null) return @js([
                        'access_starts_at' => $enrollment->access_starts_at ? \Illuminate\Support\Carbon::parse($enrollment->access_starts_at)->format('d/m/Y H:i') : '—',
                        'access_ends_at' => $enrollment->access_ends_at ? \Illuminate\Support\Carbon::parse($enrollment->access_ends_at)->format('d/m/Y H:i') : '—',
                        'review_starts_at' => $enrollment->review_starts_at ? \Illuminate\Support\Carbon::parse($enrollment->review_starts_at)->format('d/m/Y H:i') : '—',
                        'review_ends_at' => $enrollment->review_ends_at ? \Illuminate\Support\Carbon::parse($enrollment->review_ends_at)->format('d/m/Y H:i') : '—',
                    ])[field];
                    const accessEnd = this.addDays(this.enrolledAt, this.accessDays);
                    if (field === 'access_starts_at') return this.display(this.enrolledAt);
                    if (field === 'access_ends_at') return this.display(accessEnd);
                    if (!this.reviewDays) return '—';
                    if (field === 'review_starts_at') return this.display(accessEnd);
                    return this.display(this.addDays(accessEnd, this.reviewDays));
                },
            }
        }
    </script>
@endsection
