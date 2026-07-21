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

    <div class="cohort-detail-toolbar">
        <a class="cohort-detail-back" href="{{ route($routePrefix.'.show', $enrollment->id) }}">
            <span aria-hidden="true">←</span>
            {{ __('lf.LF_course_enrollment_common_back_to_detail') }}
        </a>
    </div>

    <div class="admin-card admin-form-card admin-form-surface">
        <form class="admin-form-standard" method="POST" action="{{ route($routePrefix.'.update', $enrollment->id) }}"
              x-data="{ submitting: false }" x-on:submit="if (submitting) { $event.preventDefault(); return } submitting = true">
            @csrf
            @method('PUT')

            <div class="admin-form-flow">
                <section class="admin-form-standard-section" aria-labelledby="enrollment-edit-access">
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
                        <div class="lf-form-group admin-form-field admin-form-field--full">
                            <span class="lf-form-label">{{ __('lf.LF_course_enrollment_common_version') }}</span>
                            <div class="admin-form-calculated-summary"><strong class="admin-form-calculated-summary-value">{{ $enrollment->version_title }}</strong><span class="admin-form-calculated-summary-meta">{{ __('lf.LF_course_product_item_common_version_number', ['number' => $enrollment->version_number]) }} · {{ $enrollment->version_code }}</span></div>
                        </div>
                    </div>
                </section>

                <section class="admin-form-standard-section" aria-labelledby="enrollment-edit-information">
                    <header class="admin-form-section-header"><h2 id="enrollment-edit-information" class="admin-form-section-title">{{ __('lf.LF_course_enrollment_information') }}</h2></header>
                    <div class="admin-form-field-grid">
                        <div class="lf-form-group admin-form-field">
                            <span class="lf-form-label">{{ __('lf.LF_course_enrollment_common_status') }}</span>
                            <div class="admin-form-readonly lf-form-control"><span @class(['badge', 'badge-success' => $enrollment->status === 'active'])>{{ __('lf.LF_course_enrollment_common_'.$enrollment->status) }}</span></div>
                        </div>
                        <div class="lf-form-group admin-form-field">
                            <span class="lf-form-label">{{ __('lf.LF_course_enrollment_common_source') }}</span>
                            <div class="admin-form-readonly lf-form-control">{{ __('lf.LF_course_enrollment_common_source_'.$enrollment->source) }}</div>
                        </div>
                        <div class="lf-form-group admin-form-field admin-form-field--full">
                            <span class="lf-form-label">{{ __('lf.LF_course_enrollment_common_enrolled_at') }}</span>
                            <div class="admin-form-readonly lf-form-control">{{ $enrollment->enrolled_at }}</div>
                        </div>
                    </div>
                </section>

                <section class="admin-form-standard-section" aria-labelledby="enrollment-edit-access-window">
                    <header class="admin-form-section-header"><h2 id="enrollment-edit-access-window" class="admin-form-section-title">{{ __('lf.LF_course_enrollment_access_window') }}</h2><p class="admin-form-section-help">{{ __('lf.LF_course_enrollment_access_help') }}</p></header>
                    <div class="admin-form-field-grid">
                        @foreach (['access_starts_at', 'access_ends_at'] as $field)
                            <div class="lf-form-group admin-form-field">
                                <label class="lf-form-label" for="{{ $field }}">{{ __('lf.LF_course_enrollment_common_'.$field) }}</label>
                                <input id="{{ $field }}" type="datetime-local" name="{{ $field }}" class="lf-form-control" value="{{ old($field, optional($enrollment->{$field} ? \Illuminate\Support\Carbon::parse($enrollment->{$field}) : null)->format('Y-m-d\\TH:i')) }}" @error($field) aria-invalid="true" @enderror>
                                @error($field)<p class="lf-form-error" role="alert">{{ $message }}</p>@enderror
                            </div>
                        @endforeach
                    </div>
                </section>

                <section class="admin-form-standard-section" aria-labelledby="enrollment-edit-review-window">
                    <header class="admin-form-section-header"><h2 id="enrollment-edit-review-window" class="admin-form-section-title">{{ __('lf.LF_course_enrollment_review_window') }}</h2><p class="admin-form-section-help">{{ __('lf.LF_course_enrollment_review_help') }}</p></header>
                    <div class="admin-form-field-grid">
                        @foreach (['review_starts_at', 'review_ends_at'] as $field)
                            <div class="lf-form-group admin-form-field">
                                <label class="lf-form-label" for="{{ $field }}">{{ __('lf.LF_course_enrollment_common_'.$field) }}</label>
                                <input id="{{ $field }}" type="datetime-local" name="{{ $field }}" class="lf-form-control" value="{{ old($field, optional($enrollment->{$field} ? \Illuminate\Support\Carbon::parse($enrollment->{$field}) : null)->format('Y-m-d\\TH:i')) }}" @error($field) aria-invalid="true" @enderror>
                                @error($field)<p class="lf-form-error" role="alert">{{ $message }}</p>@enderror
                            </div>
                        @endforeach
                    </div>
                </section>

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
@endsection
