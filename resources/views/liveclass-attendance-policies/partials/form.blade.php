{{-- Create or edit popup of one attendance policy. The make-up settings are stored but not applied yet (Owner 2026-11-17), so they are carried hidden. --}}
@php
    $errorBag = $errors->getBag($bag);
    $retry = $errorBag->isNotEmpty();
    $value = fn (string $field, $default) => $retry ? old($field, $default) : ($policy ? $policy->{$field} : $default);
    $absentUnlimited = $retry ? (bool) old('absent_unlimited') : ($policy ? $policy->absent_after_minutes === null : false);
    $makeupUnlimited = $retry ? (bool) old('makeup_unlimited') : ($policy ? $policy->makeup_within_days === null : true);
    $checked = fn (string $field, bool $default): bool => (bool) ($retry ? old($field) : ($policy ? $policy->{$field} : $default));
    $action = $policy
        ? route('admin.attendance-policies.update', $policy->id)
        : route('admin.attendance-policies.store');
    $closeUrl = route('admin.attendance-policies.index');
    $error = fn (string $field) => $errorBag->first($field);
@endphp
<dialog id="{{ $dialogId }}" class="ui-modal ui-attendance-policy-modal" data-columns="2" aria-labelledby="{{ $dialogId }}-title"
        x-data="{ submitting: false, absentUnlimited: @js($absentUnlimited) }"
        @if ($autoOpen) x-init="$nextTick(() => $el.showModal())" @endif
        @if ($policy) x-on:close="window.location.assign(@js($closeUrl))" @endif
        x-on:cancel="if (submitting) $event.preventDefault()">
    <form method="POST" action="{{ $action }}" class="ui-modal__form" novalidate
          x-on:submit="if (submitting) { $event.preventDefault(); return } submitting = true">
        @csrf
        @if ($policy) @method('PUT') @endif
        <header class="ui-modal__header">
            <div>
                <h3 id="{{ $dialogId }}-title">{{ $policy ? __('lf.LF_attendance_policy_edit') : __('lf.LF_attendance_policy_add') }}</h3>
                @if ($policy?->is_default)<p class="ui-caption">{{ __('lf.LF_attendance_policy_default_help') }}</p>@endif
            </div>
            <button type="button" class="ui-modal__close cursor-interaction" x-on:click="$root.close()" :disabled="submitting" aria-label="{{ __('lf.LF_common_button_close') }}">×</button>
        </header>
        <div class="ui-modal__body">
            <div class="ui-modal__grid">
                <div class="ui-field ui-field--full">
                    <label class="ui-field__label" for="{{ $dialogId }}-name"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_attendance_policy_field_name') }}</label>
                    <input id="{{ $dialogId }}-name" name="name" maxlength="150" required value="{{ $value('name', '') }}" placeholder="{{ __('lf.LF_attendance_policy_name_placeholder') }}">
                    @if ($error('name'))<p class="ui-field__error" role="alert">{{ $error('name') }}</p>@endif
                </div>

                @foreach ([['opens_before_minutes', 15, 240], ['late_after_minutes', 10, 240]] as [$field, $default, $max])
                    <div class="ui-field">
                        <label class="ui-field__label" for="{{ $dialogId }}-{{ $field }}"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_attendance_policy_field_'.$field) }}</label>
                        <div class="ui-input-group">
                            <input id="{{ $dialogId }}-{{ $field }}" type="number" inputmode="numeric" name="{{ $field }}" min="0" max="{{ $max }}" step="1" required value="{{ $value($field, $default) }}">
                            <span class="ui-input-group__addon">{{ __('lf.LF_attendance_policy_unit_minutes') }}</span>
                        </div>
                        <p class="ui-caption">{{ __('lf.LF_attendance_policy_help_'.$field) }}</p>
                        @if ($error($field))<p class="ui-field__error" role="alert">{{ $error($field) }}</p>@endif
                    </div>
                @endforeach

                <div class="ui-field">
                    <label class="ui-field__label" for="{{ $dialogId }}-absent_after_minutes"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_attendance_policy_field_absent_after_minutes') }}</label>
                    <div class="ui-input-group">
                        <input id="{{ $dialogId }}-absent_after_minutes" type="number" inputmode="numeric" name="absent_after_minutes" min="0" max="480" step="1"
                               value="{{ $value('absent_after_minutes', 30) }}" x-bind:disabled="absentUnlimited">
                        <span class="ui-input-group__addon">{{ __('lf.LF_attendance_policy_unit_minutes') }}</span>
                    </div>
                    <label class="ui-check">
                        <input type="checkbox" name="absent_unlimited" value="1" x-model="absentUnlimited">
                        <span>{{ __('lf.LF_attendance_policy_absent_unlimited') }}</span>
                    </label>
                    <p class="ui-caption">{{ __('lf.LF_attendance_policy_help_absent_after_minutes') }}</p>
                    @if ($error('absent_after_minutes'))<p class="ui-field__error" role="alert">{{ $error('absent_after_minutes') }}</p>@endif
                </div>
                <div class="ui-field">
                    <label class="ui-field__label" for="{{ $dialogId }}-auto_absent_after_minutes"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_attendance_policy_field_auto_absent_after_minutes') }}</label>
                    <div class="ui-input-group">
                        <input id="{{ $dialogId }}-auto_absent_after_minutes" type="number" inputmode="numeric" name="auto_absent_after_minutes" min="0" max="1440" step="1" required value="{{ $value('auto_absent_after_minutes', 30) }}">
                        <span class="ui-input-group__addon">{{ __('lf.LF_attendance_policy_unit_minutes') }}</span>
                    </div>
                    <p class="ui-caption">{{ __('lf.LF_attendance_policy_help_auto_absent_after_minutes') }}</p>
                    @if ($error('auto_absent_after_minutes'))<p class="ui-field__error" role="alert">{{ $error('auto_absent_after_minutes') }}</p>@endif
                </div>

                <label class="ui-switch-row ui-switch-row--boxed ui-field--full">
                    <span class="ui-switch-row__text">
                        <b>{{ __('lf.LF_attendance_policy_field_count_from_actual_start') }}</b>
                        <small>{{ __('lf.LF_attendance_policy_help_count_from_actual_start') }}</small>
                    </span>
                    <span class="ui-switch">
                        <input type="hidden" name="count_from_actual_start" value="0">
                        <input type="checkbox" name="count_from_actual_start" value="1" @checked($checked('count_from_actual_start', true))>
                        <span></span>
                    </span>
                </label>

                @foreach (['offline_recording', 'online_recording'] as $field)
                    <div class="ui-field">
                        <label class="ui-field__label" for="{{ $dialogId }}-{{ $field }}"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_attendance_policy_field_'.$field) }}</label>
                        <select id="{{ $dialogId }}-{{ $field }}" name="{{ $field }}" data-ui-kit-select required>
                            @foreach (\App\Services\LiveClassAttendancePolicies::RECORDING_MODES as $mode)
                                <option value="{{ $mode }}" @selected($value($field, 'system') === $mode)>{{ __('lf.LF_attendance_policy_recording_'.$mode) }}</option>
                            @endforeach
                        </select>
                        <p class="ui-caption">{{ __('lf.LF_attendance_policy_help_'.$field) }}</p>
                        @if ($error($field))<p class="ui-field__error" role="alert">{{ $error($field) }}</p>@endif
                    </div>
                @endforeach

                <div class="ui-field">
                    <label class="ui-field__label" for="{{ $dialogId }}-teacher_edit_days"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_attendance_policy_field_teacher_edit_days') }}</label>
                    <div class="ui-input-group">
                        <input id="{{ $dialogId }}-teacher_edit_days" type="number" inputmode="numeric" name="teacher_edit_days" min="0" max="365" step="1" required value="{{ $value('teacher_edit_days', 7) }}">
                        <span class="ui-input-group__addon">{{ __('lf.LF_attendance_policy_unit_days') }}</span>
                    </div>
                    <p class="ui-caption">{{ __('lf.LF_attendance_policy_help_teacher_edit_days') }}</p>
                    @if ($error('teacher_edit_days'))<p class="ui-field__error" role="alert">{{ $error('teacher_edit_days') }}</p>@endif
                </div>
                {{-- The make-up settings stay stored but are hidden until make-up classes exist (Owner 2026-11-18). --}}
                <input type="hidden" name="makeup_enabled" value="{{ $checked('makeup_enabled', true) ? 1 : 0 }}">
                <input type="hidden" name="makeup_unlimited" value="{{ $makeupUnlimited ? 1 : 0 }}">
                <input type="hidden" name="makeup_within_days" value="{{ $makeupUnlimited ? '' : $value('makeup_within_days', '') }}">
            </div>
        </div>
        <footer class="ui-modal__footer">
            <span></span>
            <button type="button" class="ui-button cursor-interaction" x-on:click="$root.close()" :disabled="submitting">{{ __('lf.LF_common_button_cancel') }}</button>
            <button type="submit" class="ui-button ui-button--primary cursor-interaction" :disabled="submitting">
                <x-backend-icon name="check" class="ui-icon" />
                {{ $policy ? __('lf.LF_attendance_policy_save') : __('lf.LF_attendance_policy_add') }}
            </button>
        </footer>
    </form>
</dialog>
