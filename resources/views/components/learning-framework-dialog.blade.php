@props(['title', 'action', 'help' => null])

{{-- One Learning Framework popup form (admin-ui-kit-dialog-form.js): a PUT when `method` is passed, otherwise a POST. --}}
<form {{ $attributes->merge(['class' => 'ui-modal__form']) }} method="POST" novalidate action="{{ $action }}">
    @csrf
    {{ $hidden ?? '' }}
    <header class="ui-modal__header">
        <div>
            <h3 id="framework-dialog-title">{{ $title }}</h3>
            @if ($help)
                <p class="ui-caption">{{ $help }}</p>
            @endif
        </div>
        <button class="ui-modal__close cursor-interaction" type="button" data-ui-dialog-form-cancel
                aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
    </header>
    <div class="ui-modal__body">
        {{ $slot }}
    </div>
    <footer class="ui-modal__footer">
        <span class="ui-caption ui-field__error" role="alert" data-ui-dialog-form-feedback></span>
        <button type="button" class="ui-button cursor-interaction" data-ui-dialog-form-cancel>{{ __('lf.LF_common_button_cancel') }}</button>
        <button type="submit" class="ui-button ui-button--primary cursor-interaction" data-ui-dialog-form-submit>
            <x-backend-icon name="check" class="ui-icon" />
            {{ __('lf.LF_common_button_save') }}
        </button>
    </footer>
</form>
