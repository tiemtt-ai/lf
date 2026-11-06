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

_(cập nhật sau khi chạy xong)_

## Chưa kiểm chứng / rủi ro còn lại

- **Điểm danh trên dev:** cờ `liveclass.attendance_enabled` đang tắt, nên tab và route xuất điểm danh trả 404 trên hkca. Chức năng chỉ được kiểm chứng bằng test (bật cờ).
- **Chưa thử trên Excel thật / MariaDB:** chưa mở file bằng Excel hay Numbers; nội dung được đọc lại bằng `SimpleXlsx::read()`. Query chưa chạy trên MariaDB với dữ liệu lớn.
- **Tải toàn bộ vào bộ nhớ:** tối đa 5000 dòng mỗi file. Tenant lớn hơn sẽ bị cắt mà không có thông báo; nên lọc trước khi xuất.
- **Không ghi log:** chưa ghi lại ai xuất file. File Người dùng và Ghi danh có email, số điện thoại và ngày sinh.
