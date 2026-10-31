{{--
    Account page body for the admin and the teacher: a profile summary with how complete it is, the personal information
    (read-only, edited in place), account security (change-password popup) and the interface language.
    $emailEditable: teachers change their email, admins do not.
--}}
@php
    $emailEditable ??= false;
    $genderLabels = [
        'male' => __('lf.LF_common_gender_common_male'),
        'female' => __('lf.LF_common_gender_common_female'),
        'other' => __('lf.LF_common_gender_common_other'),
    ];
    $birth = $user->date_of_birth ? \Illuminate\Support\Carbon::parse($user->date_of_birth) : null;
    $registered = $user->created_at ? \Illuminate\Support\Carbon::parse($user->created_at) : null;
    $fields = [
        ['name', __('lf.LF_common_label_name'), $user->name],
        ['email', __('lf.LF_common_label_email'), $user->email],
        ['phone', __('lf.LF_profile_label_admin_phone'), $user->phone],
        ['date_of_birth', __('lf.LF_profile_label_admin_birth_date'), $birth?->format('d/m/Y')],
        ['gender', __('lf.LF_profile_label_admin_gender'), $genderLabels[$user->gender] ?? null],
        ['registered', rtrim(__('lf.LF_profile_label_admin_registration_date'), ': '), $registered?->format('d/m/Y')],
    ];
    $filled = collect($fields)->filter(fn (array $field) => filled($field[2]));
    $missing = collect($fields)->reject(fn (array $field) => filled($field[2]))
        ->map(fn (array $field) => mb_strtolower($field[1]))->values();
    $completion = (int) round($filled->count() / count($fields) * 100);
    $initials = collect(preg_split('/\s+/u', trim((string) $user->name)) ?: [])
        ->filter()->map(fn (string $word) => mb_strtoupper(mb_substr($word, 0, 1)))->take(2)->implode('');
    $locale = app()->getLocale();
