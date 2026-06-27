# Table Name

`track_activity_summaries`

## Purpose

Rebuildable behavior read model theo User, Enrollment và Version Activity.

## Relationships

Mỗi Summary thuộc Customer, User, Enrollment, Product, Template Version và
Version Activity; được project từ nhiều `track_events`.

## Business Rules

* Derived từ Track Events và có thể rebuild.
* Không phải Source of Truth cho Course Progress hoặc Completion.
* Dùng cho Dashboard, Analytics và AI-ready feature generation.
* `summary_date NULL` biểu diễn lifetime summary; non-null biểu diễn daily
  activity projection khi cần.
* Không lưu final completion decision.
* Count/duration không âm; `last_event_at >= first_event_at` khi cả hai tồn tại.
* Recalculation phải tenant-scoped và idempotent.
* `projection_version` định danh công thức projection.
* Công thức mới tạo versioned projection rows; không sửa event history hoặc
  overwrite rows của projection version cũ.
* Versioned rows cho phép rebuild, compare và rollback.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| user_id | BIGINT UNSIGNED NOT NULL | User được tổng hợp. |
| enrollment_id | BIGINT UNSIGNED NOT NULL | Learning cycle. |
| product_id | BIGINT UNSIGNED NOT NULL | Product context. |
| template_version_id | BIGINT UNSIGNED NOT NULL | Published Version. |
| version_activity_id | BIGINT UNSIGNED NOT NULL | Activity context. |
| activity_type | VARCHAR(50) NOT NULL | Activity classification. |
| first_event_at | TIMESTAMP NULL | Event đầu tiên trong scope. |
| last_event_at | TIMESTAMP NULL | Event gần nhất trong scope. |
| total_events | BIGINT UNSIGNED NOT NULL DEFAULT 0 | Tổng events. |
| total_duration_seconds | BIGINT UNSIGNED NOT NULL DEFAULT 0 | Tổng observed duration. |
| active_duration_seconds | BIGINT UNSIGNED NOT NULL DEFAULT 0 | Derived active duration. |
| view_count | BIGINT UNSIGNED NOT NULL DEFAULT 0 | View/open count. |
| completion_signal_count | BIGINT UNSIGNED NOT NULL DEFAULT 0 | Số completion-like signals. |
| replay_count | BIGINT UNSIGNED NOT NULL DEFAULT 0 | Replay count. |
| pause_count | BIGINT UNSIGNED NOT NULL DEFAULT 0 | Pause count. |
| seek_count | BIGINT UNSIGNED NOT NULL DEFAULT 0 | Seek count. |
| interaction_count | BIGINT UNSIGNED NOT NULL DEFAULT 0 | Other interactions. |
| last_event_code | VARCHAR(100) NULL | Latest event code. |
| summary_date | DATE NULL | NULL lifetime; otherwise daily scope. |
| projection_version | VARCHAR(50) NOT NULL | Stable projection formula version. |
| recalculated_at | TIMESTAMP NOT NULL | Projection refresh time. |
| metadata | JSON NULL | Projection metadata/version. |
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Thời điểm cập nhật. |

## Indexes

```sql
INDEX (customer_id);
INDEX (customer_id, user_id);
INDEX (customer_id, enrollment_id);
INDEX (customer_id, version_activity_id);
INDEX (customer_id, user_id, version_activity_id);
INDEX (customer_id, recalculated_at);
UNIQUE (customer_id, enrollment_id, version_activity_id, COALESCE(summary_date, '1000-01-01'), projection_version);
```

## Sample Data

`id=3001, customer_id=1, user_id=100, enrollment_id=501, product_id=10, template_version_id=30, version_activity_id=9001, activity_type=video, total_events=18, total_duration_seconds=1800, active_duration_seconds=1500, view_count=2, completion_signal_count=1, replay_count=1, pause_count=3, seek_count=2, interaction_count=7, summary_date=NULL, projection_version=v1, recalculated_at=2026-06-27T03:00:00Z`

## Design Notes

Logical unique key normalize lifetime `summary_date NULL` bằng sentinel chỉ ở
index expression. Projection versions được giữ song song để compare/rollback.
