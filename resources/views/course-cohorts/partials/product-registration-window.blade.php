@php
    $formatRegistration = static fn ($value): ?string => $value ? \Illuminate\Support\Carbon::parse($value)->format('d/m/Y H:i') : null;
    $registrationStart = $formatRegistration($cohort->product_registration_starts_at ?? null);
    $registrationEnd = $formatRegistration($cohort->product_registration_ends_at ?? null);
@endphp
<div class="ui-summary__item" id="{{ $registrationWindowTitleId }}">
    <dt class="ui-enroll-dt-tip">{{ __('lf.LF_course_cohort_product_registration_window') }} <x-info-tip :text="__('lf.LF_course_cohort_product_registration_help')" /></dt>
    <dd>
        @if ($registrationStart === null && $registrationEnd === null)
            {{ __('lf.LF_course_cohort_product_registration_unlimited') }}
        @else
            {{ $registrationStart ?: '—' }} <span aria-hidden="true">→</span> {{ $registrationEnd ?: '—' }}
        @endif
        @if (! empty($cohort->product_id))
            <a href="{{ route('admin.course-products.edit', $cohort->product_id) }}" class="ui-enroll-link ui-cohort-version-link" target="_blank" rel="noopener" aria-label="{{ __('lf.LF_course_cohort_product_registration_edit_new_tab') }}">
                <x-backend-icon name="square-pen" class="ui-icon" />{{ __('lf.LF_course_cohort_product_registration_edit') }}
            </a>
        @endif
    </dd>
</div>
