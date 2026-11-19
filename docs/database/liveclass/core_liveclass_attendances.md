# Table: core_liveclass_attendances

Document Path: database/liveclass/core_liveclass_attendances.md

## Cohort-Centered Amendment — 2026-07-25

Attendance always belongs to a Session and an Enrollment that is an active
member of that Session's Cohort when attendance is first recorded.
`version_activity_id` is derived from Session and is nullable. Attendance can
be Course Activity Evidence only when that value exists. Canonical statuses are
`registered`, `present`, `late`, `absent`, `excused`; attendance mode is
`online` or `offline`.

## Check-In Amendment — 2026-11-09

ADR-0002 § Attendance Check-In Amendment. No column or migration.

* A QR check-in writes `status` (`present` or `late`), `joined_at` (the server time
  when the code was received), `attendance_mode = offline` and
  `attendance_source = system`.
* The physical table has no `attendance_method` column, although the field list
  below documents one. The way a learner checked in is read from
  `attendance_mode` and `attendance_source`:
  - `offline` + `system`: QR scan in the room;
  - `online` + `system`: "Vào phòng họp" (later phase);
  - `manual`: set by a teacher or administrator.
* A row that already has `joined_at`, or that was set by hand, is not changed by a
  later scan. A manual save rewrites only the rows it changes.
* Only an Enrollment with `status = active` is recorded, by a scan or by a manual
  save.

## Policy And Online Entry Amendment — 2026-11-17

ADR-0002 § Attendance Policy And Recording Amendment (Owner approved the business rules
on 2026-11-17). No column and no migration on this table. Implemented 2026-11-17
(`LiveClassAttendanceRecorder`), behind `liveclass.attendance_enabled`.

* The opening time and the late and absent thresholds come from the Cohort's
  [attendance policy](core_liveclass_attendance_policies.md), no longer from
  `liveclass.late_after_minutes`.
* "Vào phòng họp" writes `attendance_mode = online`, `attendance_source = system`,
  `joined_at` (server time) and `status` `present`, `late` or `absent`. An entry
  after the absent threshold is stored as `absent` **with** its `joined_at`.
* The "Điểm danh" button of an in-room Session
  (`core_liveclass_sessions.attendance_tool = button`) writes the same values as
  "Vào phòng họp" but with `attendance_mode = offline`. `offline` + `system` is
  therefore a QR scan or a button press, told apart by the Session's tool.
* A learner opening the Whiteboard of an `online` Session writes the same values
  as "Vào phòng họp" (`online` + `system`). Opening the board of an `offline` or
  `hybrid` Session writes nothing. In a `hybrid` Session an in-room check-in by a
  learner already recorded as `online` updates only `attendance_mode` to
  `offline`; it keeps `joined_at` and `status`, and adds a `system` row to the
  change history.
* A QR scan after the absent threshold is refused and writes nothing.
* Automatic absence writes `status = absent`, `attendance_source = system`,
  `joined_at = NULL`, for a member with no row or a `registered` row.
* A teacher with an active assignment to the Cohort may write manual attendance
  within the policy's edit window; `customer_admin` always may. A manual row is
  never replaced by a scan, an online entry or automatic absence.
* Every manual write, and every change of an existing result, adds a row to
  [core_liveclass_attendance_changes](core_liveclass_attendance_changes.md) in the
  same transaction.

## Attendance By Content And Make-Up Amendment — 2026-11-18

