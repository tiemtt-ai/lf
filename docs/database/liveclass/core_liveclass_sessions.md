# Table: core_liveclass_sessions

## Purpose

Đại diện cho một lần học live cụ thể trong một LiveClass Room.

Session lưu lịch kế hoạch và thời gian thực tế, nhưng không tự quyết định Course
completion.

## Relationships

```text
Customer 1 → N LiveClass Sessions

LiveClass Room 1 → N LiveClass Sessions

Course Product 1 → N LiveClass Sessions

Course Template Version 1 → N LiveClass Sessions

Version Activity 1 → N LiveClass Sessions

Teacher/User 1 → N LiveClass Sessions

LiveClass Session 1 → N Attendances / Recordings / Chat Logs
```

## Business Rules

* Session phải thuộc một Room.
* Session và Room phải có cùng `customer_id`, `product_id`,
  `template_version_id` và `version_activity_id`.
* Version Activity phải có `activity_type = live_class`.
* `teacher_id` phải thuộc cùng tenant và có quyền dạy.
* `session_no` là duy nhất trong một Room.
* `scheduled_start_at` phải trước `scheduled_end_at`.
* `actual_start_at` và `actual_end_at` lưu thời gian thực tế; khi có cả hai,
  `actual_start_at` phải không sau `actual_end_at`.
* `max_participants = NULL` nghĩa là LF không áp giới hạn hoặc giới hạn do
  provider quyết định.
* `allow_join_before_minutes` không được âm.
* Quyền join vẫn phụ thuộc Enrollment hợp lệ và Session status; hai field giới
  hạn capacity/timing không thay thế authorization.
* Session bị cancel vẫn được giữ để bảo toàn lịch sử.
* Session không lưu hoặc quyết định Course completion.
* Allowed `status`: `scheduled`, `live`, `ended`, `cancelled`, `no_show`.

## Fields

### id

```text
BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT
```

Khóa chính của Session.

### customer_id

```text
BIGINT UNSIGNED NOT NULL
```

Tenant sở hữu Session.

### room_id

```text
BIGINT UNSIGNED NOT NULL
```

Room chứa Session.

### product_id

```text
BIGINT UNSIGNED NOT NULL
```

Course Product context được sao chép có kiểm soát từ Room.

### template_version_id

```text
BIGINT UNSIGNED NOT NULL
```

Published Template Version context của Session.

### version_activity_id

```text
BIGINT UNSIGNED NOT NULL
```

Version Activity `live_class` mà Session phục vụ.

### teacher_id

```text
BIGINT UNSIGNED NOT NULL
```

User phụ trách Session. Có thể khác giáo viên mặc định của Room nếu có phân công
hợp lệ.

### title

```text
VARCHAR(255) NOT NULL
```

Tên hiển thị của Session.

### session_no

```text
INT UNSIGNED NOT NULL
```

Số thứ tự Session trong Room.

### scheduled_start_at

```text
TIMESTAMP NOT NULL
```

Thời điểm bắt đầu theo kế hoạch, lưu theo quy ước thời gian của hệ thống.

### scheduled_end_at

```text
TIMESTAMP NOT NULL
```

Thời điểm kết thúc theo kế hoạch.

### actual_start_at

```text
TIMESTAMP NULL
```

Thời điểm Session thực sự bắt đầu.

### actual_end_at

```text
TIMESTAMP NULL
```

Thời điểm Session thực sự kết thúc.

### timezone

```text
VARCHAR(64) NOT NULL
```

IANA timezone dùng để hiển thị lịch.

### max_participants

```text
INT UNSIGNED NULL
```

Số người tối đa có thể tham gia Session. `NULL` nghĩa là không giới hạn ở LF
layer hoặc do provider quyết định.

### allow_join_before_minutes

```text
INT UNSIGNED NOT NULL DEFAULT 0
```

Số phút cho phép học viên join trước `scheduled_start_at`.

### status

```text
VARCHAR(50) NOT NULL DEFAULT 'scheduled'
```

Trạng thái vận hành của Session.

### provider_session_id

```text
VARCHAR(255) NULL
```

Định danh lần họp tại provider.

### metadata

```text
JSON NULL
```

Metadata provider hoặc dữ liệu mở rộng không phải source of truth cho
completion.

### created_at

```text
TIMESTAMP NULL
```

Thời điểm tạo Session.

### updated_at

```text
TIMESTAMP NULL
```

Thời điểm cập nhật Session gần nhất.

## Indexes

```sql
INDEX (customer_id);

INDEX (customer_id, room_id);

INDEX (customer_id, version_activity_id);

INDEX (customer_id, teacher_id);

INDEX (customer_id, status);

INDEX (customer_id, scheduled_start_at);

UNIQUE (customer_id, room_id, session_no);
```

## Sample Data

```text
id = 2001
customer_id = 1
room_id = 1001
product_id = 10
template_version_id = 30
version_activity_id = 9003
teacher_id = 200
title = TOPIK Beginner Live Class - Session 1
session_no = 1
scheduled_start_at = 2026-07-04 06:00:00
scheduled_end_at = 2026-07-04 07:30:00
actual_start_at = NULL
actual_end_at = NULL
timezone = Asia/Ho_Chi_Minh
max_participants = 100
allow_join_before_minutes = 10
status = scheduled
provider_session_id = NULL
metadata = {"calendar_sync": "pending"}
```

## Design Notes

* `product_id`, `template_version_id` và `version_activity_id` là Course
  context denormalized từ Room để audit và truy vấn operational data. Room là
  source gần nhất; Version Activity là source of truth của learning context.
* Thời gian nên được lưu theo UTC và render theo `timezone`.
* Không suy ra completion chỉ từ `status = ended`; attendance/replay evidence
  còn phải được Course progress service đánh giá theo frozen completion rule.
