{{-- UI Kit password field with a show/hide eye button; extra attributes go to the input. --}}
@props(['id', 'name'])
<div class="ui-password" x-data="{ shown: false }">
    <input id="{{ $id }}" name="{{ $name }}" type="password" x-bind:type="shown ? 'text' : 'password'" {{ $attributes }}>
    <button type="button" class="ui-password__toggle cursor-interaction" x-on:click="shown = ! shown"
            aria-controls="{{ $id }}" x-bind:aria-pressed="shown.toString()"
            aria-label="{{ __('lf.LF_auth_login_show_password') }}"
            x-bind:aria-label="shown ? @js(__('lf.LF_auth_login_hide_password')) : @js(__('lf.LF_auth_login_show_password'))">
        <span x-show="! shown"><x-backend-icon name="eye" class="ui-icon" /></span>
        <span x-show="shown" x-cloak><x-backend-icon name="eye-off" class="ui-icon" /></span>
    </button>
</div>
