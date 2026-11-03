{{-- Cohort identity card, same shape as the Version and Enrollment detail pages. --}}
@php
    $cohortTone = match ($cohort->status) { 'active' => 'active', 'draft' => 'draft', 'completed' => 'completed', default => 'archived' };
@endphp
<section class="mk-section ui-framework__identity" aria-labelledby="cohort-identity-title">
    <div class="ui-version__identity">
        <p id="cohort-edit-code" class="ui-version__eyebrow ui-cohort-code" x-data="{ copied: false }">
            <span>{{ $cohort->code ?: '—' }}</span>
            @if ($cohort->code)
                <button type="button" class="ux-row-edit cursor-interaction"
                        x-bind:aria-label="copied ? @js(__('lf.LF_course_cohort_edit_copied')) : @js(__('lf.LF_course_cohort_edit_copy_code'))"
                        x-bind:title="copied ? @js(__('lf.LF_course_cohort_edit_copied')) : @js(__('lf.LF_course_cohort_edit_copy_code'))"
                        x-on:click="navigator.clipboard.writeText(@js($cohort->code)).then(() => { copied = true; setTimeout(() => copied = false, 1600) })">
                    <span x-show="!copied"><x-backend-icon name="copy" class="ui-icon" /></span>
                    <span x-cloak x-show="copied"><x-backend-icon name="check" class="ui-icon" /></span>
                </button>
            @endif
        </p>
        <h2 id="cohort-identity-title">{{ $cohort->name }}</h2>
        <div class="ui-version__chips">
            <span id="cohort-edit-status" class="ui-status ui-status--{{ $cohortTone }}">{{ __('lf.LF_course_cohort_common_'.$cohort->status) }}</span>
            @if ($cohort->course_type ?? null)
                <span class="ui-course-type ui-course-type--{{ $cohort->course_type }}" data-course-type="{{ $cohort->course_type }}">{{ __(\App\Support\CourseType::labelKey($cohort->course_type)) }}</span>
            @endif
            @if (collect($cohortTabs)->contains('read_only', true))
                <span class="ui-status ui-status--archived course-cohort-readonly-label"><x-backend-icon name="lock" class="ui-icon" />{{ __('lf.LF_course_cohort_tab_read_only') }}</span>
            @endif
        </div>
    </div>
    @isset($identityActions)
        <div class="ui-enroll-head__actions">@include($identityActions)</div>
    @endisset
</section>
