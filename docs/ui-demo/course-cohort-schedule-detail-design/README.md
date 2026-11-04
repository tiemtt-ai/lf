# Course Cohort — Chi tiết lịch học (thiết kế tĩnh)

Version: 1.1

Document Status: Review

Implementation Status: Not Applicable

Last Updated: 2026-11-04

Document Path: ui-demo/course-cohort-schedule-detail-design/README.md

## Phạm vi

Bản thiết kế tĩnh cho popup **Chi tiết lịch học** và form **Thêm / Sửa lịch
học** của lớp (tab Lịch học, `admin/course-cohorts/{id}?tab=schedules`). Quy trình theo mục
“Static Design” trong [LF-Tech-CSS](../../tech/LF-Tech-CSS.md).

Nguồn đối chiếu hiện trạng: `resources/views/course-cohort-schedules/partials/detail.blade.php`,
`form.blade.php`,
chuỗi `lf.LF_course_cohort_schedule_*` và ảnh màn hình Owner gửi ngày 2026-11-04.
Bản thiết kế chỉ đổi trình bày, không đổi nghiệp vụ: lịch học vẫn chỉ là kế
hoạch dự kiến, không tạo Buổi học thực tế.

## Xem

Mở `index.html` bằng trình duyệt, giữ nguyên cấu trúc `docs/ui-demo/`; popup mở
sẵn. Thanh “Bản thiết kế” chỉ có trong bản thiết kế: lịch 1 tháng (đúng dữ liệu
ảnh dev “lịch chính tháng 11”), lịch 3 tháng nhiều ngày loại trừ, lịch sắp áp
dụng không có ngày loại trừ (múi giờ khác). Ngày “hôm nay” cố định 04/11/2026.

## Thay đổi so với giao diện hiện tại

1. **Tiêu đề gọn:** tên lịch kèm badge trạng thái ngay cạnh; câu “kế hoạch dự
   kiến” rút ngắn, có icon thông tin.
2. **Dải tổng hợp 4 ô:** Thời gian áp dụng (+ số ngày), Số buổi dự kiến (+ đã
   qua / còn lại, buổi tiếp theo khi đang áp dụng), Tổng thời lượng (+ giờ/tuần),
   Múi giờ (+ GMT). Trạng thái chuyển lên tiêu đề.
3. **Lặp hằng tuần dạng 7 ô T2–CN:** ngày có học nổi màu kèm giờ và thời lượng;
   ngày không học mờ — thấy ngay nhịp học trong tuần (bản cũ là danh sách 3 dòng).
4. **Các buổi dự kiến có 2 kiểu xem:**
   - *Lịch tháng* (mặc định trên PC): lưới 7 cột, mỗi buổi là nhãn giờ; buổi đã
     qua màu xám, buổi tiếp theo màu xanh; ngày loại trừ hiện ngay trên lịch
     (giờ gạch ngang + lý do); ngày ngoài thời gian áp dụng có nền sọc; chuyển
     tháng bằng ‹ › khi lịch kéo dài nhiều tháng; chú thích màu bên dưới.
   - *Danh sách* (mặc định trên mobile): nhóm theo tuần kèm số buổi/tuần, đánh
     số buổi, thời lượng từng buổi; buổi bị loại trừ vẫn hiện tại đúng vị trí
     (gạch ngang + badge lý do); tự cuộn tới “Buổi tiếp theo”.
   Bản cũ chỉ liệt kê 2 cột, buổi bị loại trừ biến mất khỏi danh sách nên khó
   thấy ngày lễ đã làm mất buổi nào.
5. **Ngày loại trừ dạng thẻ:** thứ, ngày, lý do và tác động (“bỏ 1 buổi” hoặc
   “không trùng buổi học”).
6. **Footer:** nhắc “Sửa lịch không tạo hay thay đổi Buổi học thực tế”; nút
   chính ghi rõ “Sửa lịch học” (chỉ hiện khi lớp còn sửa được, như hiện tại).
7. **Mobile ≤ 680px:** tổng hợp 2 cột, chỉ hiện ngày có học trong tuần, lịch
   tháng thu nhỏ thành vạch màu, danh sách là kiểu xem mặc định.

## Form Thêm / Sửa lịch học (v1.1)

Mở bằng “Thêm lịch học” (nền) hoặc “Sửa lịch học” (popup chi tiết).

1. **Bố cục 2 phần:** form bên trái, xem trước cố định bên phải (popup 3 cột);
   ≤ 1000px xếp dọc.
2. **Thông tin chung:** Tên lịch, Múi giờ (kèm GMT), Ngày bắt đầu/kết thúc; dòng
   gợi ý thời gian vận hành của lớp và nút “Dùng toàn bộ thời gian vận hành”.
3. **Khung giờ định kỳ theo 7 ngày trong tuần:** tick ngày để bật, nhập giờ
   bắt đầu–kết thúc, thời lượng hiện ngay; “+ Thêm khung giờ Thứ …” cho nhiều
   khung trong cùng ngày; ngày không học ghi “Không học”. Thay cho bảng nhiều
   dòng “Ngày học trong tuần” + dropdown (dễ chọn trùng ngày, khó thấy tuần).
   Ngày mới bật lấy giờ của khung gần nhất để nhập nhanh.
4. **Ngày loại trừ:** ô Ngày + Lý do + nút Thêm (bản cũ thêm ngay khi chọn ngày,
   không có nút); mỗi dòng có thứ, tác động (“Bỏ n buổi” / “Không trùng buổi
   học” / “Ngoài khoảng áp dụng”), lý do sửa được tại chỗ, nút gỡ.
