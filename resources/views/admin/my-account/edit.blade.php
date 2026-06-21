@extends('layouts.backend')

@section('title', __('lf.LF_profile_title_admin_my_account'))
@section('page_title', __('lf.LF_profile_title_admin_my_account'))

@section('content')
    @php
        $birthDate = old('date_of_birth', $user->date_of_birth);
        $birth = $birthDate ? \Illuminate\Support\Carbon::parse($birthDate) : null;
    @endphp

    @if (session('profile_success'))
        <div class="admin-alert admin-alert-success">
            {{ session('profile_success') }}
        </div>
    @endif

    @if ($errors->default->any())
        <div class="admin-alert admin-alert-danger">
            <ul>
                @foreach ($errors->default->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <h2 class="sr-only">{{ __('lf.LF_profile_title_common_information') }}</h2>

    <form method="POST" action="{{ route('admin.my-account.update') }}">
        @csrf
        @method('PATCH')

        <table class="admin-form-grid">
            <tbody>
            <tr>
                <td class="admin-form-label">{{ __('lf.LF_profile_label_admin_username') }}</td>
                <td>{{ $user->name }}</td>
                <td class="admin-form-label">{{ __('lf.LF_profile_label_admin_registration_date') }}</td>
                <td>{{ $user->created_at ? \Illuminate\Support\Carbon::parse($user->created_at)->format('d/m/Y') : '01/01/1970' }}</td>
            </tr>
            <tr>
                <td class="admin-form-label">
                    <label for="admin_name">{{ __('lf.LF_profile_label_admin_name') }}</label>
                </td>
                <td colspan="3">
                    <input id="admin_name" type="text" name="name" class="form-control"
                           value="{{ old('name', $user->name) }}" placeholder="{{ __('lf.LF_common_label_name') }}" required>
                </td>
            </tr>
            <tr>
                <td class="admin-form-label">{{ __('lf.LF_profile_label_admin_gender') }}</td>
                <td colspan="3">
                    <div class="admin-radio-group">
                        <input id="admin_male" type="radio" name="gender" value="male"
                               @checked(old('gender', $user->gender) === 'male')>
                        <label for="admin_male">{{ __('lf.LF_common_gender_common_male') }}</label>
                        <input id="admin_female" type="radio" name="gender" value="female"
                               @checked(old('gender', $user->gender) === 'female')>
                        <label for="admin_female">{{ __('lf.LF_common_gender_common_female') }}</label>
                        <input id="admin_other" type="radio" name="gender" value="other"
                               @checked(old('gender', $user->gender) === 'other')>
                        <label for="admin_other">{{ __('lf.LF_common_gender_common_other') }}</label>
                    </div>
                </td>
            </tr>
            <tr>
                <td class="admin-form-label">
                    <label for="admin_phone">{{ __('lf.LF_profile_label_admin_phone') }}</label>
                </td>
                <td colspan="3">
                    <input id="admin_phone" type="text" name="phone" class="form-control"
                           value="{{ old('phone', $user->phone) }}" placeholder="0987 654 321">
                </td>
            </tr>
            <tr>
                <td class="admin-form-label">
                    {{ __('lf.LF_common_label_email') }}
                </td>
                <td colspan="3">
                    {{ $user->email }}
                </td>
            </tr>
            <tr>
                <td class="admin-form-label">
                    {{ __('lf.LF_profile_label_admin_birth_date') }}
                </td>
                <td colspan="3"
                    x-data="{
                        day: '{{ $birth?->format('d') }}',
                        month: '{{ $birth?->format('m') }}',
                        year: '{{ $birth?->format('Y') }}'
                    }">
                    <input type="hidden" name="date_of_birth"
                           x-bind:value="day && month && year ? `${year}-${month}-${day}` : ''">
                    <div class="admin-birth-fields">
                        <select class="form-control" x-model="day" aria-label="{{ __('lf.LF_common_label_day') }}">
                            <option value="">{{ __('lf.LF_common_label_day') }}</option>
                            @foreach (range(1, 31) as $day)
                                <option value="{{ str_pad((string) $day, 2, '0', STR_PAD_LEFT) }}">
                                    {{ $day }}
                                </option>
                            @endforeach
                        </select>
                        <select class="form-control" x-model="month" aria-label="{{ __('lf.LF_common_label_month') }}">
                            <option value="">{{ __('lf.LF_common_label_month') }}</option>
                            @foreach (range(1, 12) as $month)
                                <option value="{{ str_pad((string) $month, 2, '0', STR_PAD_LEFT) }}">
                                    {{ $month }}
                                </option>
                            @endforeach
                        </select>
                        <select class="form-control" x-model="year" aria-label="{{ __('lf.LF_common_label_year') }}">
                            <option value="">{{ __('lf.LF_common_label_year') }}</option>
                            @foreach (range((int) now()->format('Y'), 1940) as $year)
                                <option value="{{ $year }}">{{ $year }}</option>
                            @endforeach
                        </select>
                    </div>
                </td>
            </tr>
            <tr>
                <td class="admin-form-label">{{ __('lf.LF_profile_label_admin_password') }}</td>
                <td colspan="3">
                    <button type="button" class="btn btn-secondary" x-data
                            x-on:click="$dispatch('open-modal', 'change-password')">
                        {{ __('lf.LF_common_button_common_change_password') }}
                    </button>
                </td>
            </tr>
            <tr>
                <td class="admin-form-label"></td>
                <td colspan="3">
                    <button type="submit" class="btn btn-primary">{{ __('lf.LF_common_button_save_changes') }}</button>
                </td>
            </tr>
            </tbody>
        </table>
    </form>

    @if (session('password_success'))
        <div class="admin-alert admin-alert-success">
            {{ session('password_success') }}
        </div>
    @endif

    <x-modal name="change-password" :show="$errors->updatePassword->any()" focusable>
        <div class="lf-modal-card">
            <h2>{{ __('lf.LF_profile_title_common_change_password') }}</h2>

            @if ($errors->updatePassword->any())
                <div class="lf-alert-danger">
                    <ul>
                        @foreach ($errors->updatePassword->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('admin.my-account.password.update') }}">
                @csrf
                @method('PATCH')

                <div class="lf-form-group">
                    <label for="admin_current_password" class="lf-form-label">{{ __('lf.LF_common_label_current_password') }}</label>
                    <input id="admin_current_password" type="password" name="current_password"
                           class="lf-form-control" autocomplete="current-password" required>
                </div>

                <div class="lf-form-group">
                    <label for="admin_new_password" class="lf-form-label">{{ __('lf.LF_common_label_new_password') }}</label>
                    <input id="admin_new_password" type="password" name="password"
                           class="lf-form-control" autocomplete="new-password" required>
                </div>

                <div class="lf-form-group">
                    <label for="admin_password_confirmation" class="lf-form-label">{{ __('lf.LF_common_label_confirm_new_password') }}</label>
                    <input id="admin_password_confirmation" type="password" name="password_confirmation"
                           class="lf-form-control" autocomplete="new-password" required>
                </div>

                <div class="lf-modal-actions">
                    <button type="button" class="btn btn-secondary"
                            x-on:click="$dispatch('close-modal', 'change-password')">
                        {{ __('lf.LF_common_button_cancel') }}
                    </button>
                    <button type="submit" class="btn btn-primary">{{ __('lf.LF_common_button_common_change_password') }}</button>
                </div>
            </form>
        </div>
    </x-modal>
@endsection
