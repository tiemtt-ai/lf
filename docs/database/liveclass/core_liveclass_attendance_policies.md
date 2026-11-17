# Table: core_liveclass_attendance_policies

Version: 1.2

Document Status: Approved

Implementation Status: Partial

Last Updated: 2026-11-17

Document Path: database/liveclass/core_liveclass_attendance_policies.md

## Purpose

Bộ quy tắc điểm danh của một trung tâm: các mốc quyết định khi nào mở điểm danh và khi
nào một lần vào lớp là `present`, `late` hay `absent`. Nghiệp vụ nằm ở
[ADR-0002](../../adr/ADR-0002-LiveClass-Foundation.md) § Attendance Policy And Recording
Amendment (Owner duyệt nghiệp vụ 2026-11-17).

Owner duyệt thiết kế dữ liệu ngày 2026-11-17 và cho bắt đầu code cùng ngày. Migration: `2026_11_17_000200_create_liveclass_attendance_policies`; ràng buộc được kiểm bằng `tests/Integration/LiveClassAttendancePolicyMariaDbTest.php`.

## Relationships

* `Customer 1 → N Attendance Policies`.
* `Attendance Policy 1 → N Cohorts` qua khoá ngoại kép
  `core_course_cohorts (attendance_policy_id, customer_id)`.
  Lớp để trống cột đó dùng bộ mặc định của trung tâm.

## Business Rules

* Mỗi bộ thuộc đúng một trung tâm (`customer_id`). Lớp chỉ được gán bộ của chính trung
  tâm đó; code kiểm tra bằng `TenantContext`, không tin `id` từ request.
* Mỗi trung tâm có đúng một bộ mặc định. Ràng buộc: `is_default` chỉ nhận `1` hoặc
  `NULL`, và `(customer_id, is_default)` là duy nhất. Nhiều dòng `NULL` không trùng nhau,
  nên mỗi trung tâm có tối đa một dòng `1`.
* Bộ mặc định được tạo với các giá trị mặc định dưới đây: migration tạo cho mọi trung
  tâm đang có; trung tâm tạo sau được tạo bộ mặc định ở lần đầu cần đọc, trong một
  transaction, và ràng buộc duy nhất chặn tạo trùng.
* Không xoá được bộ mặc định. Không xoá được bộ đang được lớp tham chiếu (khoá ngoại
  `RESTRICT`). Xoá là xoá thật; bảng không có trạng thái.
* Không đổi được bộ mặc định sang bộ khác bằng cách sửa cờ. Trung tâm sửa giá trị của bộ
  mặc định.
* Tên là duy nhất trong một trung tâm.
* Sửa một bộ không tính lại kết quả điểm danh đã ghi. Bảng này không lưu lịch sử thay
  đổi; `updated_by` và `updated_at` cho biết lần sửa sau cùng.
* `makeup_enabled` và `makeup_within_days` được lưu nhưng chưa có nghiệp vụ đọc chúng
  cho tới khi học bù được duyệt.
* Trạng thái triển khai (2026-11-17): mọi thiết lập đều đã có nghiệp vụ đọc, trừ
  `makeup_enabled` và `makeup_within_days` (chờ nghiệp vụ học bù). Vì vậy tài liệu ghi
  `Partial`.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khoá chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Trung tâm sở hữu. |
