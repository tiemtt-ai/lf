# Table: core_liveclass_attendances

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
* Allowed `attendance_source`: `provider`, `manual`, `system`.

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

Nguồn xác định Attendance: `provider`, `manual` hoặc `system`.

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
