# Table Name

`track_daily_summaries`

## Purpose

Rebuildable daily behavior summary theo User và optional
Enrollment/Product context.

## Relationships

Mỗi Summary thuộc Customer/User, có thể thuộc Enrollment và Product; được
project từ `track_events` trong một `summary_date`.

## Business Rules

* Derived từ Track Events và có thể rebuild.
* Dùng cho Analytics, Dashboard và AI feature generation.
* Không phải Course Progress.
* Không phải Billing/Usage Source of Truth; Usage chỉ tiêu thụ approved metrics
  qua contract riêng.
* Count/duration không âm; first/last event phải nằm trong summary window.
* Recalculation phải tenant-scoped và idempotent.
* Summary date dùng timezone của tenant hoặc user; không assume UTC.
* `timezone` được snapshot khi tạo summary và dùng IANA timezone identifier.
* `projection_version` định danh công thức projection.
* Công thức mới tạo versioned rows; không overwrite projection cũ hoặc sửa
  event history.
* Versioned rows cho phép rebuild, compare và rollback.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| user_id | BIGINT UNSIGNED NOT NULL | User được tổng hợp. |
| enrollment_id | BIGINT UNSIGNED NULL | Optional learning cycle. |
| product_id | BIGINT UNSIGNED NULL | Optional Product context. |
| summary_date | DATE NOT NULL | Ngày tổng hợp theo approved timezone policy. |
| timezone | VARCHAR(64) NOT NULL | Snapshot IANA timezone của summary date. |
| total_events | BIGINT UNSIGNED NOT NULL DEFAULT 0 | Tổng events. |
| total_duration_seconds | BIGINT UNSIGNED NOT NULL DEFAULT 0 | Tổng observed duration. |
| active_duration_seconds | BIGINT UNSIGNED NOT NULL DEFAULT 0 | Derived active duration. |
| lesson_count | BIGINT UNSIGNED NOT NULL DEFAULT 0 | Distinct lessons observed. |
| activity_count | BIGINT UNSIGNED NOT NULL DEFAULT 0 | Distinct activities observed. |
| assessment_count | BIGINT UNSIGNED NOT NULL DEFAULT 0 | Assessment interactions. |
| liveclass_count | BIGINT UNSIGNED NOT NULL DEFAULT 0 | LiveClass interactions. |
| media_count | BIGINT UNSIGNED NOT NULL DEFAULT 0 | Media interactions. |
| replay_count | BIGINT UNSIGNED NOT NULL DEFAULT 0 | Replay interactions. |
| completion_signal_count | BIGINT UNSIGNED NOT NULL DEFAULT 0 | Completion-like signals only. |
| first_event_at | TIMESTAMP NULL | First event in window. |
| last_event_at | TIMESTAMP NULL | Last event in window. |
| projection_version | VARCHAR(50) NOT NULL | Stable projection formula version. |
| recalculated_at | TIMESTAMP NOT NULL | Projection refresh time. |
| metadata | JSON NULL | Projection version/timezone metadata. |
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Thời điểm cập nhật. |

## Indexes

```sql
INDEX (customer_id);
INDEX (customer_id, user_id, summary_date);
INDEX (customer_id, enrollment_id, summary_date);
INDEX (customer_id, product_id, summary_date);
UNIQUE (customer_id, user_id, COALESCE(enrollment_id, 0), COALESCE(product_id, 0), summary_date, timezone, projection_version);
```

## Sample Data

`id=4001, customer_id=1, user_id=100, enrollment_id=501, product_id=10, summary_date=2026-06-27, timezone=Asia/Ho_Chi_Minh, total_events=42, total_duration_seconds=3600, active_duration_seconds=3000, lesson_count=2, activity_count=4, assessment_count=1, liveclass_count=0, media_count=3, replay_count=1, completion_signal_count=2, projection_version=v1, recalculated_at=2026-06-28T00:10:00Z`

## Design Notes

Nullable scope được normalize bằng reserved `0` trong logical index expression.
Summary không được dùng để suy diễn billing hoặc completion ngoài approved
consumer policy.