Implemented 2026-11-18 (`LiveClassAttendanceRecorder::contentChanged()`):
[ADR-0022 Amendment 1.3, Đợt 3](../../adr/ADR-0022-Course-Type-On-Template.md#đợt-3--tính-vào-điểm-danh-và-học-bù)
(Owner duyệt nghiệp vụ 2026-11-18). No column and no migration on this table.

* `attendance_source` nhận thêm giá trị `makeup`: kết quả `present` do học bù. Cột không
  có CHECK nên không cần migration.
* Kết quả đang chờ là dòng `status = registered` có `joined_at` và `attendance_mode`:
  học viên đã vào lớp nhưng chưa xong Activity phần Trong được tick "Tính vào điểm danh".
  Dòng `registered` không có `joined_at` vẫn là "chưa có kết quả".
* Tự ghi vắng chuyển dòng đang chờ sang `absent` và giữ `joined_at`.
* Học bù ghi `status = present`, `attendance_source = makeup`, giữ `joined_at` và
  `attendance_mode` đã có, và thêm một dòng `system` vào
  [core_liveclass_attendance_changes](core_liveclass_attendance_changes.md).
* Dòng `makeup` được giáo viên hoặc quản trị sửa tay như mọi dòng khác; lần vào lớp sau
  đó không thay nó.

## Self-Study Session Amendment — 2026-11-18

Implemented 2026-11-18
([ADR-0022 Amendment 1.3, Đợt 4, Bước 3](../../adr/ADR-0022-Course-Type-On-Template.md#đợt-4--buổi-thực-hành-và-buổi-tự-học-có-lịch)).
No column and no migration on this table.

* Kết quả của buổi `self_study` có `version_activity_id = NULL` và `attendance_mode = NULL`.
* Hệ thống ghi `present` với `attendance_source = system` và `joined_at` là lúc học viên xong
  Activity được tick cuối cùng, hoặc lúc bấm nút Điểm danh khi bài không tick Activity nào.
* Học bù ghi `present` với `attendance_source = makeup`, `joined_at = NULL`.
* Không có `late`. Tự ghi vắng và ghi tay như buổi thường.

## Purpose

Ghi nhận trạng thái và thời lượng một học viên tham gia một LiveClass Session.

Attendance là dữ liệu vận hành có thể làm đầu vào cho Course Activity Progress;
nó không phải bản ghi completion của Course.

## Relationships

```text
Customer 1 → N LiveClass Attendances

LiveClass Session 1 → N LiveClass Attendances

LiveClass Room 1 → N LiveClass Attendances

Course Enrollment 1 → N LiveClass Attendances

User 1 → N LiveClass Attendances

Version Activity 1 → N LiveClass Attendances
```

## Business Rules

* Attendance phải thuộc `customer_id`.
* Chỉ tạo Attendance khi Enrollment đang `active`.
* Attendance, Enrollment, Session và Room phải thuộc cùng tenant.
* `product_id` và `template_version_id` phải khớp Enrollment và Session.
* `user_id` phải khớp `student_id` của Enrollment.
* `version_activity_id` phải khớp Session và có `activity_type = live_class`.
* Một Enrollment chỉ có một Attendance record cho một Session.
* `left_at` không được trước `joined_at`.
* `duration_seconds` là tổng thời lượng hiện diện đã chuẩn hóa từ provider,
  manual entry hoặc system aggregation; không nhất thiết bằng
  `left_at - joined_at` khi có nhiều lần join.
* `attendance_source` mô tả nguồn dữ liệu; `attendance_method` mô tả cách điểm
  danh.
* Allowed `attendance_method`: `provider`, `manual`, `qr`, `face`, `gps`,
  `system`.
* `attendance_percentage` phải nằm trong khoảng `0.00` đến `100.00`.
* `attendance_percentage` là read-model và không thay thế Course Activity
  Progress.
* Nếu `status` là `present` hoặc `late`, phải có `joined_at` hoặc
  `attendance_source = manual`.
* Attendance sau khi tạo được giữ lại khi Enrollment hết active để bảo toàn
  lịch sử learning cycle.
* Attendance có thể kích hoạt việc recalculation
  `core_course_activity_progress`, nhưng không thay thế bảng đó và không tự đặt
  Course completion.
* Allowed `status`: `registered`, `present`, `late`, `absent`, `excused`.
* Allowed `attendance_source`: `provider`, `manual`, `system`, `makeup`.

## Fields

### id

```text
BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT
```

Khóa chính của Attendance.

### customer_id

```text
BIGINT UNSIGNED NOT NULL
```

Tenant sở hữu Attendance.

### session_id

```text
BIGINT UNSIGNED NOT NULL
```

Session được điểm danh.

### room_id

```text
BIGINT UNSIGNED NOT NULL
```

Room chứa Session.

### enrollment_id

```text
BIGINT UNSIGNED NOT NULL
```

Learning cycle của học viên; liên kết tới `core_course_enrollments.id`.

### user_id

```text
BIGINT UNSIGNED NOT NULL
```

User tham gia Session và phải khớp học viên của Enrollment.

### product_id

```text
BIGINT UNSIGNED NOT NULL
```

Course Product của Enrollment và Session.

### template_version_id

```text
BIGINT UNSIGNED NOT NULL
```

Published Template Version đã khóa trên Enrollment.

### version_activity_id

```text
BIGINT UNSIGNED NOT NULL
```

Version Activity `live_class` liên quan.

### status

```text
VARCHAR(50) NOT NULL DEFAULT 'registered'
```

Trạng thái điểm danh đã chuẩn hóa.

### joined_at

```text
TIMESTAMP NULL
```

Thời điểm tham gia đầu tiên được ghi nhận.

### left_at

```text
TIMESTAMP NULL
```

Thời điểm rời cuối cùng được ghi nhận.

### duration_seconds

```text
BIGINT UNSIGNED NOT NULL DEFAULT 0
```

Tổng thời lượng hiện diện đã chuẩn hóa.

### attendance_percentage

```text
DECIMAL(5,2) NOT NULL DEFAULT 0.00
```

Read-model thể hiện phần trăm tham gia dựa trên `duration_seconds` và Session
duration.

### attendance_source

```text
VARCHAR(50) NOT NULL
```

Nguồn xác định Attendance: `provider`, `manual`, `system` hoặc `makeup` (học bù).

### attendance_method

```text
VARCHAR(50) NOT NULL DEFAULT 'provider'
```

Phương thức điểm danh.

Allowed values:

```text
provider

manual

qr

face

gps

system
```

### provider_participant_id

```text
VARCHAR(255) NULL
```

Định danh participant tại provider, dùng cho sync và đối soát.

### metadata

```text
JSON NULL
```

Chi tiết sync, các khoảng join/leave hoặc lý do điều chỉnh. Không phải source
of truth của Course completion.

### created_at

```text
TIMESTAMP NULL
```

Thời điểm tạo Attendance.

### updated_at

```text
TIMESTAMP NULL
```

Thời điểm cập nhật Attendance gần nhất.

## Indexes

```sql
INDEX (customer_id);

INDEX (customer_id, session_id);

INDEX (customer_id, enrollment_id);

INDEX (customer_id, user_id);

INDEX (customer_id, version_activity_id);

INDEX (customer_id, attendance_method);

INDEX (customer_id, status);

UNIQUE (customer_id, session_id, enrollment_id);
```

## Sample Data

```text
id = 3001
customer_id = 1
session_id = 2001
room_id = 1001
enrollment_id = 501
user_id = 100
product_id = 10
template_version_id = 30
version_activity_id = 9003
status = present
joined_at = 2026-07-04 06:02:10
left_at = 2026-07-04 07:27:40
duration_seconds = 5130
attendance_percentage = 95.00
attendance_source = provider
attendance_method = provider
provider_participant_id = participant-abc
metadata = {"sync_status": "confirmed", "join_count": 2}
```

## Design Notes

* Attendance là operational summary. Raw join/leave events chi tiết có thể nằm
  trong Track Domain và nên append-only.
* `duration_seconds` có source of truth là các provider/manual attendance
  intervals; sync hoặc manual correction phải recalculation giá trị này.
* `attendance_percentage` có source of truth là `duration_seconds` và Session
  duration. Sync/manual correction phải recalculation field; consumer được phép
  dùng cho operational UI/reporting và Course Progress input, không dùng như
  completion source.
* Attendance threshold thuộc frozen Version Activity completion rule hoặc
  tenant policy được rule đó tham chiếu; bảng Attendance không sở hữu threshold.
