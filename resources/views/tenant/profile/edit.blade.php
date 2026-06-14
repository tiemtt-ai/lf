@extends('layouts.tenant')

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

        @if (session('password_success'))
            <div class="student-alert is-success">{{ session('password_success') }}</div>
        @endif

        <div class="student-profile-layout">
            <section class="student-card student-profile-card">
                <div class="student-profile-card-heading">
                    <div>
                        <p class="student-profile-card-label">Thông tin cá nhân</p>
                        <h2>Thông tin học viên</h2>
                    </div>
                    <span class="student-profile-status">Đang hoạt động</span>
                </div>

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
                            <input id="student-date-of-birth" type="date" name="date_of_birth"
                                   class="student-form-control" value="{{ old('date_of_birth', $user->date_of_birth) }}">
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
                    </div>
                </form>
            </section>

            <aside class="student-profile-sidebar" aria-label="Cài đặt hồ sơ">
                <section class="student-card student-profile-side-card">
                    <span class="student-profile-side-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path d="M12 3 5 6v5c0 4.6 2.8 8 7 10 4.2-2 7-5.4 7-10V6l-7-3Z"></path>
                            <path d="m9 12 2 2 4-4"></path>
                        </svg>
                    </span>
                    <div class="student-profile-side-copy">
                        <h2>Bảo mật tài khoản</h2>
                        <p>Cập nhật mật khẩu định kỳ để bảo vệ tài khoản học tập của bạn.</p>
                    </div>
                    <button type="button" class="student-button is-outline" x-data
                            x-on:click="$dispatch('open-modal', 'change-password')">
                        Đổi mật khẩu
                    </button>
                </section>

                <section class="student-card student-profile-side-card">
                    <span class="student-profile-side-icon is-green">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <circle cx="12" cy="12" r="9"></circle>
                            <path d="M3 12h18M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18"></path>
                        </svg>
                    </span>
                    <div class="student-profile-side-copy">
                        <h2>Ngôn ngữ</h2>
                        <p>Ngôn ngữ giao diện hiện tại</p>
                    </div>
                    <div class="student-profile-setting-value">
                        <span>VI</span>
                        Tiếng Việt
                    </div>
                </section>

                <section class="student-card student-profile-side-card">
                    <div class="student-profile-progress-heading">
                        <div>
                            <p class="student-profile-card-label">Tiến độ hồ sơ</p>
                            <h2>Hồ sơ của bạn</h2>
                        </div>
                        <strong>80%</strong>
                    </div>
                    <div class="student-profile-progress-track" aria-label="Tiến độ hồ sơ 80%">
                        <span></span>
                    </div>
                    <p class="student-profile-progress-copy">
                        Bổ sung đầy đủ thông tin để nhận trải nghiệm học tập phù hợp hơn.
                    </p>
                </section>
            </aside>
        </div>

        @include('profile.partials.password-modal', [
            'action' => route('student.profile.password.update'),
            'variant' => 'student',
        ])
    </div>
@endsection
