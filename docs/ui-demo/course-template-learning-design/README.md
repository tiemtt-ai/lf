# Course Template — Đầu ra & năng lực (thiết kế tĩnh)

Version: 1.0

Document Status: Review

Implementation Status: Not Applicable

Last Updated: 2026-10-31

Document Path: ui-demo/course-template-learning-design/README.md

## Phạm vi

Bản thiết kế tĩnh cho tab **Đầu ra & năng lực** của trang Thiết lập Template
khóa học (`admin/course-templates/{id}/edit?tab=learning`). Quy trình theo mục
“Static Design” trong [LF-Tech-CSS](../../tech/LF-Tech-CSS.md).

Nguồn đối chiếu hiện trạng: `resources/views/course-templates/partials/learning-mappings.blade.php`,
`learning-framework-select.blade.php`, `learning-skill-chip.blade.php` và dữ liệu
template id 2 trên môi trường dev ngày 2026-10-31. Bản thiết kế chỉ đổi trình bày
và bố cục, không đổi nghiệp vụ.

## Xem

Mở `index.html` bằng trình duyệt, giữ nguyên cấu trúc `docs/ui-demo/`: trang
dùng trực tiếp CSS/icon của `admin-layout-developer` và `ui-kit-developer`.
Thanh “Bản thiết kế · xem trạng thái” chỉ có trong bản thiết kế, dùng để xem:
đã chọn bộ chuẩn, chưa chọn bộ chuẩn, có liên kết tới nội dung đã xoá.

## Thay đổi so với giao diện hiện tại

1. **Khối bộ chuẩn gọn lại:** tên bộ chuẩn, phiên bản, trạng thái “Đang dùng”,
   số năng lực hiển thị dạng badge; ba thao tác cùng một hàng: Xem bộ chuẩn,
   Đổi bộ chuẩn, Gỡ bộ chuẩn (nút nguy hiểm). Ba đoạn hướng dẫn lặp ý gộp còn một.
2. **Thêm thống kê tiến độ:** bài học đã gắn (x/y), năng lực của bộ chuẩn đã
   dùng (x/y) có thanh tiến độ, tổng liên kết trong bản nháp.
3. **Đổi bộ chuẩn qua popup 1 cột** thay cho ô select luôn hiển thị và tự mở
   xác nhận khi đổi giá trị. Popup báo trước số liên kết sẽ bị gỡ; nút Áp dụng
   chỉ bật khi chọn khác phiên bản đang dùng.
4. **Bảng năng lực theo bài học:** header màu nhấn theo UI Kit, cột #, Bài học,
   Năng lực, Thao tác; chữ bảng 14px theo amendment UI Kit 2026-10-19.
   - Tab lọc Tất cả / Chưa gắn / Đã gắn kèm số lượng; ô tìm bài học hoặc năng lực.
   - Nhóm theo đường dẫn chương (Chương 1 › Mục 2 › Nhóm 1), thu gọn/mở rộng
     từng nhóm hoặc tất cả; mỗi nhóm có “n bài · m đã gắn”; nhóm rỗng ghi rõ.
   - Năng lực hiển thị dạng chip có mã + tên (mô tả ở tooltip), nút gỡ là icon.
   - Bài chưa gắn có chấm màu cảnh báo “Chưa gắn năng lực”.
5. **Popup gắn năng lực dùng chung (2 cột):** một dialog cho mọi bài học thay
   vì mỗi dòng một dialog; có ô tìm; mỗi năng lực là thẻ chọn có mã, tên, mô tả;
   năng lực đã gắn xếp cuối, mờ, có badge “Đã gắn”; footer đếm số đã chọn, nút
   Gắn năng lực chỉ bật khi đã chọn.
6. **Popup xác nhận** gỡ năng lực / gỡ bộ chuẩn theo mẫu popup UI Kit 1 cột.
7. **Popup xem bộ chuẩn (2 cột):** thang đánh giá dạng 4 ô, bảng năng lực có
   tìm kiếm và số bài đã gắn từng năng lực.
8. **Liên kết tới nội dung đã xoá** tách thành khối cảnh báo riêng phía trên bảng.
9. **Mobile ≤ 680px:** bảng chuyển thành thẻ theo bài học; popup full chiều rộng.

## Khác biệt so với UI Kit

- Bổ sung class trang `ctl-*` trong `assets/learning.css`: badge trạng thái
  (success/warn/muted), thẻ thống kê + thanh tiến độ, chip năng lực, hàng nhóm
  thu gọn, thẻ chọn năng lực, ghi chú cảnh báo, toast. Lý do: UI Kit chưa có các
  mẫu này; màu dùng lại token `--ui-primary` và bảng màu trung tính của kit.
- Thêm biến thể nút `ui-button--danger` (nền đỏ) cho xác nhận gỡ và
  `ui-button--sm` (32px) cho nút trong dòng bảng.
- Bảng dùng căn trái và bỏ vạch dọc ở thân bảng (kit mẫu căn phải cho số liệu).

## Ghi chú khi áp dụng (bước 2)

Chỉ thực hiện khi Owner duyệt bản này và yêu cầu “áp dụng thiết kế
`course-template-learning-design` cho tab Đầu ra & năng lực”.

- Giữ nguyên route, `working_revision`, `mapping_role`, `source`, `mapping_picker`,
  validation, thông báo lỗi và hành vi async hiện có (`data-lf-mapping-async`).
- Popup gắn năng lực dùng chung cần nhận bài học qua data-attribute và giữ cơ
  chế mở lại popup khi có lỗi validation (`old('mapping_picker')`).
- Thống kê, lọc, tìm, thu gọn nhóm là trình bày phía client trên dữ liệu đã có;
  không thêm query mới. Dữ liệu và handler trong `assets/learning.js` chỉ để minh họa.
- Không nạp CSS/JS từ `docs/`; chuyển presentation vào Shared CSS theo LF-Tech-CSS.

## Kiểm tra đã thực hiện

Mở bằng trình duyệt qua localhost ở 1440×1000 và 375×812: không tràn ngang,
không lỗi console; thử gắn năng lực, đổi bộ chuẩn, popup gắn trên mobile.
Chưa kiểm tra bằng trình đọc màn hình, chưa đo pixel so với UI Kit.

## Lịch sử thay đổi

- 1.0 — 2026-10-31: bản thiết kế đầu tiên.
