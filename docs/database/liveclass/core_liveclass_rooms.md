# Table: core_liveclass_rooms

## Cohort-Centered Amendment — 2026-07-25

This section supersedes all conflicting fields and cardinalities below.

Room is an optional reusable delivery resource, not a Session parent or Course
learning-context authority.

Canonical fields are: `id`, `customer_id`, `title`, `delivery_mode`
(`online|offline|hybrid`), nullable `provider`, provider/meeting/join/host
values, nullable facility/room/address values, `timezone`, nullable `capacity`,
`status` (`active|archived`), `metadata`, timestamps and audit users.

Room has no `product_id`, `template_version_id`, `version_activity_id` or
required teacher. One Room may be referenced by zero or many Sessions.

## Purpose

Đại diện cho phòng LiveClass của một Course Version Activity đã publish và là
nhóm vận hành chứa một hoặc nhiều live session.

Room không kế thừa Course, không tham chiếu working Template Activity và không
lưu learning progress.

## Relationships

```text
Customer 1 → N LiveClass Rooms

Course Product 1 → N LiveClass Rooms

Course Template Version 1 → N LiveClass Rooms

Version Activity 1 → 0..1 LiveClass Room

Teacher/User 1 → N LiveClass Rooms

LiveClass Room 1 → N LiveClass Sessions
```

## Business Rules

* Room phải thuộc `customer_id`.
* Trong Course Foundation scope, Room phải gắn với `product_id`,
  `template_version_id` và `version_activity_id`.
* Room không được gắn với working `template_activity_id`.
* Version Activity phải thuộc cùng tenant và cùng `template_version_id` với
  Room, đồng thời có `activity_type = live_class`.
* Product phải thuộc cùng tenant và bán đúng `template_version_id`.
* `teacher_id` phải là User thuộc cùng tenant và có quyền dạy.
* Một Version Activity có tối đa một Room.
* Published Version Activity là immutable; Room chỉ chứa cấu hình vận hành có
  thể thay đổi mà không sửa snapshot học tập.
* `meeting_url`, `join_url` và `host_url` có thể `NULL` cho tới khi provider tạo
  phòng.
* `visibility` quy định phạm vi hiển thị/join, nhưng không thay thế
  authorization.
* Tenant context và user permission vẫn phải được kiểm tra khi join, kể cả khi
  `visibility = public`.
* Room không lưu hoặc quyết định completion/progress.
* Không hard-delete Room đã có Session; dùng trạng thái `archived` để giữ lịch
  sử.
* Allowed `status`: `draft`, `scheduled`, `active`, `completed`, `cancelled`,
  `archived`.

## Fields

### id

```text
BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT
```

Khóa chính của Room.

### customer_id

```text
BIGINT UNSIGNED NOT NULL
```

Tenant sở hữu Room.

### product_id

```text
BIGINT UNSIGNED NOT NULL
```

Course Product cung cấp Version Activity này.

### template_version_id

```text
BIGINT UNSIGNED NOT NULL
```

Published Template Version chứa Version Activity.

### version_activity_id

```text
BIGINT UNSIGNED NOT NULL
```

Liên kết tới `core_course_template_version_activities.id`; đây là link chính
giữa LiveClass và Course Domain.

### teacher_id

```text
BIGINT UNSIGNED NOT NULL
```

User chịu trách nhiệm chính cho Room.

### title

```text
VARCHAR(255) NOT NULL
```

Tên vận hành của Room.

### description

```text
TEXT NULL
```

Mô tả vận hành của Room.

### provider

```text
VARCHAR(50) NOT NULL
```

Provider abstraction, ví dụ `zoom`, `google_meet`, `microsoft_teams`,
`custom_rtmp` hoặc `webrtc`.

### provider_room_id

```text
VARCHAR(255) NULL
```

Định danh Room tại provider.

### meeting_url

```text
TEXT NULL
```

URL meeting do provider cấp; không mặc định là URL public.

### join_url

```text
TEXT NULL
```

URL hoặc endpoint dành cho participant.

### host_url

```text
TEXT NULL
```

URL dành cho host. Đây là dữ liệu nhạy cảm và chỉ consumer được phép mới được
đọc.

### timezone

```text
VARCHAR(64) NOT NULL
```

IANA timezone dùng để hiển thị và lập lịch, ví dụ `Asia/Ho_Chi_Minh`.

### visibility

```text
VARCHAR(50) NOT NULL DEFAULT 'private'
```

Phạm vi hiển thị/join của Room.

Allowed values:

```text
private

organization

public

invite_only
```

### status

```text
VARCHAR(50) NOT NULL DEFAULT 'draft'
```

Trạng thái vòng đời vận hành của Room.

### metadata

```text
JSON NULL
```

Cấu hình provider hoặc thuộc tính mở rộng không phải source of truth cho
Course completion.

### created_at

```text
TIMESTAMP NULL
```

Thời điểm tạo Room.

### updated_at

```text
TIMESTAMP NULL
```

Thời điểm cập nhật Room gần nhất.

## Indexes

```sql
INDEX (customer_id);

INDEX (customer_id, product_id);

INDEX (customer_id, template_version_id);

INDEX (customer_id, version_activity_id);

INDEX (customer_id, teacher_id);

INDEX (customer_id, visibility);

INDEX (customer_id, status);

UNIQUE (customer_id, version_activity_id);
```

Unique constraint thực thi quan hệ `Version Activity 1 → 0..1 Room` trong phạm
vi tenant.

## Sample Data

```text
id = 1001
customer_id = 1
product_id = 10
template_version_id = 30
version_activity_id = 9003
teacher_id = 200
title = TOPIK Beginner Live Class
description = Buổi học trực tuyến cho Activity 3
provider = zoom
provider_room_id = 987654321
meeting_url = https://provider.example/meeting/987654321
join_url = https://provider.example/join/987654321
host_url = NULL
timezone = Asia/Ho_Chi_Minh
visibility = private
status = scheduled
metadata = {"waiting_room": true}
```

## Design Notes

* `template_version_id` và `product_id` được denormalize có chủ đích từ
  Version Activity/Product để tenant-scoped lookup, audit và reporting nhanh.
  Source of truth về learning content vẫn là Version Activity; các giá trị phải
  được validate khi tạo Room và không tự drift.
* Provider credentials, access tokens và signing secrets không thuộc bảng này.
* Foundation hiện thiết kế Room gắn Course. Standalone LiveClass cần một quyết
  định kiến trúc riêng trước khi cho phép các khóa Course context là `NULL`.
