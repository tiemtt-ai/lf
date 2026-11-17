# Table: core_liveclass_attendance_changes

Version: 1.1

Document Status: Approved

Implementation Status: Implemented

Last Updated: 2026-11-17

Document Path: database/liveclass/core_liveclass_attendance_changes.md

## Purpose

Lịch sử sửa điểm danh, chỉ thêm: ai đổi kết quả của một học viên ở một buổi, lúc nào, từ
gì sang gì. Nghiệp vụ nằm ở
[ADR-0002](../../adr/ADR-0002-LiveClass-Foundation.md) § Attendance Policy And Recording
Amendment (Owner duyệt nghiệp vụ 2026-11-17).

Owner duyệt thiết kế dữ liệu ngày 2026-11-17 và cho bắt đầu code cùng ngày. Migration: `2026_11_17_000300_create_liveclass_attendance_changes`; ràng buộc được kiểm bằng `tests/Integration/LiveClassAttendanceChangeMariaDbTest.php`.

## Relationships

* `Attendance 1 → N Attendance Changes`.
* `session_id` và `enrollment_id` được chép từ Attendance để đọc lịch sử của một buổi
  hoặc một học viên mà không cần join.

## Business Rules

* Một dòng được thêm trong cùng transaction với thay đổi của
  `core_liveclass_attendances`, khi:
  - một người ghi tay, kể cả lần ghi tay đầu tiên cho học viên chưa có kết quả;
  - hệ thống đổi một kết quả đã có (ví dụ học bù về sau).
* Lần ghi đầu tiên của hệ thống (quét mã QR, "Vào phòng họp", tự ghi vắng) không tạo
  dòng nào: chính dòng Attendance đã là bản ghi đó.
* Một lần lưu không đổi trạng thái, hình thức hay ghi chú thì không tạo dòng.
* Dòng không bao giờ bị sửa hay xoá. Ứng dụng không có đường ghi nào ngoài thêm mới.
* `changed_by` bắt buộc khi `change_source = manual` và là `NULL` khi `system`.
* `customer_id` phải trùng với Attendance.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khoá chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Trung tâm sở hữu. |
| attendance_id | BIGINT UNSIGNED NOT NULL | Kết quả điểm danh bị đổi. |
| session_id | BIGINT UNSIGNED NOT NULL | Buổi học, chép từ Attendance. |
| enrollment_id | BIGINT UNSIGNED NOT NULL | Enrollment, chép từ Attendance. |
| old_status | VARCHAR(30) NULL | Trạng thái trước khi đổi. `NULL` khi trước đó chưa có dòng Attendance. |
| new_status | VARCHAR(30) NOT NULL | Trạng thái sau khi đổi: `present`, `late`, `absent` hoặc `excused`. |
| old_attendance_mode | VARCHAR(20) NULL | Hình thức trước khi đổi (`online`, `offline`). |
| new_attendance_mode | VARCHAR(20) NULL | Hình thức sau khi đổi. |
| notes | VARCHAR(1000) NULL | Ghi chú của kết quả sau khi đổi. |
| change_source | VARCHAR(20) NOT NULL | `manual` hoặc `system`. |
| changed_by | BIGINT UNSIGNED NULL | Người sửa. |
| created_at | TIMESTAMP NOT NULL | Thời điểm sửa. |

Không có `updated_at`: dòng không bao giờ được sửa.

## Constraints

```sql
PRIMARY KEY (id);
INDEX (customer_id, attendance_id, created_at);
INDEX (customer_id, session_id, created_at);

FOREIGN KEY (customer_id) REFERENCES saas_customers (id) ON UPDATE RESTRICT ON DELETE RESTRICT;
FOREIGN KEY (attendance_id) REFERENCES core_liveclass_attendances (id) ON UPDATE RESTRICT ON DELETE RESTRICT;
FOREIGN KEY (session_id) REFERENCES core_liveclass_sessions (id) ON UPDATE RESTRICT ON DELETE RESTRICT;
FOREIGN KEY (enrollment_id) REFERENCES core_course_enrollments (id) ON UPDATE RESTRICT ON DELETE RESTRICT;
FOREIGN KEY (changed_by) REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT;

CHECK (old_status IS NULL OR old_status IN ('registered', 'present', 'late', 'absent', 'excused'));
CHECK (new_status IN ('present', 'late', 'absent', 'excused'));
CHECK (old_attendance_mode IS NULL OR old_attendance_mode IN ('online', 'offline'));
CHECK (new_attendance_mode IS NULL OR new_attendance_mode IN ('online', 'offline'));
CHECK (change_source IN ('manual', 'system'));
CHECK ((change_source = 'manual' AND changed_by IS NOT NULL) OR (change_source = 'system' AND changed_by IS NULL));
```

SQLite bỏ qua `CHECK`; các ràng buộc này phải được kiểm trên MariaDB 11.4.

## Sample Data

`id=1, customer_id=1, attendance_id=40, session_id=30, enrollment_id=40, old_status='absent', new_status='excused', old_attendance_mode=NULL, new_attendance_mode=NULL, notes='Có đơn xin nghỉ', change_source='manual', changed_by=12, created_at='2026-11-18 02:10:00'`

## Design Notes

* Cùng kiểu với `core_liveclass_session_schedule_changes`: bằng chứng chỉ-thêm, không
  trigger; tính chỉ-thêm do ứng dụng bảo đảm.
* `new_status` không nhận `registered`: một kết quả đã có không đưa được về "chưa điểm
  danh" (ADR-0002 § Review additions, điểm 3, Owner xác nhận 2026-11-17). `old_status`
  vẫn nhận `registered` cho dòng cũ đang ở trạng thái đó.
* Dòng tồn tại khiến Attendance không xoá được (`RESTRICT`), đúng với quy tắc Attendance
  được giữ làm lịch sử.
