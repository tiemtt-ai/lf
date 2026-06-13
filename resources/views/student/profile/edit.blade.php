@extends('layouts.student')

@section('title', 'Hồ sơ học viên | LearnForge')

@section('content')
    <div class="student-profile-shell">
        <header class="student-page-heading">
            <h1>Hồ sơ cá nhân</h1>
            <p>Cập nhật thông tin tài khoản và cài đặt bảo mật của bạn.</p>
        </header>

        @if (session('profile_success'))
            <div class="student-alert is-success">{{ session('profile_success') }}</div>
        @endif

        @if ($errors->default->any())
            <div class="student-alert is-danger">
                <ul>
                    @foreach ($errors->default->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section class="student-card student-profile-card">
            <h2>Thông tin học viên</h2>

            <form method="POST" action="{{ route('student.profile.update') }}">
                @csrf
                @method('PATCH')

                <div class="student-form-grid">
                    <div class="student-form-group student-form-span">
                        <label class="student-form-label" for="student-name">Họ và tên</label>
                        <input id="student-name" type="text" name="name" class="student-form-control"
                               value="{{ old('name', $user->name) }}" required>
                    </div>

                    <div class="student-form-group">
                        <label class="student-form-label" for="student-email">Email</label>
                        <input id="student-email" type="email" name="email" class="student-form-control"
                               value="{{ old('email', $user->email) }}" required>
                    </div>

                    <div class="student-form-group">
                        <label class="student-form-label" for="student-phone">Số điện thoại</label>
                        <input id="student-phone" type="text" name="phone" class="student-form-control"
                               value="{{ old('phone', $user->phone) }}">
                    </div>

                    <div class="student-form-group">
                        <label class="student-form-label" for="student-date-of-birth">Ngày sinh</label>
                        <input id="student-date-of-birth" type="date" name="date_of_birth" class="student-form-control"
                               value="{{ old('date_of_birth', $user->date_of_birth) }}">
                    </div>

                    <div class="student-form-group">
                        <label class="student-form-label" for="student-gender">Giới tính</label>
                        <select id="student-gender" name="gender" class="student-form-control">
                            <option value="">Chọn giới tính</option>
                            <option value="male" @selected(old('gender', $user->gender) === 'male')>Nam</option>
                            <option value="female" @selected(old('gender', $user->gender) === 'female')>Nữ</option>
                            <option value="other" @selected(old('gender', $user->gender) === 'other')>Khác</option>
                        </select>
                    </div>

                    <div class="student-form-group student-form-span">
                        <label class="student-form-label" for="student-role">Vai trò</label>
                        <input id="student-role" type="text" class="student-form-control" value="Học viên" disabled>
                    </div>
                </div>

                <div class="student-form-actions">
                    <button type="submit" class="student-button">Lưu thay đổi</button>
                    <button type="button" class="student-button is-outline" x-data
                            x-on:click="$dispatch('open-modal', 'change-password')">
                        Đổi mật khẩu
                    </button>
                </div>
            </form>
        </section>

        @if (session('password_success'))
            <div class="student-alert is-success">{{ session('password_success') }}</div>
        @endif

        @include('profile.partials.password-modal', [
            'action' => route('student.profile.password.update'),
        ])
    </div>
@endsection
