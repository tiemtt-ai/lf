# Audit: xuất Excel cho danh sách P1

Ngày: 2026-11-07 · Audit Level: **HIGH** (đọc dữ liệu tenant, thêm route, sửa query của nhiều module) ·
Không có thay đổi schema hay migration. Đề xuất gốc: [LF-List-Excel-Export-Proposal.md](LF-List-Excel-Export-Proposal.md).

## Phạm vi đã làm

| Danh sách | Route mới | Ai dùng | Ghi chú |
| --- | --- | --- | --- |
| Người dùng | `GET admin/users/export` → `admin.users.export` | Admin | Theo tab vai trò, từ khóa, trạng thái |
| Ghi danh khóa học | `GET admin/course-enrollments/export` → `admin.course-enrollments.export` | Admin | Theo mọi bộ lọc của trang (cả bộ lọc nâng cao) |
| Học viên của một lớp | `GET admin/course-cohorts/{id}/students/export` → `admin.course-cohorts.students.export` | Admin | Học viên đang học (active), theo từ khóa của tab |
| Điểm danh của lớp | `GET admin/course-cohorts/{id}/attendance/export` → `admin.course-cohorts.attendance.export` | Admin | Ma trận học viên × buổi; 404 khi `liveclass.attendance_enabled` tắt |
| Kết quả kiểm tra | `GET admin\|teacher/assessments/results/export` → `*.assessments.results.export` | Admin, giáo viên | Theo tab Chờ chấm, từ khóa, đề, trạng thái |

**Không làm:** "Học viên trong lớp (toàn tenant)" (`admin.course-cohort-students.index`) — trang này không có lối vào từ menu hay trang khác, chỉ mở được bằng URL; xuất theo từng lớp đã thay thế nhu cầu này.

## Thiết kế

- `app/Support/XlsxDownload.php` (mới): nhận header + rows, ghi bằng `SimpleXlsx::write()`, đặt tên file `slug-YmdHis.xlsx`, `Cache-Control: no-store`, làm sạch tên sheet (≤31 ký tự, bỏ `:\/?*[]`). `MAX_ROWS = 5000`.
- Mỗi controller tách phần đọc bộ lọc và dựng query của `index()` thành `listFilters()` + `listQuery()`; `index()` và `export()` cùng dùng, nên file xuất không thể lệch phạm vi so với danh sách. Riêng hai file của lớp nằm trong controller mới `CourseCohortExportController` (chỉ đọc).
- Giá trị được ghi dạng inline string, nên Excel không chạy công thức trong dữ liệu (không có rủi ro formula injection).
- Nút "Xuất Excel" nằm trong live region, nên link cập nhật theo bộ lọc khi tìm kiếm trực tiếp.
- `AssessmentQuestionImportController` (xuất câu hỏi có sẵn) **không đổi**, để giữ phạm vi nhỏ.

## Tenant và phân quyền

- Mọi query lọc theo `TenantContext::customerId()` và join kèm `customer_id`; không nhận `customer_id` từ request.
- Lớp của tenant khác trả về 404 (`findCohort` lọc theo tenant).
- Route admin nằm trong group `role:customer_admin`; controller tự kiểm tra lại (`authorizeAdmin` / `customer_admin`).
- Kết quả kiểm tra dùng `AssessmentGradingService::scoped()`: giáo viên chỉ nhận bài của đề mình tạo hoặc của ghi danh mình cấp, đúng như danh sách.

## Kiểm chứng

| Lệnh / kiểm tra | Kết quả |
| --- | --- |
| `php artisan test --filter=test_export_follows` (Người dùng, Ghi danh) | PASS (2 test, 29 assertion) |
| `php artisan test --filter=test_cohort_roster_and_attendance_export…` | PASS (30 assertion) |
| `php artisan test --filter=test_export_follows_the_list_scope…` (Kết quả) | PASS (45 assertion) |
| `php artisan test` (toàn bộ, SQLite in-memory) | xem mục Full suite |
| `./vendor/bin/pint` các file đã đổi | Đã chạy, chỉ sửa thứ tự import trong phần code mới |
| `git diff --check` | Sạch |
| `php artisan route:list` | 6 route `*.export` mới đăng ký đúng tên |
| Trình duyệt (hkca.localhost:8010, admin) | Người dùng, Ghi danh, Kết quả, Học viên lớp 10–15: HTTP 200, đúng MIME `.xlsx`, đúng tên file. Link xuất đổi theo bộ lọc khi gõ tìm kiếm (Người dùng, Ghi danh). Ảnh chụp: nút nằm cạnh các nút hành động hiện có |

