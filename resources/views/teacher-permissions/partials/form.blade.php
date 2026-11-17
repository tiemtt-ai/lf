{{-- Create or edit popup of one permission group: its name and the permissions on offer (ADR-0033 D3). --}}
@php
    $errorBag = $errors->getBag($bag);
    $retry = $errorBag->isNotEmpty();
    $name = $retry ? old('name', '') : ($group?->name ?? '');
    $keys = $retry ? (array) old('keys', []) : ($group ? $group->keys : \App\Services\TeacherPermissions::DEFAULT_KEYS);
    $action = $group ? route('admin.teacher-permissions.update', $group->id) : route('admin.teacher-permissions.store');
    $closeUrl = route('admin.teacher-permissions.index');
@endphp
<dialog id="{{ $dialogId }}" class="ui-modal ui-teacher-permission-modal" data-columns="2" aria-labelledby="{{ $dialogId }}-title"
        x-data="{ submitting: false }"
        @if ($autoOpen) x-init="$nextTick(() => $el.showModal())" @endif
        @if ($group) x-on:close="window.location.assign(@js($closeUrl))" @endif
        x-on:cancel="if (submitting) $event.preventDefault()">
    <form method="POST" action="{{ $action }}" class="ui-modal__form" novalidate
          x-on:submit="if (submitting) { $event.preventDefault(); return } submitting = true">
        @csrf
        @if ($group) @method('PUT') @endif
        <header class="ui-modal__header">
            <div>
                <h3 id="{{ $dialogId }}-title">{{ $group ? __('lf.LF_teacher_permission_group_edit') : __('lf.LF_teacher_permission_group_add') }}</h3>
                @if ($group?->is_default)<p class="ui-caption">{{ __('lf.LF_teacher_permission_group_default_help') }}</p>@endif
            </div>
            <button type="button" class="ui-modal__close cursor-interaction" x-on:click="$root.close()" :disabled="submitting" aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
        </header>
        <div class="ui-modal__body">
            <div class="ui-field">
                <label class="ui-field__label" for="{{ $dialogId }}-name"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_teacher_permission_group_field_name') }}</label>
                <input id="{{ $dialogId }}-name" name="name" maxlength="150" required value="{{ $name }}" placeholder="{{ __('lf.LF_teacher_permission_group_name_placeholder') }}">
                @if ($errorBag->first('name'))<p class="ui-field__error" role="alert">{{ $errorBag->first('name') }}</p>@endif
            </div>
            <fieldset class="ui-field ui-teacher-permission-keys">
                <legend class="ui-field__label">{{ __('lf.LF_teacher_permission_col_permissions') }}</legend>
                {{-- One box per area of work, in the order of the menu. --}}
                @foreach (\App\Services\TeacherPermissions::AREAS as $area => $areaKeys)
                    @php $areaKeys = array_values(array_intersect($areaKeys, \App\Services\TeacherPermissions::AVAILABLE)); @endphp
                    @continue($areaKeys === [])
                    <div class="ui-teacher-permission-area" role="group" aria-labelledby="{{ $dialogId }}-area-{{ $area }}">
                        <h4 id="{{ $dialogId }}-area-{{ $area }}">{{ __('lf.LF_teacher_permission_area_'.$area) }}</h4>
                        <div class="ui-teacher-permission-area__rows">
                            @foreach ($areaKeys as $key)
                                @php $slug = str_replace('.', '_', $key); @endphp
                                <label class="ui-switch-row">
                                    <span class="ui-switch-row__text">
                                        <b>{{ __('lf.LF_teacher_permission_key_'.$slug) }}</b>
                                        <small>{{ __('lf.LF_teacher_permission_help_'.$slug) }}</small>
                                    </span>
                                    <span class="ui-switch">
                                        <input type="checkbox" name="keys[]" value="{{ $key }}" @checked(in_array($key, $keys, true))>
                                        <span></span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endforeach
                @if ($errorBag->first('keys.*') ?: $errorBag->first('keys'))<p class="ui-field__error" role="alert">{{ $errorBag->first('keys.*') ?: $errorBag->first('keys') }}</p>@endif
            </fieldset>
        </div>
        <footer class="ui-modal__footer">
            <span></span>
            <button type="button" class="ui-button cursor-interaction" x-on:click="$root.close()" :disabled="submitting">{{ __('lf.LF_common_button_cancel') }}</button>
            <button type="submit" class="ui-button ui-button--primary cursor-interaction" :disabled="submitting">
                <x-backend-icon name="check" class="ui-icon" />
                {{ $group ? __('lf.LF_teacher_permission_group_save') : __('lf.LF_teacher_permission_group_add') }}
            </button>
        </footer>
    </form>
</dialog>
