# Học viên — Góc học tập của tôi (thiết kế tĩnh)

Version: 1.1

Document Status: Approved

Implementation Status: Not Applicable

Last Updated: 2026-11-10

Document Path: ui-demo/learner-my-learning-design/README.md

## Phạm vi

Bản thiết kế tĩnh cho trang học viên `/my-courses` của LF: Khoá học của tôi,
Lớp học của tôi, Bài thi của tôi, kèm header (menu, tài khoản, ngôn ngữ) và
footer của site học viên. Quy trình theo mục “Static Design” trong
[LF-Tech-CSS](../../tech/LF-Tech-CSS.md).

**Nguồn giao diện:** Owner yêu cầu (2026-11-10) làm giống giao diện **B2B chuẩn
của mk_v2, slug `giangtran`** (trung tâm không tùy biến, `body.standard-b2b-site`).
Bản 1.1 dựng lại theo mã nguồn mk_v2, giữ nguyên tên class:

| Phần | Nguồn trong mk_v2 |
|---|---|
| Header, menu tài khoản, ngôn ngữ, menu mobile | `resources/views/b2b/layouts/standard-nav.blade.php`, `public/assets/b2b/standard-site.css` |
| Thanh 4 ô, tiêu đề, tab trạng thái, Lọc | `resources/views/b2b/profile/student-products.blade.php`, `product-filter.blade.php`, `public/assets/b2b/student-products.css` |
| Thẻ khóa học | `profile/student-course-card.blade.php` |
| Thẻ lớp học | `profile/standard-live-courses.blade.php`, `public/assets/b2b/kla_my_page.css` |
| Thẻ bài thi | `profile/student-exam-card.blade.php` |
| Footer, nút AI Speak | `resources/views/b2b/layouts/footerb2b.blade.php` |
| Ảnh (logo, cờ, tim, ảnh khóa học mặc định, logo footer) | `public/v2-imgs`, `public/images/iconb2b`, `public/assets/images` → `assets/img/` |

Bản 1.0 (tự vẽ theo ảnh) được Owner đánh giá “chưa giống” và đã thay hoàn toàn.

Nghiệp vụ và dữ liệu minh họa theo LF: `resources/views/tenant/my-learning/*`,
mục “My Courses Read Pages” trong `business/LF-Navigation.md`.

## Xem

Mở `index.html`; mở thẳng tab: `#mk`, `#mklive`, `#exam`, `#results`. Thanh
“Bản thiết kế” bật/tắt khối buổi học đang diễn ra và trạng thái trống. “Hôm nay”
cố định 10/11/2026 20:00 để minh họa trạng thái bài thi theo lịch.

## Cấu trúc file

* `assets/student.css`: lớp nền tương đương Bootstrap 4 reboot mà B2B chuẩn đang
  dùng, tiếp theo là các khối CSS chép từ mk_v2 (đánh dấu nguồn từng khối), cuối
  cùng là phần bổ sung của LF.
* `assets/student.js`: dữ liệu minh họa, dựng thẻ theo đúng markup Blade của mk_v2,
  đổi tab, Lọc, ♡, menu `details`.

## Khác biệt so với mk_v2 và nghiệp vụ LF — cần Owner quyết định

