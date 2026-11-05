# Course Cohort — Buổi học (thiết kế tĩnh)

Version: 1.0

Document Status: Review

Implementation Status: Not Applicable

Last Updated: 2026-11-05

Document Path: ui-demo/course-cohort-sessions-design/README.md

## Phạm vi

Bản thiết kế tĩnh cho tab **Buổi học** của lớp (`admin/course-cohorts/{id}?tab=sessions`):
danh sách buổi, nội dung Trước / Trong / Sau của từng buổi, popup Xem/Sửa buổi
(gồm đổi ngày giờ), Thêm buổi và Tạo buổi từ lịch học. Quy trình theo mục
“Static Design” trong [LF-Tech-CSS](../../tech/LF-Tech-CSS.md).

Kế thừa 3 bản nháp của Owner (2026-11-05): “Tạo nhiều buổi học từ lịch”,
“Thêm buổi — Online trực tiếp”, “Thêm buổi — Bất kỳ lúc nào”; đã chuyển sang
chữ/font và thành phần UI Kit (Arial, 14px chính, 12px phụ, 15px tiêu đề khối,
18px tiêu đề popup, weight 400/500).

Nguồn nghiệp vụ đã đọc: `core/LF-Core-LiveClass.md` (Schedule Occurrence To
Session Origin, LiveClass Sessions), `core/LF-Core-Course.md` (Activity
Learning Availability, Course Type), `database/course/core_course_template_activities.md`,
và code `resources/views/course-cohorts/partials/tabs/sessions.blade.php`,
`session-form`, `session-batch`, `session-reschedule`, chuỗi `lf.LF_course_cohort_session_*`.
Trang thật cần đăng nhập nên dữ liệu trong bản thiết kế là dữ liệu minh họa.

## Phân tích nghiệp vụ

**Đã có trong LF (giữ nguyên):**
- Buổi trong chương trình gắn đúng 1 Bài học + 1 Hoạt động học trực tiếp
  (`live_class`) của Version đã khóa; một bài có thể có nhiều buổi. Buổi ngoài
  chương trình không gắn bài, không tính tiến độ.
- Hoạt động trên template khai báo thời điểm: Trước / Trong / Sau buổi học
  (Theo lịch), hoặc thêm “Bất kỳ lúc nào” (Kết hợp). Hoạt động trực tiếp không
  được là “Bất kỳ lúc nào”.
- Buổi tạo từ ngày dự kiến của lịch lưu nguồn bất biến (Theo lịch / Đã điều
  chỉnh); buổi tự nhập là Ngoài lịch. Đổi giờ = dời lịch: giữ nguồn, ghi lịch sử.
- Không trùng giờ trong cùng lớp; trong thời gian vận hành; giáo viên phải rảnh
  toàn bộ khung giờ; offline/hybrid cần phòng + địa chỉ; link có thể bổ sung sau.
- Chỉ sửa được buổi chưa bắt đầu, chưa có dữ liệu vận hành.

**Khoảng trống so với ý tưởng của Owner:**
1. Docs ghi “cửa sổ thời gian chính xác của Trước / Trong / Sau” là **câu hỏi
   mở, chưa có runtime**. Bản thiết kế dùng quy tắc đề xuất (bên dưới).
2. Bản nháp có hình thức “Bất kỳ lúc nào” cho buổi học. Theo docs, hình thức
   buổi chỉ là Offline / Online / Hybrid và hoạt động trực tiếp không được
   “bất kỳ lúc nào”, nên bản thiết kế **không** dùng hình thức này.
3. Bài không có Hoạt động học trực tiếp (vd. bài ôn tập chỉ có Trước/Sau) không
   gắn được buổi trong chương trình → các hoạt động của bài chưa có mốc mở.
4. Thêm buổi cho bài đã có buổi: docs cho phép nhưng chưa nói Trước/Sau tính
   theo buổi nào.

## Quyết định cần Owner (bản thiết kế đang dùng phương án đề xuất)

