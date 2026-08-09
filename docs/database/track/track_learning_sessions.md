# Table: track_learning_sessions

Document Path: database/track/track_learning_sessions.md

## Purpose

Nhóm behavior events thành một phiên học tập có ý nghĩa cho Analytics và AI;
không phải authentication/login session.

## Relationships

`Customer/User 1 → N Learning Sessions`; Session có thể thuộc Enrollment,
Product và Template Version; `Learning Session 1 → N Track Events`.

## Business Rules

* Session không thay thế Enrollment và không quyết định Progress.
* `duration_seconds` là read-model value từ events và có thể recalculate.
* Nếu `ended_at` có giá trị thì phải sau `started_at`.
* Allowed `status`: `active`, `ended`, `expired`.
* Allowed `session_source`: `course`, `liveclass`, `assessment`, `media`,
  `system`.
* Một user có thể có nhiều session, kể cả overlap theo policy sau này.
* Mọi reference phải cùng tenant.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu session. |
| user_id | BIGINT UNSIGNED NOT NULL | Learner/User của session. |
| enrollment_id | BIGINT UNSIGNED NULL | Optional learning cycle. |
| product_id | BIGINT UNSIGNED NULL | Optional Product context. |
| template_version_id | BIGINT UNSIGNED NULL | Optional published Version. |
| started_at | TIMESTAMP NOT NULL | Session start. |
| ended_at | TIMESTAMP NULL | Session end. |
| duration_seconds | BIGINT UNSIGNED NOT NULL DEFAULT 0 | Derived duration. |
| session_source | VARCHAR(50) NOT NULL | Source that opened/inferred session. |
| device_type | VARCHAR(50) NULL | Device classification. |
| platform | VARCHAR(50) NULL | OS/client platform. |
| browser | VARCHAR(100) NULL | Browser/client family. |
| ip_address | VARCHAR(45) NULL | IP subject to privacy policy. |
| status | VARCHAR(50) NOT NULL DEFAULT 'active' | Session lifecycle. |
| metadata | JSON NULL | Non-canonical session context. |
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Projection/lifecycle update time. |

## Indexes

```sql
INDEX (customer_id);
INDEX (customer_id, user_id, started_at);
INDEX (customer_id, enrollment_id, started_at);
INDEX (customer_id, status);
INDEX (customer_id, started_at);
```

## Sample Data

`id=2001, customer_id=1, user_id=100, enrollment_id=501, product_id=10, template_version_id=30, started_at=2026-06-27T02:00:00Z, ended_at=2026-06-27T02:45:00Z, duration_seconds=2700, session_source=course, device_type=desktop, status=ended`

## Design Notes

Session boundary, idle timeout, cross-device merge và late/offline events phải
được owner review. Events vẫn là observation history; Session là Track-owned
grouping record.
