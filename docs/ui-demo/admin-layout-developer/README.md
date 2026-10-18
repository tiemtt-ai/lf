# Admin layout — developer handoff

Version: 1.0

Document Status: Review

Implementation Status: Not Applicable

Last Updated: 2026-10-18

Document Path: ui-demo/admin-layout-developer/README.md

## Xem giao diện

Giải nén và mở `index.html` bằng trình duyệt hiện đại. Chỉ có một bản HTML. CSS, JavaScript, logo và thư viện icon nằm trong `assets/`; không cần CDN hay bước build.

## Thiết kế

- Font Arial, 14px/1.45; label và nút 400; tiêu đề trang 20px/500.
- Bảng 13px; tiêu đề cột 500. Input/nút desktop 36px, icon thao tác 16px.
- Màu chính #485cc7, nền #f7f8fb, logo gốc nền trong suốt trên nền trắng, rộng 180px và giữ tỷ lệ.
- Sidebar 248px, thu gọn 68px; một cấp con. Dưới 680px dùng drawer; Escape và nút đóng được hỗ trợ.
- Thanh đầu trang 66px; menu tài khoản, thông báo, ngôn ngữ. Bảng cuộn ngang trong vùng riêng.

## Quy ước mã

- `#admin-app`: root duy nhất của layout, chỉ dùng một lần trên trang.
- `admin-*`: khung trang, header, sidebar và điều hướng.
- `adm-*`: các component minh họa của trang danh mục, tách tên khỏi `ui-*`, `ux-*`, `mk-*` trong gói UI kit đã chốt.
- Class được tái sử dụng; ID phải duy nhất. Khi lặp form, dùng tiền tố theo instance, ví dụ `category-create-name`, `category-edit-12-name`. Cập nhật `for`, `aria-controls`, `aria-labelledby` tương ứng.
- `data-page`, `data-filter`, `data-edit`, `data-status`, `data-select`: điểm gắn hành vi trong demo.

## Tích hợp Laravel

1. Chép thư mục `assets` vào `public/admin-ui/`.
2. Tạo layout Blade `resources/views/layouts/admin.blade.php` từ `index.html`. Đổi đường dẫn tài nguyên theo ví dụ bên dưới.
3. Tách sidebar và header thành `partials/admin/sidebar.blade.php` và `partials/admin/header.blade.php`. Giữ root `id="admin-app"` bao toàn bộ layout.
4. Thay phần `<main class="admin-content">…</main>` bằng `<main class="admin-content">@yield('content')</main>`.
5. Truyền menu và trạng thái active từ route phía server. Thay các `href="#..."` bằng `route(...)`; bỏ listener demo của `[data-page]` để liên kết điều hướng thật.

```blade
<link rel="stylesheet" href="{{ asset('admin-ui/admin-layout.css') }}">
<script defer src="{{ asset('admin-ui/lucide.min.js') }}"></script>
<script defer src="{{ asset('admin-ui/admin-layout.js') }}"></script>
```

Đường dẫn logo trong Blade: `{{ asset('admin-ui/brand-logo.png') }}`.

## Kết hợp UI kit đã chốt

Gói này cung cấp khung admin và một trang danh mục minh họa, không thay thế toàn bộ UI kit. CSS layout được giới hạn trong `#admin-app`; ví dụ nội dung dùng `adm-*` để tránh trùng class của kit.

CSS hiện tại của kit dựa vào root `#ui-catalog-soft`. Nếu nhúng nguyên kit, giữ đúng root này và chỉ có một root trên trang. Đặt CSS kit sau CSS layout. Khi chuyển thành Blade components tái sử dụng, developer cần chuyển selector root sang wrapper component thích hợp và bỏ phụ thuộc ID demo trong JavaScript; không sao chép nhiều lần nguyên trang catalog.

## Phân biệt demo và nghiệp vụ

`admin-layout.js` có ba nhóm: sidebar/drawer; bảng và form demo; menu thanh đầu trang. Khi thay nội dung trang, chuyển nhóm bảng/form sang script riêng của trang hoặc bỏ nhóm đó. Không chạy nhóm demo trên trang không có markup tương ứng.

- Tìm kiếm, lọc trạng thái, phân trang, chọn dòng, thêm/sửa và bật/tắt trạng thái hoạt động với mảng dữ liệu trong bộ nhớ; tải lại trang sẽ mất thay đổi.
- Xuất CSV tạo tệp từ dữ liệu đang lọc. Nhập CSV chỉ chọn tệp và thông báo, chưa đọc hay nhập dữ liệu.
- Ngôn ngữ chỉ đổi nhãn lựa chọn; không dịch cả giao diện.
- Hồ sơ, mật khẩu, đăng xuất và thông báo là điểm minh họa giao diện.
- Khi nối Laravel: dùng dữ liệu server, kiểm tra mã duy nhất, phân quyền, validation, CSRF và xử lý lỗi; không coi kiểm tra demo là ràng buộc nghiệp vụ.

## Kiểm tra bàn giao

Đã kiểm tra cú pháp JavaScript, ID không trùng và đường dẫn tài nguyên nội bộ. Chưa kiểm thử tự động trên trình duyệt hoặc tích hợp với repository Laravel của bạn.

Logo lấy nguyên tệp người dùng cung cấp: https://www.masterkorean.vn/images/logo.png. Thư viện Lucide được đóng gói cục bộ; giữ các thông báo giấy phép trong file vendor.

Cập nhật: bỏ nhãn QUẢN TRỊ trên menu; sidebar thu gọn hiển thị chữ V, mở rộng hiển thị logo đầy đủ.

Logo cuối cùng: Master Korean; giữ nguyên tỷ lệ ảnh trên nền trắng. Khi thu gọn menu hiển thị chữ V theo thiết kế đã duyệt.

Bố cục cuối: sidebar và nội dung cao tối thiểu 100dvh, footer Copyright nằm ở chân màn hình khi nội dung ngắn và sau nội dung khi trang dài; không dùng footer fixed.
