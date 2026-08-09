# Table: core_assessment_grading_assignments

Document Path: database/assessment/core_assessment_grading_assignments.md

## Purpose

Phân công chấm toàn Attempt hoặc một Answer cho teacher/grader.

## Relationships

`Attempt 1 → N Assignments`; `Answer 1 → N Assignments`; `User 1 → N Assigned/Created Assignments`.

## Business Rules

* Attempt, optional Answer và users phải cùng tenant; Answer phải thuộc Attempt.
* `answer_id=NULL` nghĩa là phân công cấp Attempt.
* Allowed `status`: `pending`, `in_progress`, `completed`, `cancelled`.
* Một active assignment trùng scope/grader phải được ngăn bằng service transaction.

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| attempt_id | BIGINT UNSIGNED NOT NULL | Attempt cần chấm. |
| answer_id | BIGINT UNSIGNED NULL | Answer scope, nếu có. |
| assigned_to_user_id | BIGINT UNSIGNED NOT NULL | Grader được phân công. |
| assigned_by_user_id | BIGINT UNSIGNED NOT NULL | User phân công. |
| status | VARCHAR(50) NOT NULL DEFAULT 'pending' | Trạng thái. |
| due_at | TIMESTAMP NULL | Hạn chấm. |
| metadata | JSON NULL | Audit metadata. |
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Thời điểm cập nhật. |

## Indexes

```sql
INDEX (customer_id);
INDEX (customer_id, attempt_id);
INDEX (customer_id, answer_id);
INDEX (customer_id, assigned_to_user_id, status);
INDEX (customer_id, due_at);
```

## Sample Data

`id=3300, customer_id=1, attempt_id=3000, answer_id=3101, assigned_to_user_id=201, assigned_by_user_id=200, status=pending, due_at=2026-07-03T00:00:00Z`

## Design Notes

Assignment là workflow record, không phải final grade hoặc Course completion.
