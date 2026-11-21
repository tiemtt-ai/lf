@php
    // A recording is added to a session that has ended, in an active class, by an admin (ADR-0033 D7).
    $recordingAddable = $cohort->status === 'active' && $sessions->isNotEmpty() && ($cohortAdmin ?? true);
    $recordingSessionId = (string) old('session_id', (string) ($sessions->first()?->id ?? ''));
    $recordingTone = fn (string $status): string => match ($status) {
        'ready' => 'ui-status--active',
        'failed' => 'ui-status--danger',
        'archived', 'deleted' => 'ui-status--archived',
        default => 'ui-status--draft',
    };
    $recordingTime = fn (?string $value): string => $value ? \Illuminate\Support\Carbon::parse($value)->format('d/m/Y H:i') : '—';
@endphp
<section class="mk-section ui-cohort-recordings" aria-labelledby="cohort-recordings-title"
     x-data="{
         open: @js($recordingAddable && $errors->hasAny(['title', 'recording_url', 'replay_available_from', 'replay_available_until'])),
         showErrors: true,
         submitting: false,
         sessionId: @js($recordingSessionId),
         base: @js(url('/admin/course-cohorts/'.$cohort->id.'/sessions')),
         discard() {
             const form = this.$refs.recordingForm
             if (!form) return
             for (const input of form.querySelectorAll('input[type=text], input[type=url]')) input.value = ''
             this.showErrors = false
         }
     }"
     x-init="$watch('open', isOpen => { const dialog = $refs.recordingDialog; if (!dialog) return; if (isOpen) dialog.showModal(); else { if (dialog.open) dialog.close(); discard() } }); if (open) $refs.recordingDialog?.showModal()">
    <div class="ui-summary-head">
        <div>
            <h2 id="cohort-recordings-title">{{ __('lf.LF_course_cohort_recording_title') }}</h2>
            <p class="ui-caption">{{ __('lf.LF_course_cohort_recording_help') }}</p>
        </div>
        <div class="mk-table-top__actions">
            <span class="ui-caption">{{ trans_choice('lf.LF_course_cohort_recording_count', $recordings->count(), ['count' => $recordings->count()]) }}</span>
            @if ($recordingAddable)
                <button type="button" class="ui-button ui-button--outline cursor-interaction" aria-haspopup="dialog" x-on:click="open = true" data-recording-add>
                    {{ __('lf.LF_course_cohort_recording_add') }}
                </button>
            @endif
        </div>
    </div>

    @if ($recordingAddable)
        <dialog x-ref="recordingDialog" class="ui-modal ui-cohort-select-modal" data-columns="2" aria-labelledby="cohort-recording-add-title"
                x-on:close="open = false" x-on:cancel="if (submitting) $event.preventDefault()">
            <form method="POST" x-bind:action="`${base}/${sessionId}/recordings`" class="ui-modal__form" x-ref="recordingForm" novalidate
                  data-recording-form x-on:submit="if (submitting) { $event.preventDefault(); return } submitting = true">
                @csrf
                <input type="hidden" name="session_id" x-bind:value="sessionId">
                <header class="ui-modal__header">
                    <div><h3 id="cohort-recording-add-title">{{ __('lf.LF_course_cohort_recording_add') }}</h3></div>
                    <button type="button" class="ui-modal__close cursor-interaction" x-on:click="open = false" :disabled="submitting" aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
                </header>
                <div class="ui-modal__body">
                    <div class="ui-modal__grid">
                        <div class="ui-field">
                            <label class="ui-field__label" for="recording_session_id"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_course_cohort_attendance_session') }}</label>
                            <select id="recording_session_id" data-ui-kit-select x-model="sessionId" required>
                                @foreach ($sessions as $session)
                                    <option value="{{ $session->id }}" @selected($recordingSessionId === (string) $session->id)>#{{ $session->session_no }} · {{ $session->title }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="ui-field">
                            <label class="ui-field__label" for="recording_title"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_course_cohort_recording_name') }}</label>
                            <input id="recording_title" type="text" name="title" value="{{ old('title') }}" maxlength="255" required
                                   @error('title') aria-invalid="true" @enderror>
                            @error('title')<p class="ui-field__error" role="alert" x-show="showErrors">{{ $message }}</p>@enderror
                        </div>
                        <div class="ui-field ui-field--full">
                            <label class="ui-field__label" for="recording_url"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_course_cohort_recording_url') }}</label>
                            <input id="recording_url" type="url" name="recording_url" value="{{ old('recording_url') }}" maxlength="2000" required placeholder="https://"
                                   @error('recording_url') aria-invalid="true" @enderror>
                            @error('recording_url')<p class="ui-field__error" role="alert" x-show="showErrors">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </div>
                <footer class="ui-modal__footer">
                    <span></span>
                    <button type="button" class="ui-button cursor-interaction" x-on:click="open = false" :disabled="submitting">{{ __('lf.LF_common_button_cancel') }}</button>
                    <button class="ui-button ui-button--primary cursor-interaction" type="submit" :disabled="submitting" :aria-busy="submitting">
                        <x-backend-icon name="check" class="ui-icon" />
                        {{ __('lf.LF_course_cohort_recording_add') }}
                    </button>
                </footer>
            </form>
        </dialog>
    @endif

    <div class="mk-table-scroll" role="region" aria-labelledby="cohort-recordings-title" tabindex="0">
        <table>
            <colgroup><col class="mk-col-sequence"><col class="ui-cohort-col-session"><col><col class="ui-cohort-col-role"><col class="ui-cohort-col-replay"></colgroup>
            <thead><tr>
                <th scope="col" class="mk-sequence">{{ __('lf.table_no') }}</th>
                <th scope="col" class="mk-text-cell">{{ __('lf.LF_course_cohort_attendance_session') }}</th>
                <th scope="col" class="mk-text-cell">{{ __('lf.LF_course_cohort_recording_name') }}</th>
                <th scope="col" class="mk-text-cell">{{ __('lf.LF_course_cohort_common_status') }}</th>
                <th scope="col" class="mk-text-cell">{{ __('lf.LF_course_cohort_recording_window') }}</th>
            </tr></thead>
            <tbody>
            @forelse ($recordings as $recording)
                <tr>
                    <td class="mk-sequence" data-label="{{ __('lf.table_no') }}">{{ $loop->iteration }}</td>
                    <td class="mk-text-cell" data-label="{{ __('lf.LF_course_cohort_attendance_session') }}">
                        <span class="mk-name-text">#{{ $recording->session_no }} · {{ $recording->session_title }}</span>
                    </td>
                    <td class="mk-text-cell" data-label="{{ __('lf.LF_course_cohort_recording_name') }}">
                        <span class="mk-name-text">{{ $recording->title }}</span>
                        @if ($recording->recording_url)
                            <a class="ui-caption ui-cohort-recording-link" href="{{ $recording->recording_url }}" target="_blank" rel="noopener noreferrer">{{ __('lf.LF_course_cohort_recording_open') }}</a>
                        @endif
                    </td>
                    <td class="mk-text-cell" data-label="{{ __('lf.LF_course_cohort_common_status') }}">
                        <span class="ui-status {{ $recordingTone((string) $recording->status) }}">{{ __('lf.LF_course_cohort_recording_status_'.$recording->status) }}</span>
                    </td>
                    <td class="mk-text-cell ui-enroll-nowrap" data-label="{{ __('lf.LF_course_cohort_recording_window') }}">
                        {{ $recordingTime($recording->replay_available_from) }}
                        <span aria-hidden="true">→</span>
                        {{ $recordingTime($recording->replay_available_until) }}
                    </td>
                </tr>
            @empty
                <tr class="mk-empty-row"><td class="mk-empty" colspan="5"><div role="status">
                    <strong>{{ __('lf.LF_course_cohort_recording_empty') }}</strong>
                    <p class="ui-caption">{{ __('lf.LF_course_cohort_recording_empty_help') }}</p>
                </div></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</section>
