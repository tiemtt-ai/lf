# ADR-0028 — Course Window Columns As DATETIME, Lesson Unlock Time In UTC

Version: 1.0

Status: Approved

Implementation Status: Implemented

Last Updated: 2026-11-10

Proposal Date: 2026-11-10

Approval Date: 2026-11-10

Approved By: LearnForge Business/Architecture Owner

Document Path: adr/ADR-0028-Course-Window-Datetime-And-Unlock-UTC.md

Related ADRs:

* [ADR-0012 — Course Template Published Version Snapshot](ADR-0012-Course-Template-Published-Version-Snapshot.md)
* [ADR-0015 — Course Lesson Multiple Prerequisites](ADR-0015-Course-Lesson-Multiple-Prerequisites.md) — `date_based` so sánh mốc UTC đã chụp

---

# Context

Rà soát luồng khóa học (2026-11-10) còn hai lỗi cần migration:

1. **Năm 2038.** MariaDB `TIMESTAMP` chỉ lưu tới `2038-01-19 03:14:07` UTC. Các cột
   mốc thời gian do người dùng nhập hoặc do cộng thời lượng đang là `TIMESTAMP`:
   Product cho nhập thời lượng học / ôn tới 36500 ngày, ngày bán / đăng ký / hiển thị
   nhập tự do, giờ mở khoá bài học nhập tự do. Giá trị sau 2038 bị MariaDB từ chối
   (strict mode) hoặc ghi thành `0000-00-00`, ghi danh hỏng hoặc không tạo được.
2. **Giờ mở khoá bài học.** `date_based` so sánh mốc `unlock_at_snapshot` theo UTC
   (LF-Core-Course, ADR-0015). Trước bản sửa 2026-11-10 (`App\Support\LessonUnlockTime`),
   form ghi thẳng giờ Việt Nam vào `core_course_template_lessons.unlock_at`, nên bài học
   mở trễ 7 giờ. Code mới ghi UTC và hiển thị lại giờ địa phương; các dòng đã lưu
   trước đó vẫn là giờ Việt Nam.

Kiểm trên `learnforge_db` (dev, 2026-11-10): `unlock_at` khác NULL ở 0 Lesson, 0 Activity,
0 `unlock_at_snapshot`; chưa có môi trường production.

# Decision

## 1. Đổi `TIMESTAMP` → `DATETIME` cho các cột mốc thời gian của khóa học

| Bảng | Cột | Nullable |
| --- | --- | --- |
| `core_course_enrollments` | `enrolled_at` | NOT NULL |
| `core_course_enrollments` | `access_starts_at`, `access_ends_at`, `review_starts_at`, `review_ends_at` | NULL |
| `core_course_products` | `sale_starts_at`, `sale_ends_at`, `available_from`, `available_until`, `registration_starts_at`, `registration_ends_at` | NULL |
| `core_course_template_lessons` | `unlock_at` | NULL |
| `core_course_template_activities` | `unlock_at` | NULL |

* Giữ nguyên nullable, default, index, CHECK, trigger; chỉ đổi kiểu.
* `ALTER ... MODIFY` chạy trên connection mặc định của ứng dụng, nên MariaDB đổi giá trị
  theo cùng session time zone mà ứng dụng dùng để ghi: chuỗi giờ đọc ra trước và sau
  migration giống hệt nhau.
* Chỉ chạy trên MySQL/MariaDB. SQLite không phân biệt hai kiểu nên bỏ qua.
* `unlock_at_snapshot` của Version đã là `DATETIME`, không đổi.

## 2. Chuyển `core_course_template_lessons.unlock_at` đã lưu sang UTC

* Cùng migration, sau bước đổi kiểu: mỗi dòng có `unlock_at` được đọc như giờ
  `config('app.timezone')` và ghi lại ở UTC (Việt Nam không có giờ mùa hè: lùi 7 giờ).
