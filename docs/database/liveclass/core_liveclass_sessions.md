# Table: core_liveclass_sessions

Document Path: database/liveclass/core_liveclass_sessions.md

## Session Status And Time Convention Amendment — 2026-08-10

Approved: 2026-08-10 bởi LearnForge Architecture Owner.

Amendment này là canonical cho hai concern: tập giá trị `status` và quy ước thời
gian của Session. Nó giải quyết `DOC-CONFLICT-0001` và `DOC-CONFLICT-0002` tại
[LF Documentation Conflict Register](../../quality/LF-Documentation-Conflicts.md)
và thay thế mọi phát biểu trái ngược ở các mục phía dưới trong chính tài liệu
này cũng như trong
[LF-Core-LiveClass](../../core/LF-Core-LiveClass.md) trước phiên bản 2.6.

### Canonical Status Set

```text
scheduled
live
completed
cancelled
no_show
```

`ended` bị loại bỏ khỏi hợp đồng. `completed` là giá trị terminal canonical duy
nhất cho một Session đã diễn ra xong. Lý do: `completed` nằm trong danh sách
status canonical tại [LF Development Standards](../../LF-Development-Standards.md)
§ Status Fields và đồng nhất với từ vựng của Cohort, Enrollment và Cohort
Membership; `ended` là ngoại lệ duy nhất trong toàn hệ thống.

`draft` bị loại bỏ khỏi hợp đồng. Không policy nào từng định nghĩa ý nghĩa
nghiệp vụ, điều kiện vào hoặc transition ra của `draft` ở cấp Session. Session
được chuẩn bị trong Cohort `draft` được tạo ở trạng thái `scheduled`; trạng thái
setup thuộc Cohort lifecycle, không thuộc Session status.

### Canonical Lifecycle

```text
scheduled → live
scheduled → cancelled
scheduled → no_show
live      → completed
live      → cancelled
live      → no_show
```

`completed`, `cancelled` và `no_show` là terminal. Session `cancelled` và
`no_show` được giữ lại, vẫn giữ Origin và tiếp tục tiêu thụ occurrence identity,
và không bao giờ bị hard delete. Chúng không còn giữ chỗ khoảng thời gian cho
overlap validation.

`status` không quyết định Course completion trong bất kỳ trạng thái nào.

### Canonical Time Convention

`scheduled_start_at`, `scheduled_end_at`, `actual_start_at` và `actual_end_at`
lưu giờ địa phương (wall-clock) được diễn giải theo cột `timezone` của chính
dòng đó. Chúng không phải UTC instant.

Mọi phép so sánh phải parse giá trị theo `timezone` của dòng, chuẩn hóa về UTC,
rồi mới so sánh instant đã chuẩn hóa. Quy tắc áp dụng cho overlap detection,
Cohort operating-period boundary, edit/reschedule eligibility, runtime
eligibility và Origin classification.

Hai Session trong cùng một Cohort có thể mang hai timezone khác nhau. So sánh
trực tiếp giá trị cột giữa chúng — kể cả trong SQL — là sai. Browser timezone và
server default timezone không phải calculation authority.

Quy ước này thay thế phát biểu tại § Design Notes phía dưới rằng thời gian
Session "nên được lưu theo UTC".

### Implementation Alignment

Historical migration `2026_07_25_010000_create_cohort_liveclass_operations.php`
đặt `status` default `draft`. Migration lịch sử không được sửa. Việc đưa default
về `scheduled` bằng forward migration, gỡ nhánh xử lý `draft` không còn hiệu lực
và bổ sung transition workflow được theo dõi riêng tại `DOC-CONFLICT-0009` và
`DOC-CONFLICT-0010`; tài liệu này mô tả hợp đồng target, không khẳng định
implementation đã hoàn tất.

## Schedule Origin Amendment — 2026-08-05

Schedule lineage is not stored on this table. A Session explicitly confirmed
from a projected occurrence has exactly one immutable row in
`core_liveclass_session_schedule_origins`; manual and legacy Sessions have no
Origin. No existing Session is backfilled by timestamp inference.

