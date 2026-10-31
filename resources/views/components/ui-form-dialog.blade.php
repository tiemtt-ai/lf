{{-- One UI Kit form dialog fragment: title, body slot, footer with Cancel + Confirm. --}}
@props(['action', 'title', 'titleId' => 'ui-form-dialog-title', 'method' => 'PATCH', 'submitLabel' => null])

<form class="ui-modal__form" method="POST" novalidate action="{{ $action }}">
    @csrf
    @unless (strtoupper($method) === 'POST')
        @method($method)
    @endunless
    <header class="ui-modal__header">
        <div><h3 id="{{ $titleId }}">{{ $title }}</h3></div>
        <button class="ui-modal__close cursor-interaction" type="button" data-ui-dialog-form-cancel
                aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
    </header>
    <div class="ui-modal__body">{{ $slot }}</div>
    <footer class="ui-modal__footer">
        <span class="ui-caption ui-field__error" role="alert" data-ui-dialog-form-feedback></span>
        <button type="button" class="ui-button cursor-interaction" data-ui-dialog-form-cancel>{{ __('lf.LF_common_button_cancel') }}</button>
        <button type="submit" class="ui-button ui-button--primary cursor-interaction" data-ui-dialog-form-submit>
            <x-backend-icon name="check" class="ui-icon" />
            {{ $submitLabel ?? __('lf.LF_common_button_confirm') }}
        </button>
    </footer>
</form>
