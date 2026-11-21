@php
    $rangeStartsAt = \Illuminate\Support\Carbon::parse($rangeStart);
    $rangeEndsAt = \Illuminate\Support\Carbon::parse($rangeEnd);
@endphp
{{-- The day and the time range each stay on one line; a narrow row breaks only between them. --}}
<span class="ui-enroll-nowrap">{{ \Illuminate\Support\Str::ucfirst($rangeStartsAt->translatedFormat('l')) }}, {{ $rangeStartsAt->format('d/m/Y') }}</span> · <span class="ui-enroll-nowrap">{{ $rangeStartsAt->format('H:i') }}–{{ $rangeEndsAt->isSameDay($rangeStartsAt) ? $rangeEndsAt->format('H:i') : $rangeEndsAt->format('d/m/Y H:i') }}</span>