| name | VARCHAR(150) NOT NULL | Tên bộ, ví dụ "Lớp buổi tối". |
| is_default | TINYINT(1) NULL | `1` là bộ mặc định của trung tâm; `NULL` là bộ thường. |
| opens_before_minutes | SMALLINT UNSIGNED NOT NULL DEFAULT 15 | Mở điểm danh trước giờ bắt đầu dự kiến. Vào sớm tính là đúng giờ. |
| late_after_minutes | SMALLINT UNSIGNED NOT NULL DEFAULT 10 | Vào sau mốc này là `late`. |
| absent_after_minutes | SMALLINT UNSIGNED NULL DEFAULT 30 | Vào sau mốc này là `absent`. `NULL` là không giới hạn: trễ đến đâu cũng chỉ `late`. |
| auto_absent_after_minutes | SMALLINT UNSIGNED NOT NULL DEFAULT 30 | Sau giờ kết thúc dự kiến bao lâu thì tự ghi `absent` cho người chưa có kết quả. |
| count_from_actual_start | BOOLEAN NOT NULL DEFAULT true | Bật: mốc muộn và vắng tính từ mốc muộn hơn giữa giờ dự kiến và `actual_start_at`. Tắt: luôn tính từ giờ dự kiến. Buổi dùng nút Điểm danh luôn tính từ giờ dự kiến. |
| offline_recording | VARCHAR(20) NOT NULL DEFAULT 'system' | Cách ghi giờ vào lớp ở buổi tại lớp: `system` (mã QR hoặc nút Điểm danh, theo `core_liveclass_sessions.attendance_tool`) hoặc `manual` (giáo viên ghi tay, không có mã và không có nút). |
| online_recording | VARCHAR(20) NOT NULL DEFAULT 'system' | Cách ghi ở buổi trực tuyến: `system` (nút "Vào phòng họp") hoặc `manual`. |
| makeup_enabled | BOOLEAN NOT NULL DEFAULT true | Cho học bù. Chưa có hiệu lực. |
| makeup_within_days | SMALLINT UNSIGNED NULL | Hạn học bù, tính bằng ngày sau buổi. `NULL` là đến khi lớp kết thúc. Chưa có hiệu lực. |
| teacher_edit_days | SMALLINT UNSIGNED NOT NULL DEFAULT 7 | Giáo viên ghi và sửa điểm danh được trong bao nhiêu ngày sau buổi. Quản trị không bị giới hạn. |
| created_by | BIGINT UNSIGNED NULL | Người tạo. `NULL` khi hệ thống tạo bộ mặc định. |
| updated_by | BIGINT UNSIGNED NULL | Người sửa sau cùng. |
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Thời điểm sửa sau cùng. |

Với buổi `hybrid`, người ở lớp theo `offline_recording`, người ở xa theo
`online_recording`.

## Constraints

```sql
PRIMARY KEY (id);
UNIQUE (customer_id, name);
UNIQUE (customer_id, is_default);
UNIQUE (id, customer_id);

FOREIGN KEY (customer_id) REFERENCES saas_customers (id) ON UPDATE RESTRICT ON DELETE RESTRICT;
FOREIGN KEY (created_by) REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT;
FOREIGN KEY (updated_by) REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT;

CHECK (is_default = 1);
CHECK (opens_before_minutes <= 240);
CHECK (late_after_minutes <= 240);
CHECK (absent_after_minutes IS NULL OR (absent_after_minutes >= late_after_minutes AND absent_after_minutes <= 480));
CHECK (auto_absent_after_minutes <= 1440);
CHECK (offline_recording IN ('system', 'manual'));
CHECK (online_recording IN ('system', 'manual'));
CHECK (makeup_within_days IS NULL OR makeup_within_days BETWEEN 1 AND 365);
CHECK (teacher_edit_days <= 365);
```

`CHECK (is_default = 1)` cho qua `NULL`, nên cột chỉ nhận `1` hoặc `NULL`.

SQLite bỏ qua `CHECK`; các ràng buộc này phải được kiểm trên MariaDB 11.4.

## Sample Data

`id=1, customer_id=1, name='Mặc định của trung tâm', is_default=1, opens_before_minutes=15, late_after_minutes=10, absent_after_minutes=30, auto_absent_after_minutes=30, count_from_actual_start=1, offline_recording='system', online_recording='system', makeup_enabled=1, makeup_within_days=NULL, teacher_edit_days=7`

`id=2, customer_id=1, name='Lớp buổi tối', is_default=NULL, late_after_minutes=15, absent_after_minutes=NULL`

## Design Notes

* Không dùng `saas_customer_settings`: bảng đó lưu từng giá trị rời theo khoá, không
  lưu được nhiều bộ có tên và không gán được cho lớp.
* Kết quả điểm danh không lưu bản chụp của bộ quy tắc. Kết quả là giá trị đã ghi, nên
  sửa bộ về sau không làm sai lịch sử.
* Chu kỳ đổi mã QR (30 giây) không nằm ở đây; nó là hằng số của hệ thống.