An Origin-backed Session records the source Schedule timezone. Its relationship
label is derived by normalizing the current planned interval through the
Session timezone and comparing it with the immutable Origin UTC instants:
equal is `on_schedule`, different is `rescheduled`. A new manual Session is
`off_schedule`; a legacy Session without Origin is `source_unknown`.

Reschedule updates this table through the existing dedicated workflow, appends
`core_liveclass_session_schedule_changes` and leaves Origin, Schedule and Slot
unchanged. Cancelled/no-show Sessions retain Origin and occurrence uniqueness.
Bulk confirmation creates no Attendance, Recording, Replay, Progress or
Completion.

## Cohort Schedule Boundary Amendment — 2026-08-04

A Cohort Session interval must be fully contained in the Cohort operating
period. Its minimum selectable date is `MAX(cohort.start_date, CURRENT_DATE)`;
its maximum is the end of `cohort.end_date`. Create, edit and reschedule must
enforce the same boundary in both UI controls and authoritative backend
validation. A request cannot create or move a Session into an elapsed date or
outside its Cohort period.

## Session Teacher Availability Amendment — 2026-08-04

This section is the canonical contract for resolving the Session teaching team.
New Cohort Session writes set nullable `primary_teacher_id` to `NULL`; non-null
values are read-only legacy compatibility data. Every selected Teacher must:

1. belong to the same `customer_id`;
2. be an active `teacher` User;
3. have an active `core_course_cohort_teachers` assignment for the Session's
   `cohort_id`;
4. satisfy the assignment availability rule below.

An active Cohort `primary_teacher`, `teacher` or `assistant` assignment is
eligible only when the complete Session interval is covered:

```text
assigned_from IS NULL OR DATE(scheduled_start_at) >= assigned_from

AND

assigned_to IS NULL OR DATE(scheduled_end_at) <= assigned_to
```

The UI must defer all checkbox options until both scheduled timestamps are
available and filter them with this rule. No Teacher is selected automatically.
Backend validation is mandatory and authoritative; a direct or tampered request
must not select an unassigned or unavailable Teacher.

This availability check does not mutate Cohort assignments. Persisting a
Session Teacher continues to create the Session-level operational assignment
and does not change the Teacher's Cohort role or assignment period.

## Curriculum And Operational Session Amendment — 2026-08-03

This section supersedes the Session binding nullability in the 2026-07-25
amendment and every conflicting legacy field description below.

Add the canonical column:

```text
session_type VARCHAR(50) NOT NULL
```

Allowed values are `curriculum` and `operational`.

Required for every Session: `customer_id`, `cohort_id`,
`template_version_id`, `session_type`, `title`, `session_no`, `delivery_mode`,
`scheduled_start_at`, `scheduled_end_at`, `timezone` and `status`.

Conditional binding:

| session_type | version_lesson_id | version_activity_id |
| --- | --- | --- |
| `curriculum` | required | required |
| `operational` | `NULL` | `NULL` |

Both binding columns are therefore nullable at database level and protected by
a database check constraint where supported plus mandatory transactional
application validation. Curriculum references must share `customer_id` and
`template_version_id`; the Activity must belong to the Lesson and have
`activity_type = live_class`.

Existing rows require deterministic backfill before adding the constraint:

* rows with a valid `version_activity_id` become `curriculum` after validating
  the complete locked Version/Lesson/Activity chain;
* rows with `version_activity_id IS NULL` become `operational` and their legacy
  `version_lesson_id` is cleared only after an audit confirms they are not
  Course evidence anchors;
* invalid or ambiguous rows block deployment and require manual remediation;
* cancelled/no-show rows and all evidence history are retained.

Recommended indexes remain tenant-first and include
`(customer_id, cohort_id, session_type, scheduled_start_at)`,
`(customer_id, version_lesson_id)` and
`(customer_id, version_activity_id)`. Foreign keys retain `RESTRICT` behavior.
No Working Template identifier is permitted.

This document describes the approved target contract. Implementation must use
a new forward migration; the historical migration must not be edited.

