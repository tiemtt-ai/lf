{{-- UI Kit list result messages: success toast (also shows session('success')) and a danger toast filled by JS. --}}
<div class="ui-toast-region" data-ui-toast-region>
    <div class="ui-toast ui-toast--success" role="status" data-ui-toast data-ui-toast-timeout="4000" data-ui-dialog-form-flash @unless (session('success')) hidden @endunless>
        <span class="ui-toast__text" data-ui-toast-text>{{ session('success') }}</span>
        <button type="button" class="ui-toast__close cursor-interaction" data-ui-toast-close aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
    </div>
    <div class="ui-toast ui-toast--danger" role="alert" data-ui-toast data-ui-toast-timeout="0" data-ui-dialog-form-page-error hidden>
        <span class="ui-toast__text" data-ui-toast-text></span>
        <button type="button" class="ui-toast__close cursor-interaction" data-ui-toast-close aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
    </div>
</div>
