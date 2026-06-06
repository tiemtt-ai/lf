<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Đăng ký LF</title>
</head>
<body>
<h1>Đăng ký khách hàng LF</h1>

@if ($errors->any())
    <div style="color:red">
        @foreach ($errors->all() as $error)
            <div>{{ $error }}</div>
        @endforeach
    </div>
@endif

<form method="POST" action="{{ route('customer.register.store') }}">
    @csrf

    <div>
        <label>Tên trung tâm / tổ chức</label><br>
        <input type="text" name="customer_name" value="{{ old('customer_name') }}">
    </div>

    <div>
        <label>Slug / subdomain</label><br>
        <input type="text" name="slug" value="{{ old('slug') }}">
    </div>

    <hr>

    <div>
        <label>Tên admin</label><br>
        <input type="text" name="name" value="{{ old('name') }}">
    </div>

    <div>
        <label>Email admin</label><br>
        <input type="email" name="email" value="{{ old('email') }}">
    </div>

    <div>
        <label>Mật khẩu</label><br>
        <input type="password" name="password">
    </div>

    <div>
        <label>Nhập lại mật khẩu</label><br>
        <input type="password" name="password_confirmation">
    </div>

    <br>

    <button type="submit">Tạo tài khoản</button>
</form>
</body>
</html>