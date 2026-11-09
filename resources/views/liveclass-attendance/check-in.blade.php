@extends('layouts.tenant')

@php
    $t = fn (string $key, array $replace = []) => __('lf.LF_liveclass_attendance_checkin_'.$key, $replace);
    $recorded = is_array($recorded) ? $recorded : [];
    $joinedAt = ! empty($recorded['joined_at']) ? \Illuminate\Support\Carbon::parse($recorded['joined_at'])->format('H:i') : null;
@endphp

@section('title', $t('title').' | '.($tenant?->name ?? 'LF'))

@section('content')
    {{-- LF-Core-LiveClass § Attendance, Điểm danh bằng mã QR. --}}
    <section class="student-card student-section lf-checkin" aria-labelledby="lf-checkin-title" data-checkin-result="{{ $result ?? ($pending ? 'pending' : 'idle') }}">
        <h1 id="lf-checkin-title" class="student-section-title">{{ $t('title') }}</h1>

        @if (in_array($result, ['recorded', 'already'], true))
            <p class="lf-checkin__status" role="status">
                @if ($result === 'recorded')
                    {{ $recorded['status'] === 'late' ? $t('recorded_late') : $t('recorded_present') }}
                @else
                    {{ $t('already') }}
                @endif
            </p>
            @if (! empty($recorded['title']))
                <p class="student-section-copy">{{ $recorded['title'] }} · {{ $recorded['cohort'] }}</p>
            @endif
            @if ($joinedAt)
                <p class="student-section-copy">{{ $t('joined_at', ['time' => $joinedAt]) }}</p>
            @endif
        @elseif ($pending)
            <form method="POST" action="{{ route('student.live-sessions.check-in.store', $session) }}" x-data x-init="$el.requestSubmit()">
                @csrf
                <p class="student-section-copy" role="status">{{ $t('confirming') }}</p>
                <noscript><p class="student-section-copy">{{ $t('confirm_help') }}</p></noscript>
                <button type="submit" class="student-button">{{ $t('confirm') }}</button>
            </form>
        @elseif ($wrongAccount)
            <p class="lf-checkin__status" role="alert">{{ $t('wrong_account') }}</p>
        @elseif ($result)
            <p class="lf-checkin__status" role="alert">{{ $t($result) }}</p>
        @else
            <p class="student-section-copy">{{ $t('scan_again') }}</p>
        @endif

        @if ($studentMode)
            <p><a class="student-button is-outline" href="{{ route('student.courses.index') }}">{{ $t('back') }}</a></p>
        @endif
    </section>
@endsection
