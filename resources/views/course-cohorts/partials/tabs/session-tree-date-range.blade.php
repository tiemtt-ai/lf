@php
    $rangeStartsAt = \Illuminate\Support\Carbon::parse($rangeStart);
    $rangeEndsAt = \Illuminate\Support\Carbon::parse($rangeEnd);
@endphp
{{ \Illuminate\Support\Str::ucfirst($rangeStartsAt->translatedFormat('l')) }}, {{ $rangeStartsAt->format('d/m/Y') }} · {{ $rangeStartsAt->format('H:i') }}–{{ $rangeEndsAt->isSameDay($rangeStartsAt) ? $rangeEndsAt->format('H:i') : $rangeEndsAt->format('d/m/Y H:i') }}