Các test bao phủ: đúng cột tiêu đề; dữ liệu theo bộ lọc; dữ liệu tenant khác không xuất hiện; lớp của tenant khác trả 404; giáo viên hoặc học viên gọi route admin bị 403; giáo viên chỉ nhận bài trong phạm vi của mình; ma trận điểm danh (buổi chưa bắt đầu không có trong file, chưa điểm danh hiện "Dự kiến", tổng số và tỉ lệ có mặt đúng); điểm danh tắt thì trả 404.

## Full suite

`php artisan test` (SQLite in-memory, chạy sau phần xuất Excel): **1783 passed, 23 skipped, 0 failed** (270 giây).

## Bổ sung: UI trang Kết quả & Chấm bài (Owner duyệt "Sửa cả 5")

1. Nút Xuất Excel chuyển lên cùng hàng với các tab (`ui-result-tabbar`), vẫn là live region nên link đổi theo bộ lọc.
2. Độ rộng cột: STT 56, Học viên 176 (email dài bị cắt bằng dấu …), Điểm 164, Trạng thái 116, Thao tác 88; cột Đề dùng phần còn lại.
3. Cột Điểm: điểm in đậm; nhãn màu Đạt (xanh) hoặc Chưa đạt (đỏ); bài còn câu chờ chấm ghi "Tạm tính".
4. Thao tác: dùng icon theo UI Kit (Owner chọn sau khi đã thử nút chữ "Chấm"): `square-pen` cho bài chờ chấm (tooltip "Chấm bài"; `pencil` bị test UI Kit cấm), `eye` cho bài khác, thay cho `sliders`.
5. ~~Banner "Có N bài chờ chấm"~~: đã làm rồi bỏ theo Owner ("quá thừa"; tab Chờ chấm đã có số đếm). Đã xóa view, khóa ngôn ngữ, CSS và truy vấn `oldestPendingId`.

Kiểm chứng: `tests/Feature/Assessment` cùng test Người dùng và Ghi danh đạt 163 passed. Test mới `test_results_page_puts_pending_grading_first_and_marks_the_verdict` (nay là `test_results_page_marks_pending_grading_and_the_verdict`) kiểm tra không còn banner, có thao tác Chấm bài, nhãn Chưa đạt và Tạm tính. Các test đọc CSS UI Kit (PHP 82 passed, `node --test tests/js/admin-ui-kit-css.test.mjs` 6/6) và Pint đều đạt. Đã xem trên trình duyệt ở khổ hẹp và khổ 1440px, tab Chờ chấm và tìm kiếm trực tiếp.
Sau khi sửa UI chưa chạy lại full suite.
Lỗi đã gặp và đã sửa: tên class `ui-result-grade` trùng với form chấm ở trang chi tiết, nên đổi thành `ui-result-grade-link`.

## Chưa kiểm chứng / rủi ro còn lại

- **Điểm danh trên dev:** cờ `liveclass.attendance_enabled` đang tắt, nên tab và route xuất điểm danh trả 404 trên hkca. Chức năng chỉ được kiểm chứng bằng test (bật cờ).
- **Chưa thử trên Excel thật / MariaDB:** chưa mở file bằng Excel hay Numbers; nội dung được đọc lại bằng `SimpleXlsx::read()`. Query chưa chạy trên MariaDB với dữ liệu lớn.
- **Tải toàn bộ vào bộ nhớ:** tối đa 5000 dòng mỗi file. Tenant lớn hơn sẽ bị cắt mà không có thông báo; nên lọc trước khi xuất.
- **Không ghi log:** chưa ghi lại ai xuất file. File Người dùng và Ghi danh có email, số điện thoại và ngày sinh.