| # | Câu hỏi | Đề xuất trong bản thiết kế |
|---|---|---|
| D1 | Mốc mở của Trước / Sau | **Trước:** mở khi buổi trong chương trình liền trước kết thúc (hoặc lớp bắt đầu) → hạn lúc vào buổi. **Trong:** đúng giờ buổi. **Sau:** mở khi buổi kết thúc → hạn khi buổi trong chương trình kế tiếp bắt đầu (hoặc lớp kết thúc). Buổi ngoài chương trình không làm mốc. |
| D2 | Quá hạn thì sao | Chỉ đánh dấu trễ, không khóa (như bản nháp 3) — chưa thể hiện trong bản tĩnh. |
| D3 | Bài có nhiều buổi | Dùng chung 1 Trước (theo buổi đầu) và 1 Sau (theo buổi cuối); điểm danh tính riêng từng buổi. |
| D4 | Bài không có hoạt động trực tiếp | Chưa xếp buổi được; hiển thị cảnh báo trong “Theo chương trình”. Phương án: (a) bắt buộc bài Theo lịch có hoạt động trực tiếp khi xuất bản, hoặc (b) cho các hoạt động của bài mở theo khoảng giữa hai buổi liền kề, hoặc (c) thêm loại “khoảng tự học” (cần ADR). |
| D5 | Dời lịch có tính lại mốc không | Có: mốc Trước/Sau của buổi đó và buổi liền kề tính lại theo giờ mới; popup hiện trước phần “Ảnh hưởng tới buổi liền kề”. Học viên đã nộp trong khoảng cũ giữ nguyên kết quả. |

## Thiết kế

1. **Đầu tab:** tên lớp, phiên bản khóa, loại, thời gian vận hành; tab lớp;
   nút “Tạo từ lịch học” và “Thêm buổi học”.
2. **Dải tổng hợp:** Bài học đã có buổi (x/y bài có hoạt động trực tiếp + thanh
   tiến độ), Buổi học (đã diễn ra / sắp tới), Cần xử lý (+ số ngày dự kiến chưa
   tạo), Buổi tiếp theo.
3. **Hai kiểu xem:**
   - *Theo thời gian:* nhóm theo tuần; mỗi buổi có ngày giờ (giờ gốc gạch
     ngang nếu đã dời), tên + bài + hoạt động, chip Trước n / Trong n / Sau n,
     nhãn Theo lịch / Đã điều chỉnh / Ngoài lịch, hình thức, giáo viên, trạng
     thái và việc cần xử lý (chưa có GV, chưa có link, thiếu phòng). **Ngày dự
     kiến chưa tạo** của lịch hiện xen kẽ, có nút “Tạo buổi”. Bấm ⌄ để mở 3 cột
     Trước / Trong / Sau với mốc mở–hạn và danh sách hoạt động (bắt buộc / tùy
     chọn, điều kiện hoàn thành). Lọc: Tất cả · Sắp diễn ra · Đã diễn ra · Cần xử
     lý · Chưa tạo từ lịch; tìm theo buổi, bài, giáo viên.
   - *Theo chương trình:* từng bài theo thứ tự; các buổi của bài (bấm để mở);
     “Xếp buổi” cho bài chưa có buổi, “Thêm buổi” cho bài đã có; bài không có
     hoạt động trực tiếp hiện cảnh báo D4; cuối danh sách là buổi ngoài chương trình.
4. **Popup Xem/Sửa buổi** (3 cột, form trái + nội dung buổi phải):
   - Nội dung (bài + hoạt động trực tiếp, tên buổi) · Thời gian (ngày, bắt đầu,
     kết thúc) · Hình thức (thẻ Offline / Online / Hybrid + link hoặc phòng/địa
     chỉ) · Giáo viên (thẻ chọn, báo Rảnh / Bận theo khung giờ) · Lịch sử dời lịch.
   - **Đổi ngày giờ ngay trong popup:** khi giờ khác giờ đã lưu, hiện khung
     “Dời lịch: cũ → mới · nhãn đổi thành Đã điều chỉnh” + Lý do dời lịch; khung
     “Ảnh hưởng tới buổi liền kề” (vd. hạn Sau của Bài 3 đổi 09/11 11:00 → 18:00);
     cột phải tính lại mốc Trước/Trong/Sau ngay. Lưu = một lần dời lịch có lịch sử.
   - Buổi đã bắt đầu/đã diễn ra: chỉ xem.
