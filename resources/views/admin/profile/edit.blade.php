@extends('layouts.backend')

@section('title', 'Settings')
@section('page_title', 'Settings')

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

    <h2 class="sr-only">Profile Information</h2>

    <form method="POST" action="{{ route('admin.profile.update') }}">
        @csrf
        @method('PATCH')

        <table class="admin-form-grid">
            <tbody>
            <tr>
                <td class="admin-form-label">Username</td>
                <td>{{ $user->name }}</td>
                <td class="admin-form-label">Ngày đăng ký:</td>
                <td>{{ $user->created_at ? \Illuminate\Support\Carbon::parse($user->created_at)->format('d/m/Y') : '01/01/1970' }}</td>
            </tr>
            <tr>
                <td class="admin-form-label">
                    <label for="admin_name">Tên</label>
                </td>
                <td colspan="3">
                    <input id="admin_name" type="text" name="name" class="form-control"
                           value="{{ old('name', $user->name) }}" placeholder="Name" required>
                </td>
            </tr>
            <tr>
                <td class="admin-form-label">Giới tính</td>
                <td colspan="3">
                    <div class="admin-radio-group">
                        <input id="admin_male" type="radio" name="gender" value="male"
                               @checked(old('gender', $user->gender) === 'male')>
                        <label for="admin_male">Nam</label>
                        <input id="admin_female" type="radio" name="gender" value="female"
                               @checked(old('gender', $user->gender) === 'female')>
                        <label for="admin_female">Nữ</label>
                        <input id="admin_other" type="radio" name="gender" value="other"
                               @checked(old('gender', $user->gender) === 'other')>
                        <label for="admin_other">Khác</label>
                    </div>
                </td>
            </tr>
            <tr>
                <td class="admin-form-label">
                    <label for="admin_phone">Số điện thoại</label>
                </td>
                <td colspan="3">
                    <input id="admin_phone" type="text" name="phone" class="form-control"
                           value="{{ old('phone', $user->phone) }}" placeholder="0987 654 321">
                </td>
            </tr>
            <tr>
                <td class="admin-form-label">
                    Email
                </td>
                <td colspan="3">
                    {{ old('email', $user->email) }}
                    <input type="hidden" name="email" value="{{ old('email', $user->email) }}">
                </td>
            </tr>
            <tr>
                <td class="admin-form-label">
                    Ngày sinh
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
                        <select class="form-control" x-model="day" aria-label="Day">
                            <option value="">Ngày</option>
                            @foreach (range(1, 31) as $day)
                                <option value="{{ str_pad((string) $day, 2, '0', STR_PAD_LEFT) }}">
                                    {{ $day }}
                                </option>
                            @endforeach
                        </select>
                        <select class="form-control" x-model="month" aria-label="Month">
                            <option value="">Tháng</option>
                            @foreach (range(1, 12) as $month)
                                <option value="{{ str_pad((string) $month, 2, '0', STR_PAD_LEFT) }}">
                                    {{ $month }}
                                </option>
                            @endforeach
                        </select>
                        <select class="form-control" x-model="year" aria-label="Year">
                            <option value="">Năm</option>
                            @foreach (range((int) now()->format('Y'), 1940) as $year)
                                <option value="{{ $year }}">{{ $year }}</option>
                            @endforeach
                        </select>
                    </div>
                </td>
            </tr>
            <tr>
                <td class="admin-form-label">Mật khẩu</td>
                <td colspan="3">
                    <button type="button" class="btn btn-secondary" x-data
                            x-on:click="$dispatch('open-modal', 'change-password')">
                        Change Password
                    </button>
                </td>
            </tr>
            <tr>
                <td class="admin-form-label"></td>
                <td colspan="3">
                    <button type="submit" class="btn btn-primary">Save Changes</button>
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
            <h2>Change Password</h2>

            @if ($errors->updatePassword->any())
                <div class="lf-alert-danger">
                    <ul>
                        @foreach ($errors->updatePassword->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('admin.profile.password.update') }}">
                @csrf
                @method('PATCH')

                <div class="lf-form-group">
                    <label for="admin_current_password" class="lf-form-label">Current Password</label>
                    <input id="admin_current_password" type="password" name="current_password"
                           class="lf-form-control" autocomplete="current-password" required>
                </div>

                <div class="lf-form-group">
                    <label for="admin_new_password" class="lf-form-label">New Password</label>
                    <input id="admin_new_password" type="password" name="password"
                           class="lf-form-control" autocomplete="new-password" required>
                </div>

                <div class="lf-form-group">
                    <label for="admin_password_confirmation" class="lf-form-label">Confirm New Password</label>
                    <input id="admin_password_confirmation" type="password" name="password_confirmation"
                           class="lf-form-control" autocomplete="new-password" required>
                </div>

                <div class="lf-modal-actions">
                    <button type="button" class="btn btn-secondary"
                            x-on:click="$dispatch('close-modal', 'change-password')">
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">Change Password</button>
                </div>
            </form>
        </div>
    </x-modal>
@endsection