@endphp
<div class="lf-admin-ui lf-admin-ui--dashboard ui-account" data-ui-dialog-form-scope
     x-data="{
         editing: @js($errors->any()),
         saving: false,
         errors: {},
         message: '',
         startEdit(field) {
             this.editing = true;
             this.$nextTick(() => {
                 const id = 'profile-form-' + (field || 'name');
                 (document.getElementById(id + '-ui-kit') || document.getElementById(id))?.focus();
             });
         },
         stopEdit() { this.editing = false; this.errors = {}; this.message = ''; this.$refs.form.reset(); },
         async save() {
             if (this.saving) return;
             this.saving = true;
             this.errors = {};
             this.message = '';
             try {
                 const response = await fetch(this.$refs.form.action, {
                     method: 'POST',
                     body: new FormData(this.$refs.form),
                     headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                     credentials: 'same-origin',
                 });
                 const payload = await response.json().catch(() => ({}));
                 if (response.ok) { window.location.assign(payload.redirect || window.location.href); return; }
                 if (response.status === 422) this.errors = payload.errors || {};
                 else this.message = @js(__('lf.LF_course_template_dialog_save_error'));
             } catch (error) {
                 this.message = @js(__('lf.LF_course_template_dialog_save_error'));
             }
             this.saving = false;
         },
     }">
    <x-ui-list-toasts />

    <section class="ui-account-hero" aria-label="{{ __('lf.LF_account_overview') }}">
        <span class="ui-account-hero__avatar" aria-hidden="true">{{ $initials ?: '?' }}</span>
        <div class="ui-account-hero__identity">
            <h2>{{ $user->name }}</h2>
            <div class="ui-account-hero__meta">
                <span><x-backend-icon name="mail" class="ui-icon" />{{ $user->email }}</span>
                @if ($registered)
                    <span><x-backend-icon name="calendar" class="ui-icon" />{{ __('lf.LF_account_member_since', ['date' => $registered->format('d/m/Y')]) }}</span>
                @endif
            </div>
        </div>
        <div class="ui-account-hero__completion">
            <div class="ui-account-hero__completion-head">
                <span>{{ __('lf.LF_account_completion') }}</span>
                <strong>{{ __('lf.LF_account_completion_count', ['filled' => $filled->count(), 'total' => count($fields)]) }}</strong>
            </div>
            <div class="ui-account-progress" role="progressbar" aria-label="{{ __('lf.LF_account_completion') }}"
                 aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $completion }}">
                <span style="width: {{ $completion }}%"></span>
            </div>
            @if ($missing->isNotEmpty())
                <p class="ui-caption">{!! __('lf.LF_account_completion_missing', ['fields' => '<strong>'.e($missing->implode(' '.__('lf.LF_account_and').' ')).'</strong>']) !!}</p>
            @endif
        </div>
    </section>

    <div class="ui-account-columns">
        <section class="ui-account-card ui-account-card--main" aria-labelledby="account-info-title">
            <header class="ui-account-card__head">
                <div>
                    <h2 id="account-info-title">{{ __('lf.LF_profile_group_admin_personal') }}</h2>
                    <p class="ui-caption">{{ __('lf.LF_account_personal_help') }}</p>
                </div>
                <button type="button" class="ui-button cursor-interaction" x-show="! editing" x-on:click="startEdit()">
                    <x-backend-icon name="square-pen" class="ui-icon" />{{ __('lf.LF_common_button_edit') }}
                </button>
            </header>

            <dl class="ui-account-fields" x-show="! editing">
                @foreach ($fields as [$key, $label, $value])
                    <div>
                        <dt>{{ $label }}</dt>
                        @if (filled($value))
                            <dd>{{ $value }}</dd>
                        @else
                            <dd class="ui-account-fields__empty">
                                <span>{{ __('lf.LF_account_not_set') }}</span>
                                <button type="button" class="ui-account-add cursor-interaction" x-on:click="startEdit('{{ $key === 'date_of_birth' ? 'birth' : $key }}')">+ {{ __('lf.LF_account_add') }}</button>
                            </dd>
                        @endif
                    </div>
                @endforeach
            </dl>

            <form class="ui-account-form" x-ref="form" x-show="editing" x-cloak novalidate method="POST" action="{{ $updateUrl }}" x-on:submit.prevent="save()">
                @csrf
                @method('PATCH')
                <input type="hidden" name="return" value="page">
                <div class="ui-account-form__grid">
                    <div class="ui-field">
                        <label class="ui-field__label" for="profile-form-name"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_common_label_name') }}</label>
                        <input id="profile-form-name" type="text" name="name" value="{{ old('name', $user->name) }}" maxlength="255" required>
                        <span class="ui-field__error" role="alert" x-show="errors.name" x-text="errors.name?.[0]"></span>
                    </div>
                    <div class="ui-field">
                        <label class="ui-field__label" for="profile-form-email">@if ($emailEditable)<span class="ui-field__required" aria-hidden="true">*</span>@endif{{ __('lf.LF_common_label_email') }}</label>
                        @if ($emailEditable)
                            <input id="profile-form-email" type="email" name="email" value="{{ old('email', $user->email) }}" maxlength="255" required>
                            <span class="ui-field__error" role="alert" x-show="errors.email" x-text="errors.email?.[0]"></span>
                        @else
                            <input id="profile-form-email" type="email" value="{{ $user->email }}" readonly disabled aria-describedby="profile-form-email-help">
                            <span class="ui-caption" id="profile-form-email-help">{{ __('lf.LF_admin_dialog_email_readonly_help') }}</span>
                        @endif
                    </div>
                    <div class="ui-field">
                        <label class="ui-field__label" for="profile-form-phone">{{ __('lf.LF_profile_label_admin_phone') }}</label>
                        <input id="profile-form-phone" type="tel" name="phone" value="{{ old('phone', $user->phone) }}" maxlength="30" placeholder="0987 654 321">
                        <span class="ui-field__error" role="alert" x-show="errors.phone" x-text="errors.phone?.[0]"></span>
                    </div>
                    <div class="ui-field">
                        <label class="ui-field__label" for="profile-form-birth">{{ __('lf.LF_profile_label_admin_birth_date') }}</label>
                        <x-ui-date-input id="profile-form-birth" name="date_of_birth" :value="$birth?->format('Y-m-d')" :max="now()->format('Y-m-d')" />
                        <span class="ui-field__error" role="alert" x-show="errors.date_of_birth" x-text="errors.date_of_birth?.[0]"></span>
                    </div>
                    <div class="ui-field">
                        <label class="ui-field__label" for="profile-form-gender">{{ __('lf.LF_profile_label_admin_gender') }}</label>
                        <select id="profile-form-gender" name="gender" data-ui-kit-select data-ui-empty-label="{{ __('lf.LF_course_category_index_no_options') }}" data-ui-clear-label="{{ __('lf.LF_common_gender_common_select') }}">
                            <option value="">{{ __('lf.LF_common_gender_common_select') }}</option>
                            @foreach ($genderLabels as $value => $label)
                                <option value="{{ $value }}" @selected($user->gender === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="ui-field">
                        <span class="ui-field__label">{{ rtrim(__('lf.LF_profile_label_admin_registration_date'), ': ') }}</span>
                        <span class="ui-account-form__static">{{ $registered?->format('d/m/Y') ?? '—' }}</span>
                    </div>
                </div>
                <footer class="ui-account-form__actions">
                    <span class="ui-field__error" role="alert" x-show="message" x-text="message"></span>
                    <button type="button" class="ui-button cursor-interaction" x-on:click="stopEdit()">{{ __('lf.LF_common_button_cancel') }}</button>
                    <button type="submit" class="ui-button ui-button--primary cursor-interaction" x-bind:disabled="saving" x-bind:aria-busy="saving.toString()">{{ __('lf.LF_common_button_save_changes') }}</button>
                </footer>
            </form>
        </section>

        <div class="ui-account-side">
            <section class="ui-account-card" aria-labelledby="account-security-title">
                <header class="ui-account-card__head">
                    <h2 id="account-security-title">{{ __('lf.LF_profile_group_admin_security') }}</h2>
                </header>
                <div class="ui-account-rows">
                    <div class="ui-account-row">
                        <span class="ui-account-row__icon" aria-hidden="true"><x-backend-icon name="lock" class="ui-icon" /></span>
                        <span class="ui-account-row__text">
                            <b>{{ __('lf.LF_account_password') }}</b>
                            <small>{{ __('lf.LF_account_password_help') }}</small>
                        </span>
                        <button type="button" class="ui-button cursor-interaction" data-ui-dialog-form-open data-url="{{ $passwordUrl }}" aria-haspopup="dialog">
                            {{ __('lf.LF_common_button_common_change_password') }}
                        </button>
                    </div>
                    <div class="ui-account-row">
                        <span class="ui-account-row__icon ui-account-row__icon--success" aria-hidden="true"><x-backend-icon name="mail" class="ui-icon" /></span>
                        <span class="ui-account-row__text">
                            <b>{{ __('lf.LF_account_login_email') }}</b>
                            <small>{{ $user->email }}</small>
                        </span>
                    </div>
                </div>
            </section>

            <section class="ui-account-card" aria-labelledby="account-preferences-title">
                <header class="ui-account-card__head">
                    <h2 id="account-preferences-title">{{ __('lf.LF_account_preferences') }}</h2>
                </header>
                <form class="ui-account-card__body" method="POST" x-ref="languageForm"
                      x-on:change="$refs.languageForm.action = @js(route('language.update', ['locale' => '__L__'])).replace('__L__', $event.target.value); $refs.languageForm.submit()">
                    @csrf
                    <div class="ui-field">
                        <label class="ui-field__label" for="account-language">{{ __('lf.LF_account_interface_language') }}</label>
                        <select id="account-language">
                            <option value="vi" @selected($locale === 'vi')>Tiếng Việt</option>
                            <option value="en" @selected($locale === 'en')>English</option>
                        </select>
                    </div>
                </form>
            </section>
        </div>
    </div>

    <x-ui-form-dialog-host title-id="dashboard-dialog-title" />
</div>
