# Table: track_learning_paths

## Purpose

Observed learning journey/path được reconstruct từ behavior events. Đây không
phải Course Learning Path hoặc curriculum.

## Relationships

Mỗi observed Path thuộc Customer/User và có thể thuộc Enrollment/Product; được
derive từ nhiều `track_events`.

## Business Rules

* Observed/analytics read model; có thể rebuild hoặc recalculate.
* Không grant access, tạo Enrollment hoặc thay đổi Course curriculum.
* Allowed `path_type`: `observed_journey`, `remediation_journey`,
  `recommendation_journey`.
* Allowed `status`: `active`, `completed`, `abandoned`.
* `ended_at`, nếu có, phải sau `started_at`.
* `steps_count` và `source_event_count` không âm.
* Recommendation journey chỉ ghi nhận hành trình quan sát; AI vẫn sở hữu
  Recommendation.
* `projection_version` định danh journey reconstruction formula.
* Rebuild tạo versioned observed path; không overwrite projection cũ hoặc sửa
  event history.
* Versioned paths cho phép compare và rollback.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| user_id | BIGINT UNSIGNED NOT NULL | User được quan sát. |
| enrollment_id | BIGINT UNSIGNED NULL | Optional learning cycle. |
| product_id | BIGINT UNSIGNED NULL | Optional Product context. |
| path_type | VARCHAR(50) NOT NULL | Observed journey classification. |
| started_at | TIMESTAMP NOT NULL | Journey start. |
| ended_at | TIMESTAMP NULL | Journey end. |
| steps_count | BIGINT UNSIGNED NOT NULL DEFAULT 0 | Derived step count. |
| source_event_count | BIGINT UNSIGNED NOT NULL DEFAULT 0 | Events used. |
| status | VARCHAR(50) NOT NULL DEFAULT 'active' | Observed path lifecycle. |
| path_summary | JSON NOT NULL | Ordered/aggregate journey representation. |
| projection_version | VARCHAR(50) NOT NULL | Stable path projection version. |
| metadata | JSON NULL | Projection version/provenance. |
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Recalculation/update time. |

## Indexes

```sql
INDEX (customer_id);
INDEX (customer_id, user_id, started_at);
INDEX (customer_id, enrollment_id, started_at);
INDEX (customer_id, product_id);
INDEX (customer_id, status);
INDEX (customer_id, user_id, projection_version);
```

## Sample Data

`id=6001, customer_id=1, user_id=100, enrollment_id=501, product_id=10, path_type=observed_journey, started_at=2026-06-27T02:00:00Z, ended_at=2026-06-27T03:00:00Z, steps_count=5, source_event_count=24, status=completed, path_summary={"steps":["lesson_opened","video_completed","assessment_submitted"]}, projection_version=v1`

## Design Notes

Tên bảng được giữ theo Foundation request nhưng semantics luôn là observed
journey. Không consumer nào được nhầm record này với Course Learning Path hoặc
access plan.
