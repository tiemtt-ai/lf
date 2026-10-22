{{-- UI Kit date input: admin-ui-kit-date.js turns it into the read-only dd/mm/yyyy field with the kit calendar; the server still gets yyyy-mm-dd. --}}
@props(['id', 'name', 'value' => null, 'max' => null, 'min' => null])

@php
    $weekdays = array_map(fn (string $day): string => __('lf.LF_common_weekday_'.$day), ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun']);
@endphp

<input id="{{ $id }}" type="date" name="{{ $name }}" value="{{ $value }}" data-ui-kit-date
       @if ($max) max="{{ $max }}" @endif @if ($min) min="{{ $min }}" @endif
       data-placeholder="{{ __('lf.LF_common_date_placeholder') }}"
       data-open-label="{{ __('lf.LF_common_date_open') }}"
       data-choose-label="{{ __('lf.LF_common_date_choose') }}"
       data-prev-label="{{ __('lf.LF_common_date_prev_month') }}"
       data-next-label="{{ __('lf.LF_common_date_next_month') }}"
       data-month-select-label="{{ __('lf.LF_common_date_month') }}"
       data-year-select-label="{{ __('lf.LF_common_date_year') }}"
       data-month-label="{{ __('lf.LF_common_date_month_n') }}"
       data-clear-label="{{ __('lf.LF_common_date_clear') }}"
       data-today-label="{{ __('lf.LF_common_date_today') }}"
       data-weekdays="{{ json_encode($weekdays, JSON_UNESCAPED_UNICODE) }}"
       {{ $attributes }}>
