# Table: core_liveclass_replays

Document Path: database/liveclass/core_liveclass_replays.md

## Cohort-Centered Amendment — 2026-07-25

Replay belongs to a Recording, Session and Enrollment. Enrollment must match a
Cohort membership for the Session context. `version_activity_id` is derived
from Session and nullable; without it Replay cannot become Course Activity
Completion Evidence.

## Purpose

Lưu trạng thái xem lại một LiveClass Recording của một Enrollment trong một
learning cycle.

Replay là operational summary có thể làm đầu vào cho Course Activity Progress;
`completed` ở bảng này chỉ có nghĩa hoàn thành replay theo rule vận hành, không
phải Course completion.

## Relationships

```text
Customer 1 → N LiveClass Replays

LiveClass Recording 1 → N LiveClass Replays

LiveClass Session 1 → N LiveClass Replays

LiveClass Room 1 → N LiveClass Replays

Course Enrollment 1 → N LiveClass Replays

User 1 → N LiveClass Replays

Version Activity 1 → N LiveClass Replays
```

## Business Rules

* Replay phải thuộc `customer_id`.
* Chỉ tạo Replay khi Enrollment đang `active`.
* Replay, Enrollment, Recording, Session và Room phải thuộc cùng tenant.
* `product_id` và `template_version_id` phải khớp Enrollment và Recording.
* `user_id` phải khớp `student_id` của Enrollment.
* `version_activity_id` phải khớp Recording và có
  `activity_type = live_class`.
* Một Enrollment có tối đa một Replay record cho một Recording.
* `watch_seconds` không âm và được tổng hợp từ replay tracking events.
* `last_position_seconds` không phải `watch_seconds`; field này chỉ lưu vị trí
  cuối để resume UX.
* `last_position_seconds` không được lớn hơn `duration_seconds` của Recording
  và không được dùng trực tiếp làm completion.
* `progress_percentage` nằm trong khoảng `0.00` đến `100.00`.
* `last_viewed_at` không được trước `first_viewed_at`.
* `completed_at` chỉ được đặt khi `status = completed`.
* Replay sau khi tạo được giữ lại khi Enrollment hết active để bảo toàn lịch sử
  learning cycle.
* Replay threshold lấy từ frozen Version Activity `completion_rule` /
  `completion_threshold` hoặc tenant policy được rule đó tham chiếu.
* Replay có thể kích hoạt recalculation `core_course_activity_progress`, nhưng
  không thay thế hoặc tự quyết định record progress đó.
* Allowed `status`: `not_started`, `in_progress`, `completed`.

## Fields

### id

```text
BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT
```

Khóa chính của Replay.

### customer_id

```text
BIGINT UNSIGNED NOT NULL
```

Tenant sở hữu Replay.

### recording_id

```text
BIGINT UNSIGNED NOT NULL
```

Recording được xem lại.

### session_id

```text
BIGINT UNSIGNED NOT NULL
```

Session nguồn của Recording.

### room_id

```text
BIGINT UNSIGNED NOT NULL
```

Room nguồn của Session.

### enrollment_id

```text
BIGINT UNSIGNED NOT NULL
```

Learning cycle của học viên.

### user_id

```text
BIGINT UNSIGNED NOT NULL
```

User xem Replay và phải khớp học viên của Enrollment.

### product_id

```text
BIGINT UNSIGNED NOT NULL
```

Course Product của Enrollment.

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
VARCHAR(50) NOT NULL DEFAULT 'not_started'
```

Trạng thái xem lại ở LiveClass operational layer.

### first_viewed_at

```text
TIMESTAMP NULL
```

Thời điểm mở Replay lần đầu.

### last_viewed_at

```text
TIMESTAMP NULL
```

Thời điểm tương tác Replay gần nhất.

### watch_seconds

```text
BIGINT UNSIGNED NOT NULL DEFAULT 0
```

Tổng thời lượng xem đã chuẩn hóa.

### last_position_seconds

```text
BIGINT UNSIGNED NOT NULL DEFAULT 0
```

Vị trí cuối cùng của người học trong Recording để resume playback.

### progress_percentage

```text
DECIMAL(5,2) NOT NULL DEFAULT 0.00
```

Phần trăm xem Replay, giới hạn từ `0.00` đến `100.00`.

### completed_at

```text
TIMESTAMP NULL
```

Thời điểm Replay đạt rule vận hành. Không phải Course completion timestamp.

### metadata

```text
JSON NULL
```

Thông tin recalculation hoặc trạng thái đồng bộ; không phải source of truth của
Course completion.

### created_at

```text
TIMESTAMP NULL
```

Thời điểm tạo Replay.

### updated_at

```text
TIMESTAMP NULL
```

Thời điểm cập nhật Replay gần nhất.

## Indexes

```sql
INDEX (customer_id);

INDEX (customer_id, recording_id);

INDEX (customer_id, session_id);

INDEX (customer_id, enrollment_id);

INDEX (customer_id, user_id);

INDEX (customer_id, version_activity_id);

INDEX (customer_id, status);

UNIQUE (customer_id, recording_id, enrollment_id);
```

## Sample Data

```text
id = 5001
customer_id = 1
recording_id = 4001
session_id = 2001
room_id = 1001
enrollment_id = 501
user_id = 100
product_id = 10
template_version_id = 30
version_activity_id = 9003
status = in_progress
first_viewed_at = 2026-07-05 02:10:00
last_viewed_at = 2026-07-05 02:55:00
watch_seconds = 2700
last_position_seconds = 2735
progress_percentage = 51.53
completed_at = NULL
metadata = {"recalculated_at": "2026-07-05T02:55:05Z"}
```

## Design Notes

* Raw play/pause/seek/watch events thuộc Track Domain. Replay là summary phục
  vụ resume, operational reporting và progress input.
* `watch_seconds` và `progress_percentage` được recalculation từ Track events
  cùng Media duration; Media metadata là source of truth cho độ dài Recording.
* `last_position_seconds` lấy từ event playback gần nhất, chỉ phục vụ resume UX
  và không cộng dồn như `watch_seconds`.
* Course Domain quyết định `core_course_activity_progress` dựa trên frozen
  Version Activity rule và evidence phù hợp; không đọc một cờ completion tùy
  ý từ metadata.
