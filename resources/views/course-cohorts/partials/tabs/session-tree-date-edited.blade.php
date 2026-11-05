{{-- A Session whose date no longer matches its Schedule: the title says which date the Schedule gave it. --}}
@php
    $editedDefault = \Illuminate\Support\Carbon::parse($session->default_start_at);
    $editedDefaultEnd = \Illuminate\Support\Carbon::parse($session->default_end_at);
@endphp
<span class="ui-status ui-status--draft ui-cohort-session-edited"
      title="{{ __('lf.LF_course_cohort_session_date_default', ['date' => $editedDefault->format('d/m/Y').' · '.$editedDefault->format('H:i').'–'.$editedDefaultEnd->format('H:i')]) }}">{{ __('lf.LF_course_cohort_session_date_edited') }}</span>