5. **Xem trước dạng lịch tháng** (cùng thành phần với popup chi tiết): 3 ô
   Buổi dự kiến · Tổng giờ · Đã loại trừ; khi sửa hiện “So với lịch đã lưu:
   12 → 13 buổi”. **Bấm một ngày có buổi học trên lịch để loại trừ / bỏ loại
   trừ** (có bàn phím Enter/Space).
6. **Kiểm tra lỗi tại chỗ:** tên trống, khoảng ngày ngược hoặc ngoài thời gian
   vận hành, chưa chọn ngày học, giờ kết thúc ≤ giờ bắt đầu, khung giờ chồng
   lấn cùng ngày, ngày loại trừ ngoài khoảng áp dụng. Lỗi hiện dưới đúng ô +
   khung tổng hợp đầu form khi bấm Lưu. Khung giờ lỗi không làm mất xem trước —
   xem trước bỏ qua khung đó và ghi “Chưa tính n khung giờ chưa hợp lệ”.
7. **Footer:** trạng thái “Chưa có thay đổi / Có thay đổi chưa lưu / Còn n mục
   cần sửa”; nút Lưu khóa khi sửa mà chưa đổi gì; Hủy hoặc đóng khi có thay đổi
   sẽ hỏi “Bỏ thay đổi chưa lưu?”. Tiêu đề “Sửa lịch học: <tên>” / “Thêm lịch học”.

**Ghi chú áp dụng cho form:** giữ nguyên endpoint preview, `maxSlots` 50,
`maxExclusions` 366, cấu trúc `slots[]`/`exclusions[]`, `old()` khi có
lỗi server và hộp xác nhận gỡ khung giờ đã có dữ liệu. Quy tắc kiểm tra trong
`assets/schedule.js` chỉ minh họa; server vẫn là nơi kiểm tra cuối. Việc bấm
lịch để loại trừ chỉ thêm vào `exclusions[]` như thao tác thêm tay.

## Chữ và font theo UI Kit

Arial; 18px/500 tiêu đề popup, 15px/500 tiêu đề khối, 14px nội dung, nhãn,
ô nhập, nút, bảng/danh sách (amendment UI Kit 2026-10-19), 12px chữ phụ,
chú thích, nhãn trong ô lịch; chỉ dùng weight 400/500. Đã đo trên trình duyệt
cho cả popup chi tiết và form (ngoại lệ duy nhất là dấu × 26px của UI Kit).

## Khác biệt so với UI Kit

- Class trang `sch-*` trong `assets/schedule.css`: dải tổng hợp, ô ngày trong
  tuần, công tắc kiểu xem (segmented), lưới lịch tháng, nhãn buổi/ngày loại trừ,
  danh sách theo tuần, thẻ ngày loại trừ. Màu dùng lại `--ui-primary`; xanh cho
  buổi tiếp theo, vàng cho ngày loại trừ, xám cho buổi đã qua.
- Popup dùng mẫu UI Kit 3 cột (1120px) thay vì 2 cột để lịch tháng đủ rộng.

## Ghi chú khi áp dụng (bước 2)

Chỉ thực hiện khi Owner duyệt bản này và yêu cầu “áp dụng thiết kế
`course-cohort-schedule-detail-design` cho popup Chi tiết lịch học”.

- Giữ nguyên nguồn dữ liệu `$schedule`, `$slots`, `$exclusions`, `$preview`,
  `$derivedStatus`, `$canMutate` và route đóng/sửa hiện tại. Phép tính buổi học
  trong `assets/schedule.js` chỉ để minh họa.
- `$preview` hiện chỉ trả các buổi còn lại sau loại trừ. Để hiện buổi bị loại
  trừ đúng vị trí trên lịch/danh sách cần preview trả thêm các buổi bị loại trừ
  (hoặc ghép từ `$exclusions` + `$slots` khi render). Đây là thay đổi phía
  server, cần Owner xác nhận; nếu không đổi, chỉ hiển thị ngày loại trừ ở thẻ.
- “Buổi tiếp theo”, “đã qua/còn lại” tính theo thời điểm hiện tại ở múi giờ của
  lịch học; chỉ hiển thị khi trạng thái là Đang áp dụng.
- Không nạp CSS/JS từ `docs/`; chuyển presentation vào Shared CSS theo LF-Tech-CSS.

## Bảo trì bản thiết kế

Đường dẫn CSS/JS trong `index.html` có hậu tố `?v=…`; mỗi lần sửa `assets/`
phải tăng hậu tố này. Thư mục mới tạo cần quyền đọc cho Apache (755) để xem qua
`http://localhost/LF/docs/ui-demo/…`.

## Kiểm tra đã thực hiện

Mở trang bình thường qua localhost ở 1440×1000 và 375×812: không tràn ngang,
không cắt chữ; đã thử cả 3 mẫu, chuyển Lịch tháng/Danh sách, chuyển tháng,
nút tháng sau khóa ở tháng cuối. Số liệu mẫu 1 tháng khớp ảnh dev (12 buổi,
loại trừ 24/11). Chưa kiểm tra bằng trình đọc màn hình, chưa đo pixel so với UI Kit.

## Lịch sử thay đổi

- 1.0 — 2026-11-04: bản thiết kế đầu tiên.
- 1.1 — 2026-11-04: rà chữ theo thang UI Kit (14px chính, 12px phụ, 15px tiêu
  đề khối, weight 400/500); thêm form Thêm / Sửa lịch học.
