{{-- The meeting link of a Session whose general edit has closed: it alone stays editable until the planned end, also
     while the Session is live (ADR-0002 § Teacher Session Operations Amendment). --}}
@php
    $linkDialogId = 'session-meeting-link-'.$session->id;
    $linkRetry = $errors->getBag('sessionMeetingLink')->isNotEmpty() && (int) old('meeting_link_session_id') === (int) $session->id;
    // A stored value that is not an http(s) link is never echoed back, not even into the field (it is re-entered).
    $linkSavedUrl = preg_match('#^https?://#i', (string) $session->meeting_url_snapshot) ? $session->meeting_url_snapshot : '';
@endphp
<span x-data="{ submitting: false }" x-init="if (@js($linkRetry)) $refs.linkDialog.showModal()">
    <button type="button" class="ux-row-edit cursor-interaction" aria-haspopup="dialog"
            aria-label="{{ __('lf.LF_liveclass_session_operations_link_edit') }}: {{ $session->title }}" data-tooltip="{{ __('lf.LF_liveclass_session_operations_link_edit') }}"
            x-on:click="$refs.linkDialog.showModal()">
        <x-backend-icon name="link" class="ui-icon" />
    </button>
    <dialog id="{{ $linkDialogId }}" x-ref="linkDialog" class="ui-modal" data-columns="1" aria-labelledby="{{ $linkDialogId }}-title"
            x-on:cancel="if (submitting) $event.preventDefault()">
        <form method="POST" action="{{ route($routePrefix.'.sessions.meeting-link', [$cohort->id, $session->id]) }}" class="ui-modal__form" novalidate
              x-on:submit="if (submitting) { $event.preventDefault(); return } submitting = true">
            @csrf @method('PUT')
            <input type="hidden" name="meeting_link_session_id" value="{{ $session->id }}">
            <header class="ui-modal__header">
                <div>
                    <h3 id="{{ $linkDialogId }}-title">{{ __('lf.LF_liveclass_session_operations_link_edit') }}</h3>
                    <p class="ui-caption">{{ $session->title }}</p>
                </div>
                <button type="button" class="ui-modal__close cursor-interaction" x-on:click="$refs.linkDialog.close()" :disabled="submitting" aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
            </header>
            <div class="ui-modal__body">
                <div class="ui-field">
                    <label class="ui-field__label" for="{{ $linkDialogId }}-url">{{ __('lf.LF_course_cohort_session_meeting_url') }}</label>
                    <input id="{{ $linkDialogId }}-url" type="url" name="meeting_url" inputmode="url" autocomplete="off" maxlength="2000"
                           value="{{ $linkRetry ? old('meeting_url') : $linkSavedUrl }}" placeholder="https://meet.google.com/abc-defg-hij">
                    <p class="ui-caption">{{ __('lf.LF_liveclass_session_operations_link_help') }}</p>
                    @if ($linkRetry) @error('meeting_url', 'sessionMeetingLink')<p class="ui-field__error" role="alert">{{ $message }}</p>@enderror @endif
                </div>
            </div>
            <footer class="ui-modal__footer">
                <span></span>
                <button type="button" class="ui-button cursor-interaction" x-on:click="$refs.linkDialog.close()" :disabled="submitting">{{ __('lf.LF_common_button_cancel') }}</button>
                <button type="submit" class="ui-button ui-button--primary cursor-interaction" :disabled="submitting">
                    <x-backend-icon name="check" class="ui-icon" />{{ __('lf.LF_liveclass_session_operations_save') }}
                </button>
            </footer>
        </form>
    </dialog>
</span>
