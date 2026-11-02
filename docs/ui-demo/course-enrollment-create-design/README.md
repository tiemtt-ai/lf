# Course Enrollment — Tạo ghi danh (thiết kế tĩnh)

Version: 1.3

Document Status: Review

Implementation Status: Not Applicable

Last Updated: 2026-11-02

Document Path: ui-demo/course-enrollment-create-design/README.md

## Phạm vi

Bản thiết kế tĩnh cho trang **Tạo ghi danh** (`admin/course-enrollments/create`):
bước chọn học viên & sản phẩm, bước kiểm tra & xác nhận, và màn hình kết quả.
Quy trình theo mục “Static Design” trong [LF-Tech-CSS](../../tech/LF-Tech-CSS.md).

Nguồn đối chiếu hiện trạng: `resources/views/course-enrollments/create.blade.php`
(wizard Alpine `bulkEnrollmentWizard`), chuỗi `lf.LF_bulk_enrollment_*`, ảnh
màn hình Owner gửi ngày 2026-11-02 và dữ liệu API search trên môi trường dev.
Bản thiết kế chỉ đổi trình bày và bố cục, không đổi nghiệp vụ.

## Xem

Mở `index.html` bằng trình duyệt, giữ nguyên cấu trúc `docs/ui-demo/`.
Thanh “Bản thiết kế · dữ liệu minh họa” chỉ có trong bản thiết kế: chọn nhanh
mẫu có ghi danh lại, mẫu sản phẩm đã chọn hết điều kiện, hoặc làm lại từ đầu.
Học viên 1–3 và sản phẩm 1–4 lấy từ dev; các học viên/sản phẩm còn lại, thời
hạn truy cập/ôn tập và các ghi danh cũ (#21, #24, #27) là dữ liệu minh họa.

## Thay đổi so với giao diện hiện tại

**Chung**
1. Stepper 3 bước gọn nằm cùng hàng tiêu đề: Chọn học viên & sản phẩm →
   Kiểm tra & xác nhận → Hoàn tất (màn hình kết quả là bước 3).
2. **Thanh thao tác dính đáy màn hình** ở bước 1–2: công thức
   “học viên × sản phẩm = ghi danh”, thanh đo giới hạn 100 cặp (đổi màu khi
   ≥ 80 và khi vượt), thông báo lý do nút đang khóa, nút Hủy / Quay lại /
   Tiếp tục / Tạo n ghi danh. Thay cho dải tổng ở cuối form và footer tách rời.

**Bước 1 — Chọn học viên & sản phẩm**
3. Ngày ghi danh gọn trên một hàng (nhãn · ô · gợi ý); bỏ khối “Bắt đầu ghi
   danh” lớn — hướng dẫn nằm ở trạng thái khóa của cột Sản phẩm và dòng thông
   báo trên thanh thao tác.
4. Hai cột có cùng chiều cao danh sách (cuộn trong khung), tỷ lệ 5:7 vì sản
   phẩm có nhiều thông tin hơn; tiêu đề cột dùng icon (không đánh số để khỏi
   trùng số bước của stepper).
5. Học viên: avatar chữ cái đầu, chip học viên đã chọn có nút bỏ chọn nhanh.
6. Sản phẩm dạng dòng gọn (khoảng ⅓ chiều cao thẻ hiện tại): tên + badge điều
   kiện cùng hàng; mã · Version · mã phiên bản · thời hạn trên một dòng; khoảng
   đăng ký một dòng có trạng thái màu (hợp lệ / chưa mở / đã đóng); lý do không
   đủ điều kiện hiển thị ngay dưới dòng.
7. Khi chưa chọn học viên, danh sách sản phẩm vẫn xem được nhưng mờ và khóa
   chọn; dòng nhắc nằm cố định phía trên vùng cuộn — thay cho popup
   “Chưa chọn học viên”.
8. Sản phẩm đủ điều kiện lên trên; nhóm không đủ điều kiện thu gọn
   (“Xem n sản phẩm không đủ điều kiện”).
   **Sản phẩm đã chọn nhưng mất điều kiện** (do đổi ngày hoặc thêm học viên):
   - Tách thành nhóm riêng trên cùng danh sách “Đã chọn nhưng không còn đủ điều
     kiện (n)”, viền đỏ bên trái, nút “Bỏ chọn” ngay trong dòng.
   - Lý do chỉ nói một lần và luôn so với ngày ghi danh đã chọn: lỗi khoảng
     đăng ký nằm ngay ở dòng “Đăng ký: …” (vd. “Đã đóng trước ngày ghi danh
     05/12/2026 09:00”); lỗi do học viên đang có ghi danh hoạt động hiển thị
     một dòng riêng có mã ghi danh.
   - Cảnh báo đầu trang: tiêu đề nêu tên sản phẩm (1 mục) hoặc số lượng
     (nhiều mục), câu lý do đầy đủ, hai cách sửa: “Đổi ngày ghi danh” (chỉ hiện
     khi lỗi liên quan khoảng đăng ký, bấm sẽ đưa con trỏ tới ô ngày) và
     “Bỏ chọn sản phẩm này / Bỏ chọn n sản phẩm”.
   - Bộ đếm đầu cột ghi rõ “Đã chọn x/y đủ điều kiện · n cần xử lý”.
9. Trạng thái “Đang kiểm tra…” hiển thị ngay đầu danh sách sản phẩm khi đổi
   học viên hoặc ngày ghi danh.

**Bước 2 — Kiểm tra & xác nhận**
10. Bỏ 4 ô số liệu và tiêu đề trùng stepper; thay bằng một dải thông tin chung:
    Ngày ghi danh · Trạng thái sau khi tạo · Nguồn ghi danh · nút Đổi lựa chọn.
11. Danh sách **nhóm theo sản phẩm**: mỗi nhóm có một dòng tiêu đề gồm tên, mã ·
    Version · mã phiên bản, thời gian truy cập/ôn tập và số học viên — không lặp
    lại ở từng dòng. Bảng chỉ còn STT · Học viên · Kết quả dự kiến.
12. “Ghi danh mới” hiển thị nhẹ (chữ xám, icon +); chỉ “Ghi danh lại” dùng badge
    màu, dòng chưa xác nhận được tô nền. Dòng tóm tắt dưới tiêu đề:
    “n ghi danh mới · m ghi danh lại, k chưa xác nhận”.
13. Khi có ghi danh lại: tab lọc Tất cả / Cần xác nhận (có số đếm) và nút
    “Xác nhận tất cả ghi danh lại” qua popup UI Kit 1 cột. Nút Tạo hiển thị số
    lượng (“Tạo 6 ghi danh”) và chỉ bật khi mọi cặp ghi danh lại đã xác nhận.
14. Ghi chú nội bộ thu gọn thành liên kết “+ Thêm ghi chú nội bộ”, mở ô nhập khi cần.
15. Thanh thao tác bước 2 bỏ thanh đo giới hạn 100 cặp; thông báo nhắc ngày ghi
    danh hoặc số cặp còn cần xác nhận. Mã sản phẩm/phiên bản không bị ngắt dòng.

**Bước 3 — Hoàn tất**
16. Banner thành công gom thông tin hoàn tất, ngày ghi danh, trạng thái, nguồn;
    bảng kết quả nhóm theo sản phẩm như bước 2, cột Kết quả (mới / ghi danh lại
    từ #id) và Mã ghi danh.

**Mobile ≤ 680px:** hai cột xếp dọc, bảng chuyển thành thẻ, thanh thao tác
dính đáy với nút giãn đều; stepper chỉ hiện nhãn bước hiện tại.

## Khác biệt so với UI Kit

- Class trang `enr-*` trong `assets/enrollment.css`: stepper, checklist, dòng
  chọn có avatar, chip đã chọn, nhóm thu gọn, thanh thao tác dính đáy + thanh đo,
  ô tổng hợp, badge trạng thái, banner thành công, toast. Màu dùng lại
  `--ui-primary` và bảng màu trung tính của kit; xanh/vàng/đỏ cho trạng thái.
- Biến thể nút `ui-button--sm` (30px) cho thao tác phụ trong khối.
- Bảng căn trái, không vạch dọc ở thân bảng.

## Ghi chú khi áp dụng (bước 2)

Chỉ thực hiện khi Owner duyệt bản này và yêu cầu “áp dụng thiết kế
`course-enrollment-create-design` cho trang Tạo ghi danh”.

- Giữ nguyên các endpoint `students.search`, `products.search`, `bulk-preflight`,
  `bulk-invalidate`, `bulk-store`, `submission_token`, cấu trúc
  `reenrollment_confirmations[]`, giới hạn 1–100 cặp và mọi kiểm tra phía server.
  Logic điều kiện trong `assets/enrollment.js` chỉ để minh họa.
- Phân trang học viên/sản phẩm/nhóm không đủ điều kiện vẫn theo server
  (bản thiết kế chỉ hiển thị “Trang 1 / 1”).
- Dòng tiêu đề nhóm sản phẩm ở bước 2–3 lấy thời gian từ `pair.time_windows`
  của preflight (cùng sản phẩm + cùng ngày ghi danh thì giống nhau); nếu server
  trả thời gian khác nhau trong cùng nhóm thì hiển thị ở từng dòng.
- Thay popup “Chưa chọn học viên” bằng trạng thái khóa trong danh sách cần giữ
  thông báo cho trình đọc màn hình (aria-live) như hiện tại.
- Không nạp CSS/JS từ `docs/`; chuyển presentation vào Shared CSS theo LF-Tech-CSS.

## Bảo trì bản thiết kế

Đường dẫn CSS/JS trong `index.html` có hậu tố `?v=<Version>`. Mỗi lần sửa
`assets/` phải tăng Version ở README và hậu tố này, để trình duyệt không dùng
file cũ trong cache (HTML mới + JS cũ sẽ làm hỏng giao diện).

## Kiểm tra đã thực hiện

Mở qua localhost ở 1440×1000 và 375×812: không tràn ngang; đi hết luồng mẫu
ghi danh lại (chọn → xác nhận tất cả → tạo → kết quả) và mẫu sản phẩm hết điều
kiện (nút Tiếp tục khóa, cảnh báo hiện). Chưa kiểm tra bằng trình đọc màn hình,
chưa đo pixel so với UI Kit.

## Lịch sử thay đổi

- 1.0 — 2026-11-02: bản thiết kế đầu tiên.
- 1.1 — 2026-11-02: thu gọn hàng Ngày ghi danh, bỏ checklist trùng với stepper,
  đổi số ①② ở tiêu đề cột thành icon, dòng nhắc khóa sản phẩm không cuộn mất.
- 1.2 — 2026-11-02: làm lại thông báo sản phẩm đã chọn không còn đủ điều kiện
  (nhóm riêng trên cùng, lý do một lần có so với ngày ghi danh, thêm cách sửa
  “Đổi ngày ghi danh”, bộ đếm “cần xử lý”).
- 1.3 — 2026-11-02: bước 2 gọn lại — dải thông tin chung thay 4 ô số liệu, nhóm
  danh sách theo sản phẩm, tab Cần xác nhận, ghi chú thu gọn; bước 3 nhóm tương tự.
