@php
    $registrationStartValue = $cohort->product_registration_starts_at ?? null;
    $registrationEndValue = $cohort->product_registration_ends_at ?? null;
    $registrationStart = $registrationStartValue
        ? \Illuminate\Support\Carbon::parse($registrationStartValue)->format('d/m/Y H:i')
        : null;
    $registrationEnd = $registrationEndValue
        ? \Illuminate\Support\Carbon::parse($registrationEndValue)->format('d/m/Y H:i')
        : null;
    $registrationUnlimited = $registrationStart === null && $registrationEnd === null;
@endphp

<aside @class([
           'cohort-product-registration-window',
           'cohort-product-registration-window--compact' => $compact ?? false,
       ])
       aria-labelledby="{{ $registrationWindowTitleId }}">
    <div class="cohort-product-registration-window__heading">
        <div>
            @if (! ($compact ?? false))
                <span class="cohort-product-registration-window__source">{{ __('lf.LF_course_cohort_common_product') }}</span>
            @endif
            <h3 id="{{ $registrationWindowTitleId }}">{{ __('lf.LF_course_cohort_product_registration_window') }}</h3>
        </div>
        @if ($registrationUnlimited)
            <span class="badge cohort-product-registration-window__badge">{{ __('lf.LF_course_cohort_product_registration_unlimited') }}</span>
        @endif
    </div>

    @if (! $registrationUnlimited)
        <dl class="cohort-product-registration-window__dates">
            <div>
                <dt>{{ __('lf.LF_course_product_common_registration_starts_at') }}</dt>
                <dd>{{ $registrationStart ?: '—' }}</dd>
            </div>
            <div>
                <dt>{{ __('lf.LF_course_product_common_registration_ends_at') }}</dt>
                <dd>{{ $registrationEnd ?: '—' }}</dd>
            </div>
        </dl>
    @endif

    <p>{{ __('lf.LF_course_cohort_product_registration_help') }}</p>
</aside>