## Bổ sung: thao tác hàng loạt của trang Ghi danh khóa học

Ba nút Tạm dừng, Kích hoạt lại và Hủy ghi danh chuyển từ thanh nổi `ui-enroll-bulkbar` lên thanh công cụ của danh sách, theo thứ tự Tạo ghi danh → thao tác hàng loạt → Xuất Excel, giống nút Xóa của các danh sách khác. Các nút luôn hiển thị nhưng bị mờ cho đến khi các dòng đã chọn cho phép thao tác; nút đang dùng được có kèm số dòng, ví dụ "Tạm dừng (1)". Đã bỏ nút "Bỏ chọn" (checkbox ở tiêu đề bảng đã làm việc này) và xóa CSS `ui-enroll-bulkbar`. Phần logic Alpine và popup xác nhận giữ nguyên.
Kiểm chứng: `CourseEnrollmentManagementTest` 53 passed (thêm assertion về thứ tự nút trên thanh công cụ); Node 338 pass. Trên trình duyệt: chọn 1 ghi danh đang hoạt động thì Tạm dừng và Hủy bật, Kích hoạt lại vẫn mờ; mở popup Tạm dừng rồi bấm Hủy (không gửi), focus quay lại nút.

## Bổ sung: trang Thống kê (Owner duyệt "Làm theo đề xuất")

Logic (`AssessmentStatisticsService`):
- Điểm trung bình, Thấp nhất – Cao nhất và Tỉ lệ đạt chỉ tính các bài đã chấm xong (`grading_status` khác `pending`). Trước đây có tính cả điểm tạm của bài còn chờ chấm, trong đó câu chưa chấm bị tính 0 nên làm điểm thấp đi. Trên dev, điểm trung bình đổi từ 42.8% thành 49.9%, tính trên 4 bài đã chấm xong.
- Summary thêm `graded`; `pending` = số bài nộp trừ số bài đã chấm xong.
- Câu tự luận và câu nói: cột Số bài ghi "đã chấm / đã nộp" (ví dụ 4 / 7), khớp với cơ số dùng để tính tỉ lệ.
- Mức Dễ / Vừa / Khó chỉ hiện khi câu có ít nhất `MIN_FOR_LEVEL = 5` bài được tính; ít hơn thì hiện "— Chưa đủ dữ liệu".

UI: số trong ô tổng quan dùng cỡ chữ 20/28, đậm 600, giống `ui-summary__item--title dd` của UI Kit. Cột tỉ lệ đổi tiêu đề thành "Tỉ lệ đúng / Điểm TB" và bỏ dòng "làm đúng" lặp ở mỗi dòng (đã xóa 3 khóa ngôn ngữ không còn dùng). Nhãn Vừa chuyển sang màu trung tính; Dễ xanh và Khó đỏ giữ như cũ. Lời giới thiệu trang và chú thích bảng nói rõ cách tính mới.

Kiểm chứng: `tests/Feature/Assessment` 104 passed. Test thống kê cũ được sửa theo logic mới; test mới `test_levels_need_enough_answers_and_scores_wait_for_grading` kiểm tra trường hợp chưa có bài chấm xong, ngưỡng 5 bài và màu Dễ/Khó/Vừa. Test CSS bằng Node đạt, Pint đạt. Đã xem trên trình duyệt ở khổ 1440px với đề TOPIK demo. Full suite sau mọi thay đổi trong ngày: xem dòng dưới.
Có một lỗi gặp trong lúc làm và đã sửa: `@class` với chuỗi rỗng sinh ra `class="ui-status "`, nên đã thêm `array_filter`.

Full suite sau mọi thay đổi trong ngày: 1784 passed, 23 skipped, 1 failed. Test lỗi là `BackendLayoutNavigationTest::test_every_edit_action_uses_the_square_pen_icon`, vì UI Kit cấm icon `pencil`. Đã đổi sang `square-pen`; chạy lại `BackendLayoutNavigationTest` và `tests/Feature/Assessment` được 131 passed.
