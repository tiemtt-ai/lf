{{-- UI Kit date input: admin-ui-kit-date.js turns it into the read-only dd/mm/yyyy field with the kit calendar; the server still gets yyyy-mm-dd.
     With `time`, the field is dd/mm/yyyy hh:mm with hour and minute selects in the calendar; the server gets yyyy-mm-ddThh:mm. --}}
@props(['id', 'name', 'value' => null, 'max' => null, 'min' => null, 'time' => false])

@php
    $weekdays = array_map(fn (string $day): string => __('lf.LF_common_weekday_'.$day), ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun']);
@endphp

<input id="{{ $id }}" type="{{ $time ? 'datetime-local' : 'date' }}" name="{{ $name }}" value="{{ $value }}" data-ui-kit-date
       @if ($max) max="{{ $max }}" @endif @if ($min) min="{{ $min }}" @endif
       data-placeholder="{{ $time ? __('lf.LF_common_date_time_placeholder') : __('lf.LF_common_date_placeholder') }}"
       data-open-label="{{ __('lf.LF_common_date_open') }}"
       data-choose-label="{{ $time ? __('lf.LF_common_date_choose_time') : __('lf.LF_common_date_choose') }}"
       data-prev-label="{{ __('lf.LF_common_date_prev_month') }}"
       data-next-label="{{ __('lf.LF_common_date_next_month') }}"
       data-month-select-label="{{ __('lf.LF_common_date_month') }}"
       data-year-select-label="{{ __('lf.LF_common_date_year') }}"
       data-month-label="{{ __('lf.LF_common_date_month_n') }}"
       data-clear-label="{{ __('lf.LF_common_date_clear') }}"
       data-today-label="{{ __('lf.LF_common_date_today') }}"
       @if ($time)
           data-time-label="{{ __('lf.LF_common_date_time') }}"
           data-hour-label="{{ __('lf.LF_common_date_hour') }}"
           data-minute-label="{{ __('lf.LF_common_date_minute') }}"
           data-now-label="{{ __('lf.LF_common_date_now') }}"
           data-done-label="{{ __('lf.LF_common_date_done') }}"
       @endif
       data-weekdays="{{ json_encode($weekdays, JSON_UNESCAPED_UNICODE) }}"
       {{ $attributes }}>
