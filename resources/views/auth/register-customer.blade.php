<x-auth-layout>
    <h1>Đăng ký khách hàng LF</h1>

    @if ($errors->any())
        <div class="lf-alert-danger">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('customer.register.store') }}">
        @csrf

        <div class="lf-form-group">
            <label class="lf-form-label">Tên trung tâm / tổ chức</label>
            <input type="text" name="customer_name" class="lf-form-control"
                   value="{{ old('customer_name') }}">
        </div>

        <div class="lf-form-group">
            <label class="lf-form-label">Slug / subdomain</label>
            <input type="text" name="slug" class="lf-form-control"
                   value="{{ old('slug') }}">
        </div>

        <div class="lf-form-group">
            <label class="lf-form-label">Tên admin</label>
            <input type="text" name="name" class="lf-form-control"
                   value="{{ old('name') }}">
        </div>

        <div class="lf-form-group">
            <label class="lf-form-label">Email admin</label>
            <input type="email" name="email" class="lf-form-control"
                   value="{{ old('email') }}">
        </div>

        <div class="lf-form-group">
            <label class="lf-form-label">Mật khẩu</label>
            <input type="password" name="password" class="lf-form-control">
        </div>

        <div class="lf-form-group">
            <label class="lf-form-label">Nhập lại mật khẩu</label>
            <input type="password" name="password_confirmation" class="lf-form-control">
        </div>

        <button type="submit" class="lf-btn-primary">Tạo tài khoản</button>
    </form>
</x-auth-layout>
