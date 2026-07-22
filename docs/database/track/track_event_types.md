# Table: track_event_types

## Purpose

Master catalog cho learning behavior event types. Bảng định nghĩa taxonomy ổn
định; event type không quyết định business state.

## Relationships

`Customer 1 → N Tenant Event Types`; global type có `customer_id = NULL`;
`Event Type 1 → N Track Events`.

## Business Rules

* Global event type có `customer_id NULL`; tenant custom type có
  `customer_id NOT NULL`.
* `code` ổn định, lowercase `snake_case`; không đổi nghĩa code đã sử dụng.
* `code` immutable sau publish.
* Event type cũ chỉ được deprecated, không đổi semantics.
* Event type thay thế được liên kết qua `replaced_by_event_type_id`.
* `deprecated_at` được set khi status chuyển sang `deprecated`.
* Replacement phải cùng tenant scope hoặc là compatible global system type.
* `source_domain` xác định Domain phát event, không chuyển ownership.
* Allowed `status`: `active`, `deprecated`, `archived`.
* `is_system = true` dành cho taxonomy do LearnForge quản trị.
* Event type không complete Course, set Assessment Result hoặc thay đổi source
  record.
* Metadata không chứa canonical business state.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NULL | Tenant owner; NULL cho global type. |
| code | VARCHAR(100) NOT NULL | Stable event code. |
| name | VARCHAR(255) NOT NULL | Tên hiển thị. |
| description | TEXT NULL | Mô tả semantics. |
| category | VARCHAR(100) NOT NULL | Nhóm behavior, ví dụ `media`, `assessment`. |
| source_domain | VARCHAR(50) NOT NULL | Domain phát event. |
| is_system | BOOLEAN NOT NULL DEFAULT false | Global/system-managed flag. |
| status | VARCHAR(50) NOT NULL DEFAULT 'active' | Lifecycle taxonomy. |
| deprecated_at | TIMESTAMP NULL | Thời điểm type bị deprecated. |
| replaced_by_event_type_id | BIGINT UNSIGNED NULL | Event Type thay thế. |
| metadata | JSON NULL | Extension metadata không canonical. |
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Thời điểm cập nhật taxonomy. |

## Indexes

```sql
INDEX (customer_id);
INDEX (source_domain);
INDEX (category);
INDEX (status);
INDEX (customer_id, replaced_by_event_type_id);
UNIQUE (COALESCE(customer_id, 0), code);
```

## Sample Data

`id=1, customer_id=NULL, code=video_played, name=Video Played, category=media, source_domain=media, is_system=true, status=active`

## Design Notes

Foundation examples gồm `lesson_opened`, `lesson_completed`,
`activity_started`, `activity_completed`, `video_played`, `video_paused`,
`video_seeked`, `video_completed`, `document_opened`,
`document_downloaded`, `live_joined`, `live_left`, `replay_started`,
`replay_completed`, `assessment_started`, `assessment_submitted`,
`answer_changed`, `media_stream_started`, `media_stream_ended` và
`certificate_downloaded`.

Logical uniqueness normalize global `customer_id NULL` thành reserved scope
`0`. Migration phải chọn generated column hoặc functional-index syntax tương
thích MySQL mà không thêm business field.
