{{-- A teacher edits the operational part of a Session they teach: meeting link, room, address, attendance tool
     (ADR-0002 § Teacher Session Operations Amendment). Only the fields the Session lets through now are shown. --}}
@php
    $opsId = 'session-operations-'.$session->id;
    $opsBag = $errors->getBag('sessionOperations');
    $opsRetry = $opsBag->isNotEmpty() && (int) old('operations_session_id') === (int) $session->id;
    $opsValue = fn (string $field, $saved) => $opsRetry ? old($field) : $saved;
    // A stored value that is not an http(s) link is never echoed back, not even into the field (it is re-entered).
    $opsSavedUrl = preg_match('#^https?://#i', (string) $session->meeting_url_snapshot) ? $session->meeting_url_snapshot : '';
    // A practice session names a room or a place and has no attendance tool to choose (ADR-0022 Amendment 1.3, Đợt 4).
    $opsPractice = (bool) ($session->is_practice ?? false);
@endphp
<span x-data="{ submitting: false }" x-init="if (@js($opsRetry)) $refs.opsDialog.showModal()">
    <button type="button" class="ui-button cursor-interaction" aria-haspopup="dialog" x-on:click="$refs.opsDialog.showModal()" data-session-edit>
        <x-backend-icon name="square-pen" class="ui-icon" />{{ __('lf.LF_common_button_edit') }}
    </button>
    <dialog id="{{ $opsId }}" x-ref="opsDialog" class="ui-modal" data-columns="2" aria-labelledby="{{ $opsId }}-title"
            x-on:cancel="if (submitting) $event.preventDefault()">
        <form method="POST" action="{{ route('teacher.live-sessions.update', $session->id) }}" class="ui-modal__form" novalidate
              x-on:submit="if (submitting) { $event.preventDefault(); return } submitting = true">
            @csrf @method('PUT')
            <input type="hidden" name="operations_session_id" value="{{ $session->id }}">
            <header class="ui-modal__header">
                <div>
                    <h3 id="{{ $opsId }}-title">{{ __('lf.LF_liveclass_session_operations_edit') }}</h3>
                    <p class="ui-caption">{{ $session->title }} · {{ $session->cohort_name }}</p>
                </div>
                <button type="button" class="ui-modal__close cursor-interaction" x-on:click="$refs.opsDialog.close()" :disabled="submitting" aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
            </header>
            <div class="ui-modal__body">
                <div class="ui-modal__grid">
                    <p class="ui-caption ui-field--full">{{ __('lf.LF_liveclass_session_operations_edit_help') }}</p>
                    @if ($session->can_edit_link)
                        <div class="ui-field ui-field--full">
                            <label class="ui-field__label" for="{{ $opsId }}-url">{{ __('lf.LF_course_cohort_session_meeting_url') }}</label>
                            <input id="{{ $opsId }}-url" type="url" name="meeting_url" inputmode="url" autocomplete="off" maxlength="2000"
                                   value="{{ $opsValue('meeting_url', $opsSavedUrl) }}" placeholder="https://meet.google.com/abc-defg-hij">
                            <p class="ui-caption">{{ __('lf.LF_liveclass_session_operations_link_help') }}</p>
                            @if ($opsRetry && $opsBag->has('meeting_url'))<p class="ui-field__error" role="alert">{{ $opsBag->first('meeting_url') }}</p>@endif
                        </div>
                    @endif
                    @if ($session->can_edit_place)
                        <div class="ui-field">
                            <label class="ui-field__label" for="{{ $opsId }}-room"><span class="ui-field__required" aria-hidden="true">*</span>{{ __($opsPractice ? 'lf.LF_course_cohort_session_place' : 'lf.LF_course_cohort_session_room') }}</label>
                            <input id="{{ $opsId }}-room" name="room_name" maxlength="255" required value="{{ $opsValue('room_name', $session->room_name_snapshot) }}"
                                   placeholder="{{ __($opsPractice ? 'lf.LF_course_cohort_session_place_placeholder' : 'lf.LF_course_cohort_session_room_placeholder') }}">
                            @if ($opsRetry && $opsBag->has('room_name'))<p class="ui-field__error" role="alert">{{ $opsBag->first('room_name') }}</p>@endif
                        </div>
                        <div class="ui-field">
                            <label class="ui-field__label" for="{{ $opsId }}-address">{{ __('lf.LF_course_cohort_session_address') }}</label>
                            <input id="{{ $opsId }}-address" name="address" maxlength="2000" value="{{ $opsValue('address', $session->address_snapshot) }}"
                                   placeholder="{{ __('lf.LF_course_cohort_session_address_placeholder') }}">
                        </div>
                        @unless ($opsPractice)
                        <div class="ui-field ui-field--full">
                            <label class="ui-field__label" for="{{ $opsId }}-tool">{{ __('lf.LF_course_cohort_session_attendance_tool') }}</label>
                            <select id="{{ $opsId }}-tool" name="attendance_tool">
                                @foreach (\App\Services\LiveClassSessionOperations::ATTENDANCE_TOOLS as $tool)
                                    <option value="{{ $tool }}" @selected($opsValue('attendance_tool', $session->attendance_tool ?: 'board') === $tool)>{{ __('lf.LF_course_cohort_session_attendance_tool_'.$tool) }}</option>
                                @endforeach
                            </select>
                            <p class="ui-caption">{{ __('lf.LF_course_cohort_session_attendance_tool_help') }}</p>
                        </div>
                        @endunless
                    @elseif ($session->delivery_mode !== 'online')
                        <p class="ui-caption ui-field--full" role="status">{{ __('lf.LF_liveclass_session_operations_place_locked') }}</p>
                    @endif
                </div>
            </div>
            <footer class="ui-modal__footer">
                <span></span>
                <button type="button" class="ui-button cursor-interaction" x-on:click="$refs.opsDialog.close()" :disabled="submitting">{{ __('lf.LF_common_button_cancel') }}</button>
                <button type="submit" class="ui-button ui-button--primary cursor-interaction" :disabled="submitting">
                    <x-backend-icon name="check" class="ui-icon" />{{ __('lf.LF_liveclass_session_operations_save') }}
                </button>
            </footer>
        </form>
    </dialog>
</span>