* Migration phải phát hành cùng bản code `LessonUnlockTime`. Dòng ghi bằng code mới mà
  chạy migration lần nữa sẽ bị lùi 2 lần, nên migration chỉ chạy một lần như mọi migration.
* `core_course_template_activities.unlock_at` không có đường ghi nào ngoài NULL, không
  cần chuyển.

## 3. Không sửa `unlock_at_snapshot` của Version đã xuất bản

Guardrails Course Rule 7: Published Version là immutable. Snapshot cũ (nếu có) giữ giờ
Việt Nam và mở trễ 7 giờ cho tới khi Template xuất bản Version mới. Hiện có 0 dòng bị ảnh
hưởng và chưa có production, nên không mở ngoại lệ Rule 7.

## Ngoài phạm vi

Các cột `TIMESTAMP` khác có thể nhận mốc tương lai nhưng hiện không ai ghi giá trị do
người dùng chọn, hoặc thuộc Foundation đã Frozen: `core_course_product_relations.starts_at`
/ `ends_at` (luôn NULL), `core_certificate_issued_certificates.expires_at`,
`core_learning_evidence.valid_from` / `valid_until` / `reassessment_due_at`,
`core_assessment_attempts.deadline_at`. Xử lý khi có nghiệp vụ dùng tới.

# Alternatives rejected

* **Kiểm tra ứng dụng chặn mốc sau 2038.** Thời lượng 100 năm đã là luật của Product;
  chặn ở form chỉ đổi lỗi DB thành lỗi nhập, không giải quyết.
* **Lưu `unlock_at` giờ địa phương, đổi lúc so sánh.** Trái LF-Core-Course và ADR-0015
  (mốc UTC đã chụp); Version đã xuất bản không biết múi giờ lúc soạn.
* **Sửa luôn `unlock_at_snapshot`.** Vi phạm Rule 7 mà không có dòng nào cần sửa.

# Compatibility and migration

* Migration `2026_11_10_000100_course_window_columns_to_datetime`.
* `down()`: đổi lại `TIMESTAMP` và cộng lại 7 giờ cho `unlock_at`; từ chối chạy nếu có giá
  trị ngoài khoảng `TIMESTAMP`.
* Cập nhật `LF-SCHEMA-CONTRACT.json` và tài liệu bảng `core_course_enrollments`,
  `core_course_products`, `core_course_template_lessons`, `core_course_template_activities`.
* Kiểm trên MariaDB 11.4 dùng một lần: `schema:drift --connection=mysql`, giá trị trước /
  sau migration, ghi danh có `access_ends_at` sau 2038, test date-based unlock.

# Architecture Review (2026-11-10) — Pass

* Tenant: không đổi `customer_id`, không đổi truy vấn; backfill lọc theo từng dòng.
* Rule 7: không chạm Version đã xuất bản.
* Enrollment, Progress, Product binding: chỉ đổi kiểu, giá trị giữ nguyên.
* Database: đổi kiểu 13 cột, backfill 1 cột; không thêm bảng, không đổi CHECK hay trigger.

# Implementation record

* Migration `2026_11_10_000100_course_window_columns_to_datetime`: `ALTER ... MODIFY`
  từng bảng (MySQL/MariaDB), giữ `NOT NULL DEFAULT CURRENT_TIMESTAMP` của
  `enrolled_at`; backfill `unlock_at` theo `config('app.timezone')`.
* Kiểm trên MariaDB 11.4 dùng một lần (2026-11-10): giá trị Enrollment / Product đọc ra
  giống trước migration, `unlock_at` 08:00 giờ Việt Nam thành 01:00 UTC, ghi được
  `access_ends_at` năm 2126; `down()` từ chối khi còn giá trị sau 2038, lùi được khi hết,
  chạy lại lên được; trigger Enrollment còn nguyên; `schema:drift --connection=mysql` và
  `--docs-only` pass.

# Approval

Approved by the Business/Architecture Owner on 2026-11-10, gồm cả phạm vi 13 cột,
backfill `unlock_at` và quyết định không sửa Version đã xuất bản.
