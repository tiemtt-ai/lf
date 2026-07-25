<div class="admin-card admin-form-card admin-form-surface"
     x-data="{ sessionId: @js((string) ($sessions->first()?->id ?? '')), base: @js(url('/admin/course-cohorts/'.$cohort->id.'/sessions')) }">
    <div class="admin-form-standard">
        <section class="admin-form-standard-section">
            <header class="admin-form-section-header">
                <h2 class="admin-form-section-title">{{ __('lf.LF_course_cohort_recording_title') }}</h2>
                <p class="admin-form-section-help">{{ __('lf.LF_course_cohort_recording_help') }}</p>
            </header>
            @if ($sessions->isNotEmpty())
                <form method="POST" x-bind:action="`${base}/${sessionId}/recordings`" class="admin-form-field-grid">
                    @csrf
                    <div class="lf-form-group">
                        <x-form-label for="recording_session_id" :value="__('lf.LF_course_cohort_attendance_session')" :required="true" />
                        <select id="recording_session_id" x-model="sessionId" class="lf-form-control" required>@foreach($sessions as $session)<option value="{{ $session->id }}">#{{ $session->session_no }} · {{ $session->title }}</option>@endforeach</select>
                    </div>
                    <div class="lf-form-group">
                        <x-form-label for="recording_title" :value="__('lf.LF_course_cohort_recording_name')" :required="true" />
                        <input id="recording_title" name="title" class="lf-form-control" required>
                    </div>
                    <div class="lf-form-group admin-form-field--full">
                        <x-form-label for="recording_url" :value="__('lf.LF_course_cohort_recording_url')" />
                        <input id="recording_url" type="url" name="recording_url" class="lf-form-control">
                    </div>
                    <div class="admin-form-actions admin-form-field--full"><button type="submit" class="btn btn-primary">{{ __('lf.LF_course_cohort_recording_add') }}</button></div>
                </form>
            @endif
        </section>
        <section class="admin-form-standard-section">
            <div class="admin-table-wrap"><table class="table">
                <thead><tr><th>{{ __('lf.LF_course_cohort_attendance_session') }}</th><th>{{ __('lf.LF_course_cohort_recording_name') }}</th><th>{{ __('lf.LF_course_cohort_common_status') }}</th><th>{{ __('lf.LF_course_cohort_recording_window') }}</th></tr></thead>
                <tbody>
                @forelse($recordings as $recording)
                    <tr><td>#{{ $recording->session_no }} · {{ $recording->session_title }}</td><td><strong>{{ $recording->title }}</strong>@if($recording->recording_url)<br><a href="{{ $recording->recording_url }}" target="_blank" rel="noopener noreferrer">{{ __('lf.LF_course_cohort_recording_open') }}</a>@endif</td><td>{{ $recording->status }}</td><td>{{ $recording->replay_available_from ?: '—' }} → {{ $recording->replay_available_until ?: '—' }}</td></tr>
                @empty
                    <tr><td colspan="4"><div class="course-cohort-empty-state">{{ __('lf.LF_course_cohort_recording_empty') }}</div></td></tr>
                @endforelse
                </tbody>
            </table></div>
        </section>
    </div>
</div>