## Cohort-Centered Amendment — 2026-07-25

This section supersedes all conflicting fields and cardinalities below.

Canonical relationships:

```text
Cohort 1 → N Sessions
Version Lesson 1 → N Sessions
Version Live Class Activity 1 → 0..N Sessions
Room 1 → 0..N Sessions
Session N ↔ N Teachers
Session 1 → N Attendance / Recording / Schedule Change
```

Required fields: `customer_id`, `cohort_id`, `template_version_id`,
`version_lesson_id`, `title`, `session_no`, `delivery_mode`,
`scheduled_start_at`, `scheduled_end_at`, `timezone`, `status`.

Nullable fields: `version_activity_id`, `room_id`, `primary_teacher_id`,
`superseded_by_session_id`, actual times, online provider/meeting snapshot,
offline facility/room/address snapshot, cancellation reason and metadata.

`version_activity_id` is required by business validation when the Session is a
Course-linked learning Session and must be `NULL` for an operational event.
When present it must be a same-tenant `live_class` Activity in the Session
Lesson and Version. Only such a Session may produce Activity Completion
Evidence.

Allowed `delivery_mode`: `online`, `offline`, `hybrid`.

The `status` list originally published in this section is superseded by
§ Session Status And Time Convention Amendment — 2026-08-10 above, which retires
both `draft` and `ended`. Use that section as the canonical status set.

Rescheduling keeps the Session scheduled and appends
`core_liveclass_session_schedule_changes`. Replacement uses a new Session and
`superseded_by_session_id`.

## Historical Sections — Superseding Notice

Mọi mục từ đây tới hết tài liệu (`Purpose`, `Relationships`, `Business Rules`,
`Fields`, `Indexes`, `Sample Data`, `Design Notes`) mô tả model **Room-owned**
ban đầu và đã bị thay thế bởi bốn amendment phía trên, theo thứ tự thời gian:

1. Cohort-Centered Amendment — 2026-07-25 (Session thuộc Cohort; `room_id`,
   `product_id` và `teacher_id` không còn là ràng buộc bắt buộc của Session).
2. Curriculum And Operational Session Amendment — 2026-08-03 (`session_type`;
   `version_lesson_id` và `version_activity_id` là conditional binding).
3. Schedule Origin Amendment — 2026-08-05 (lineage nằm ở
   `core_liveclass_session_schedule_origins`).
4. Session Status And Time Convention Amendment — 2026-08-10 (tập status và quy
   ước thời gian canonical).

Các mục lịch sử được giữ lại để tra cứu quyết định cũ. Chúng **không** phải hợp
đồng hiện hành. Cụ thể, những phát biểu sau đã hết hiệu lực:

* "Session phải thuộc một Room" và `room_id NOT NULL` — Room là delivery
  resource tùy chọn.
* `product_id`, `teacher_id`, `version_activity_id` là `NOT NULL`.
* `session_no` là duy nhất trong một Room và
  `UNIQUE (customer_id, room_id, session_no)` — khóa hiện hành là
  `(customer_id, cohort_id, session_no)`.
* Danh sách `status` chứa `ended`.
* "Thời gian nên được lưu theo UTC" tại § Design Notes.

Khi hợp đồng vật lý của bảng này được viết lại hoàn chỉnh, các mục lịch sử bên
dưới sẽ được thay thế bằng một § Fields thống nhất.

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
* Dòng "Allowed `status`" cũ tại đây (chứa `ended`) đã hết hiệu lực. Tập status
  canonical nằm tại § Session Status And Time Convention Amendment — 2026-08-10.

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
* Ghi chú cũ "Thời gian nên được lưu theo UTC và render theo `timezone`" đã hết
  hiệu lực. Quy ước canonical là wall-clock time diễn giải theo cột `timezone`;
  xem § Session Status And Time Convention Amendment — 2026-08-10.
* Không suy ra completion chỉ từ `status = completed`; attendance/replay
  evidence còn phải được Course progress service đánh giá theo frozen completion
  rule.