5. **Popup Thêm buổi:** Loại (trong / ngoài chương trình) · Bài học (ghi “đã có
   n buổi / chưa có buổi”, bài không có hoạt động trực tiếp bị khóa) · Hoạt động
   · Tên (gợi ý, sửa được) · Thời gian · Hình thức · Giáo viên. Bài đã có buổi →
   cảnh báo theo D3. Mở từ ngày dự kiến của lịch: giờ khóa theo lịch, gợi ý bài
   kế tiếp chưa có buổi.
6. **Popup Tạo buổi từ lịch:** chọn lịch; bảng ngày dự kiến chưa tạo (chọn ·
   ngày giờ · bài · chip nội dung · hình thức · giáo viên · trạng thái Sẵn sàng /
   Thiếu bài học / Trùng lịch GV / Thiếu phòng học); “Tự xếp theo thứ tự chương
   trình”; phòng/địa chỉ chung cho các buổi offline/hybrid; tiến độ x/y bài;
   nút “Tạo n buổi học” khóa khi còn dòng lỗi (cả batch là một giao dịch).

## Khác biệt so với UI Kit / bản nháp

- Class trang `ses-*` trong `assets/sessions.css`: chip pha (Trước xanh dương,
  Trong màu nhấn, Sau xanh ngọc), dòng buổi, dòng ngày dự kiến (nền sọc), thẻ
  pha, thẻ chọn (radio/checkbox), bảng batch.
- Bản nháp dùng Inter, 22px/600 — đã đổi về Arial và thang chữ UI Kit.
- Bỏ hình thức “Bất kỳ lúc nào”, thêm Hybrid (theo docs).

## Ghi chú khi áp dụng (bước 2)

Chỉ thực hiện khi Owner duyệt bản này **và chốt D1–D5**, rồi yêu cầu “áp dụng
thiết kế `course-cohort-sessions-design` cho tab Buổi học”.
- D1/D3/D5 là thay đổi nghiệp vụ (runtime tính mốc) → cần amendment
  LF-Core-Course / LF-Core-LiveClass trước khi code.
- Gộp “Sửa” và “Dời lịch” vào một popup chỉ là trình bày: server vẫn tách hai
  thao tác (update + reschedule có audit), giữ Origin.
- Giữ nguyên toàn bộ kiểm tra server: trùng giờ, thời gian vận hành, giáo viên
  rảnh, phòng/địa chỉ, khóa sửa sau khi buổi bắt đầu, batch nguyên tử.
- Không nạp CSS/JS từ `docs/`; chuyển presentation vào Shared CSS.

## Bảo trì bản thiết kế

CSS/JS có hậu tố `?v=…`, tăng mỗi lần sửa `assets/`. Thư mục cần quyền 755.

## Kiểm tra đã thực hiện

Mở trang bình thường qua localhost ở 1440×1000 và 375×812: không tràn ngang
(trừ thanh tab tự cuộn), không lỗi console; đo chữ: Arial 12/14/15/18px,
weight 400/500 (dấu × 26px của UI Kit). Đã chạy: mở rộng buổi, sửa giờ Bài 4
(thấy ảnh hưởng tới Bài 3 và Bài 5) rồi lưu, thêm buổi thứ 2 cho Bài 3, tạo buổi
từ ngày dự kiến 13/11 (gợi ý Bài 7), tạo từ lịch với “Tự xếp”, xem theo chương
trình. Chưa kiểm tra bằng trình đọc màn hình.

## Lịch sử thay đổi

- 1.0 — 2026-11-05: bản thiết kế đầu tiên (kế thừa 3 bản nháp của Owner).
