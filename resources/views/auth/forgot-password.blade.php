<x-auth-layout>
    <section class="auth-login-card auth-forgot-card" aria-labelledby="forgot-password-title">
        <div class="auth-page-heading">
            <h1 id="forgot-password-title">Quên mật khẩu</h1>
        </div>

        <p class="auth-help-text">
            Nhập địa chỉ email đã đăng ký. Chúng tôi sẽ gửi cho bạn liên kết để đặt lại mật khẩu.
        </p>

        <x-auth-session-status class="auth-session-status" :status="session('status')" />

        <form method="POST" action="{{ route('password.email') }}">
            @csrf

            <div class="auth-field">
                <label class="auth-label" for="email">Email</label>
                <input id="email"
                       class="auth-input"
                       type="email"
                       name="email"
                       value="{{ old('email') }}"
                       placeholder="Nhập địa chỉ email"
                       required
                       autofocus
                       autocomplete="email">
                <x-input-error :messages="$errors->get('email')" class="auth-error" />
            </div>

            <button class="auth-submit" type="submit">Gửi liên kết đặt lại mật khẩu</button>
        </form>

        <div class="auth-back-row">
            <a class="auth-forgot-link" href="{{ route('login') }}">Quay lại đăng nhập</a>
        </div>
    </section>
</x-auth-layout>
