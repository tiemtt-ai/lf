# Table Name

`core_assessment_attempts`

## Purpose

Một lần làm Quiz của một Enrollment.

## Relationships

`Quiz 1 → N Attempts`; `Enrollment 1 → N Attempts`; `Attempt 1 → N Answers / Gradings`.

## Business Rules

* Attempt, Quiz và Enrollment phải cùng tenant, Product và Template Version.
* Chỉ bắt đầu Attempt khi Enrollment `active`; `user_id` phải khớp Enrollment student.
* Version Activity phải khớp Quiz và có `activity_type = assessment`.
* Attempt khóa Quiz snapshot khi bắt đầu; Attempt cũ không đổi khi authoring thay đổi.
* `attempt_no` duy nhất theo Quiz/Enrollment; service enforce `max_attempts`.
* Score/pass/fail là evidence, không phải Course completion.
* Allowed `status`: `in_progress`, `submitted`, `graded`, `expired`, `cancelled`.
* Allowed `grading_status`: `not_required`, `pending`, `in_progress`, `completed`.

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| quiz_id | BIGINT UNSIGNED NOT NULL | Quiz immutable. |
| enrollment_id | BIGINT UNSIGNED NOT NULL | Learning cycle. |
| user_id | BIGINT UNSIGNED NOT NULL | Người làm bài. |
| product_id | BIGINT UNSIGNED NOT NULL | Product context. |
| template_version_id | BIGINT UNSIGNED NOT NULL | Version khóa trên Enrollment. |
| version_activity_id | BIGINT UNSIGNED NOT NULL | Assessment Version Activity. |
| attempt_no | INT UNSIGNED NOT NULL | Số lần làm trong cycle. |
| quiz_snapshot | JSON NOT NULL | Quiz policy/structure lock tại start. |
| status | VARCHAR(50) NOT NULL DEFAULT 'in_progress' | Trạng thái Attempt. |
| started_at | TIMESTAMP NOT NULL | Thời điểm bắt đầu. |
| submitted_at | TIMESTAMP NULL | Thời điểm nộp. |
| graded_at | TIMESTAMP NULL | Thời điểm hoàn tất chấm. |
| time_spent_seconds | BIGINT UNSIGNED NOT NULL DEFAULT 0 | Thời gian làm. |
| score | DECIMAL(10,2) NULL | Tổng điểm evidence. |
| score_percentage | DECIMAL(5,2) NULL | Phần trăm 0–100. |
| passed | BOOLEAN NULL | Pass/fail evidence. |
| grading_status | VARCHAR(50) NOT NULL DEFAULT 'pending' | Trạng thái chấm. |
| metadata | JSON NULL | Audit/runtime metadata. |
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Thời điểm cập nhật. |

## Indexes

```sql
INDEX (customer_id);
INDEX (customer_id, quiz_id);
INDEX (customer_id, enrollment_id);
INDEX (customer_id, user_id);
INDEX (customer_id, version_activity_id);
INDEX (customer_id, status);
INDEX (customer_id, grading_status);
UNIQUE (customer_id, quiz_id, enrollment_id, attempt_no);
```

## Sample Data

`id=3000, customer_id=1, quiz_id=2000, enrollment_id=501, user_id=100, product_id=10, template_version_id=30, version_activity_id=9004, attempt_no=1, quiz_snapshot={"max_attempts":2,"pass_score_percentage":70}, status=submitted, started_at=2026-07-01T02:00:00Z, submitted_at=2026-07-01T02:45:00Z, time_spent_seconds=2700, grading_status=pending`

## Design Notes

Quiz snapshot depth and recalculation contract cần owner review; Attempt is evidence source only.
