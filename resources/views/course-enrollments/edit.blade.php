@extends('layouts.backend')

@section('title', __('lf.LF_course_enrollment_common_edit'))
@section('page_title', __('lf.LF_course_enrollment_common_edit'))

@section('content')
    @if ($errors->any())
        <div class="admin-alert admin-alert-danger admin-form-card" role="alert">
            <ul>
                @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <div class="cohort-detail-toolbar course-enrollment-edit-toolbar">
        <a class="cohort-detail-back" href="{{ route($routePrefix.'.show', $enrollment->id) }}">
            <span aria-hidden="true">←</span>
            {{ __('lf.LF_course_enrollment_common_back_to_detail') }}
        </a>
    </div>

    <div class="admin-card admin-form-card admin-form-surface course-enrollment-edit">
        <form class="admin-form-standard" method="POST" action="{{ route($routePrefix.'.update', $enrollment->id) }}"
              x-data="enrollmentTimeEditor({
                  enrolledAt: @js(old('enrolled_at', \Illuminate\Support\Carbon::parse($enrollment->enrolled_at)->format('Y-m-d\TH:i'))),
                  accessDays: @js($enrollment->access_duration_days),
                  reviewDays: @js($enrollment->review_duration_days),
              })" x-on:submit="if (submitting) { $event.preventDefault(); return } submitting = true">
            @csrf
            @method('PUT')

            <div class="admin-form-flow">
                <section class="admin-form-standard-section course-enrollment-identity-section" aria-labelledby="enrollment-edit-access">
                    <header class="admin-form-section-header">
                        <h2 id="enrollment-edit-access" class="admin-form-section-title">{{ __('lf.LF_course_enrollment_group_access') }}</h2>
                        <p class="admin-form-section-help">{{ __('lf.LF_course_enrollment_common_frozen_help') }}</p>
                    </header>
                    <div class="admin-form-field-grid">
                        <div class="lf-form-group admin-form-field">
                            <span class="lf-form-label">{{ __('lf.LF_course_enrollment_common_student') }}</span>
                            <div class="admin-form-calculated-summary"><strong class="admin-form-calculated-summary-value">{{ $enrollment->student_name }}</strong><span class="admin-form-calculated-summary-meta">{{ $enrollment->student_email }}</span></div>
                        </div>
                        <div class="lf-form-group admin-form-field">
                            <span class="lf-form-label">{{ __('lf.LF_course_enrollment_common_product') }}</span>
                            <div class="admin-form-calculated-summary"><strong class="admin-form-calculated-summary-value">{{ $enrollment->product_title }}</strong><span class="admin-form-calculated-summary-meta">{{ $enrollment->product_code }}</span></div>
                        </div>
                        <div class="lf-form-group admin-form-field">
                            <span class="lf-form-label">{{ __('lf.LF_course_enrollment_common_version') }}</span>
                            <div class="admin-form-calculated-summary"><strong class="admin-form-calculated-summary-value">{{ $enrollment->version_title }}</strong><span class="admin-form-calculated-summary-meta">{{ __('lf.LF_course_product_item_common_version_number', ['number' => $enrollment->version_number]) }} · {{ $enrollment->version_code }}</span></div>
                        </div>
                    </div>
                </section>

                <section class="admin-form-standard-section course-enrollment-system-section" aria-labelledby="enrollment-edit-information">
                    <header class="admin-form-section-header"><h2 id="enrollment-edit-information" class="admin-form-section-title">{{ __('lf.LF_course_enrollment_information') }}</h2></header>
                    <div class="admin-form-field-grid course-enrollment-information-grid">
                        <div class="lf-form-group admin-form-field course-enrollment-enrolled-at-field">
                            <label class="lf-form-label" for="enrolled_at">{{ __('lf.LF_course_enrollment_common_enrolled_at') }}</label>
                            @if ($enrollment->access_duration_days !== null)
                                <input id="enrolled_at" name="enrolled_at" type="datetime-local" class="lf-form-control" x-model="enrolledAt" required>
                            @else
                                <div id="enrolled_at" class="admin-form-readonly lf-form-control">{{ \Illuminate\Support\Carbon::parse($enrollment->enrolled_at)->format('d/m/Y H:i') }}</div>
                                <p class="lf-form-error" role="status">{{ __('lf.LF_course_enrollment_legacy_duration_missing') }}</p>
                            @endif
                        </div>
                        <div class="lf-form-group admin-form-field course-enrollment-inline-meta">
                            <span class="lf-form-label">{{ __('lf.LF_course_enrollment_common_source') }}</span>
                            <div class="admin-form-readonly lf-form-control">{{ __('lf.LF_course_enrollment_common_source_'.$enrollment->source) }}</div>
                        </div>
                        <div class="lf-form-group admin-form-field course-enrollment-inline-meta">
                            <span class="lf-form-label">{{ __('lf.LF_course_enrollment_common_status') }}</span>
                            <div class="admin-form-readonly lf-form-control"><span @class(['badge', 'badge-success' => $enrollment->status === 'active'])>{{ __('lf.LF_course_enrollment_common_'.$enrollment->status) }}</span></div>
                        </div>
                        @if ($enrollment->access_duration_days !== null)
                            <div class="course-enrollment-time-impact" aria-live="polite">
                                <p class="course-enrollment-time-impact__title">{{ __('lf.LF_course_enrollment_time_impact_title') }}</p>
                                <p>{{ $enrollment->review_duration_days > 0
                                    ? __('lf.LF_course_enrollment_time_impact_durations', ['access' => $enrollment->access_duration_days, 'review' => $enrollment->review_duration_days])
                                    : __('lf.LF_course_enrollment_time_impact_without_review', ['access' => $enrollment->access_duration_days]) }}</p>
                                <div @class(['course-enrollment-time-impact__timeline', 'course-enrollment-time-impact__timeline--without-review' => ! ($enrollment->review_duration_days > 0)])>
                                    <div><span>{{ __('lf.LF_course_enrollment_time_impact_enrolled_and_access_start') }}</span><strong x-text="preview('access_starts_at')"></strong></div>
                                    <span class="course-enrollment-time-impact__arrow" aria-hidden="true">→</span>
                                    <div><span>{{ $enrollment->review_duration_days > 0 ? __('lf.LF_course_enrollment_time_impact_access_end_review_start') : __('lf.LF_course_enrollment_common_access_ends_at') }}</span><strong x-text="preview('access_ends_at')"></strong></div>
                                    @if ($enrollment->review_duration_days > 0)
                                        <span class="course-enrollment-time-impact__arrow" aria-hidden="true">→</span>
                                        <div><span>{{ __('lf.LF_course_enrollment_common_review_ends_at') }}</span><strong x-text="preview('review_ends_at')"></strong></div>
                                    @endif
                                </div>
                                <p class="course-enrollment-time-impact__note">{{ __('lf.LF_course_enrollment_time_impact_unchanged') }}</p>
                            </div>
                        @endif
                    </div>
                </section>

                @if ($enrollment->access_duration_days === null)
                <section class="admin-form-standard-section" aria-labelledby="enrollment-edit-access-window">
                    <header class="admin-form-section-header"><h2 id="enrollment-edit-access-window" class="admin-form-section-title">{{ __('lf.LF_course_enrollment_access_window') }}</h2><p class="admin-form-section-help">{{ __('lf.LF_course_enrollment_access_help') }}</p></header>
                    <div class="admin-form-field-grid">
                        @foreach (['access_starts_at', 'access_ends_at'] as $field)
                            <div class="lf-form-group admin-form-field">
                                <span class="lf-form-label">{{ __('lf.LF_course_enrollment_common_'.$field) }}</span>
                                <div id="{{ $field }}" class="admin-form-readonly lf-form-control" x-text="preview('{{ $field }}')"></div>
                            </div>
                        @endforeach
                    </div>
                </section>

                <section class="admin-form-standard-section" aria-labelledby="enrollment-edit-review-window">
                    <header class="admin-form-section-header"><h2 id="enrollment-edit-review-window" class="admin-form-section-title">{{ __('lf.LF_course_enrollment_review_window') }}</h2><p class="admin-form-section-help">{{ __('lf.LF_course_enrollment_review_help') }}</p></header>
                    <div class="admin-form-field-grid">
                        @foreach (['review_starts_at', 'review_ends_at'] as $field)
                            <div class="lf-form-group admin-form-field">
                                <span class="lf-form-label">{{ __('lf.LF_course_enrollment_common_'.$field) }}</span>
                                <div id="{{ $field }}" class="admin-form-readonly lf-form-control" x-text="preview('{{ $field }}')"></div>
                            </div>
                        @endforeach
                    </div>
                </section>
                @endif

                <section class="admin-form-standard-section" aria-labelledby="enrollment-edit-additional">
                    <header class="admin-form-section-header"><h2 id="enrollment-edit-additional" class="admin-form-section-title">{{ __('lf.LF_course_enrollment_additional_information') }}</h2></header>
                    <div class="admin-form-field-grid">
                        <div class="lf-form-group admin-form-field admin-form-field--full">
                            <label class="lf-form-label" for="notes">{{ __('lf.LF_course_enrollment_internal_notes') }}</label>
                            <textarea id="notes" name="notes" class="lf-form-control" rows="4" placeholder="{{ __('lf.LF_course_enrollment_notes_placeholder') }}">{{ old('notes', $enrollment->notes) }}</textarea>
                            <p class="lf-form-help">{{ __('lf.LF_course_enrollment_notes_help') }}</p>
                            @error('notes')<p class="lf-form-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </section>
            </div>

            <footer class="admin-form-footer" data-actions-align="end">
                <div class="admin-form-footer-primary">
                    <a href="{{ route($routePrefix.'.show', $enrollment->id) }}" class="btn btn-secondary">{{ __('lf.LF_common_button_cancel') }}</a>
                    <button type="submit" class="btn btn-primary" :disabled="submitting" :aria-busy="submitting"><span x-show="!submitting">{{ __('lf.LF_common_button_save_changes') }}</span><span x-cloak x-show="submitting">{{ __('lf.LF_course_enrollment_update_saving') }}</span></button>
                </div>
            </footer>
        </form>
    </div>

    <script>
        function enrollmentTimeEditor(config) {
            return {
                submitting: false,
                enrolledAt: config.enrolledAt,
                accessDays: config.accessDays,
                reviewDays: config.reviewDays,
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