| # | Nội dung | Bản thiết kế | Ghi chú |
|---|---|---|---|
| Q1 | “Kết quả học tập” | Có ô và mục menu; nội dung để trống | LF-Navigation: chưa làm |
| Q2 | Nhóm “… quan tâm” và ♡ | Có, như mk_v2 | Favorite đang hoãn (ADR-0030 D4) |
| Q3 | Nhóm trạng thái | Dùng nhãn mk_v2: hiện tại / hoàn thành / quan tâm | LF có 3 nhóm Đang học / Đã hoàn thành / Hết hạn; cần chốt: gộp “Hết hạn” vào “hoàn thành” như mk_v2 hay giữ riêng |
| Q4 | “Bài thi theo lịch · Đã hết giờ vào thi / Còn thời gian vào thi” | Có, như mk_v2 | LF chưa có khung giờ thi (`core_assessment_quiz_registrations`) |
| Q5 | Nút “Tư vấn 1:1” (Zalo giáo viên) | Có, như mk_v2 | LF chưa có số Zalo giáo viên / tính năng tư vấn |
| Q6 | Menu E-Learning / MK Live / Bài Thi / Học liệu | Như mk_v2 | Menu LF hiện là Trang chủ / Khóa học / Bài đánh giá / Dịch vụ…; cần chốt tên và mục |
| Q7 | Footer: người đại diện, điện thoại, email, MST, logo VISANG | Như mk_v2, dữ liệu giả | Cần xác nhận tenant LF có các trường này |
| Q8 | Ảnh bìa | Ảnh mặc định của mk_v2 (khóa học), ô chữ cái (lớp), icon (bài thi) | LF chưa có ảnh bìa ở trang này |
| Q9 | Khối “Buổi học đang diễn ra” | Giữ của LF, đặt dưới thanh 4 ô | mk_v2 không có |
| Q10 | Logo Master Korean, nút AI Speak | Như mk_v2 | LF dùng logo/tên tenant; AI Speak chưa có trong LF |

### Owner chốt (2026-11-10)

Owner duyệt ngày 2026-11-10 và yêu cầu áp dụng cho toàn bộ giao diện học viên:

- Q7, Q10: chép y nguyên mk_v2 (logo Master Korean, logo VISANG, thông tin
  footer) cho mọi tenant.
- Q6: bố cục menu mk_v2, mục trỏ tới trang LF có sẵn; mục LF không có trang thì
  bỏ; Lịch sử học, AI Tutor, Hồ sơ vào menu tài khoản.
- Q1, Q2, Q4, Q5, AI Speak: hiện nhưng khóa, bấm vào báo "sắp ra mắt". Khung
  giờ thi (Q4) không có dữ liệu nên không hiện.
- Q3: giữ 3 nhóm LF (Đang học / Đã hoàn thành / Hết hạn), dạng tab có số đếm.

## Khác biệt so với UI Kit

Trang học viên không dùng UI Kit Admin/Teacher. Typography và màu theo B2B chuẩn
mk_v2: font hệ thống (Bootstrap 4), tiêu đề trang 28px/700, tab 15–16px/600,
nội dung 13–14px, màu nhấn `#4157c8` / `#245dc1`, nền `#f6f8fc`, footer `#1f3278`.

## Ghi chú khi áp dụng (bước 2)

Chỉ thực hiện khi Owner duyệt bản này và chốt Q1–Q10, rồi yêu cầu “áp dụng
thiết kế `learner-my-learning-design` cho trang Khóa học của tôi”.
- Chuyển presentation vào `resources/css/tenant/` (thay `my-learning.css` và
  header/footer trong `tenant.css`); không nạp CSS/JS từ `docs/`, không nạp
  Bootstrap CDN — chỉ lấy phần reboot cần thiết như lớp nền của bản thiết kế.
- Giữ dữ liệu, nhóm, quyền xem (chỉ dữ liệu của chính học viên), route và form POST
  bắt đầu làm bài hiện tại; Alpine thay JS demo.

## Bảo trì bản thiết kế

CSS/JS có hậu tố `?v=…`, tăng mỗi lần sửa `assets/`. Thư mục cần quyền 755.

## Kiểm tra đã thực hiện

Playwright 1440×1000 và 375×812, cả 3 tab: không tràn ngang, không lỗi console;
đã thử menu tài khoản, đổi tab/nhóm, ♡, Lọc, trạng thái trống. So sánh bằng mắt
với 4 ảnh Owner gửi; chưa đo pixel so với trang mk_v2 chạy thật (máy chưa chạy
mk_v2: XAMPP PHP 7.4, docker cổng 8002 không phản hồi).

## Lịch sử thay đổi

- 1.0 — 2026-11-10: bản đầu tiên tự vẽ theo 4 ảnh tham khảo.
- 1.1 — 2026-11-10: dựng lại theo mã nguồn B2B chuẩn mk_v2 (slug giangtran), giữ
  tên class và CSS gốc; thêm thẻ bài thi theo lịch, Tư vấn 1:1, nút AI Speak.
