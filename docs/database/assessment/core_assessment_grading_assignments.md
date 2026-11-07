# Table: core_assessment_grading_assignments

Version: 1.0

Document Status: Approved

Implementation Status: Implemented

Last Updated: 2026-11-07

Document Path: database/assessment/core_assessment_grading_assignments.md

> Bỏ hoãn theo [ADR-0027](../../adr/ADR-0027-Assessment-Grader-Assignment-And-Essay-Files.md)
> D3 (Approved 2026-11-07). Thiết kế này thu gọn thiết kế đích của ADR-0003.

## Purpose

Ai đang giữ một bài làm để chấm ("Giao chấm", "Nhận chấm") và lịch sử giao.

## Relationships

`Attempt 1 → N Assignments`; `User 1 → N Assignments` (người giữ, người giao).

## Business Rules

* Attempt, người giữ, người giao cùng tenant (service kiểm `users.customer_id`).
* Mỗi Attempt có tối đa một dòng `active`: database chặn bằng cột sinh
  `active_flag` + unique (như `gradings.current_flag`). Giao / nhận / trả lại
  chạy trong transaction có `lockForUpdate` trên Attempt; giao người mới thì
  dòng `active` cũ chuyển `released`.
* `assignment_type`: `assigned` (admin hoặc người tạo đề giao, `assigned_by_user_id` = người giao),
  `claimed` (giáo viên tự nhận, `assigned_by_user_id` = chính họ).
  Từ ADR-0027 Amendment 1.2 không ghi mới `assigned` (bỏ giao từng bài); chỉ còn `claimed`.
* `status`: `active` (đang giữ), `released` (bị đổi người hoặc trả lại),
  `completed` (Attempt chấm xong khi đang giữ). `ended_at` có giá trị khi và
  chỉ khi `status <> active`.
* Chỉ giao được Attempt `submitted` hoặc `graded`. Người giữ phải là `teacher`
  đang hoạt động; admin và người tạo đề không cần giữ bài để chấm.
* Khi lần chấm cuối làm Attempt sang `graded`, dòng `active` chuyển `completed`
  trong cùng transaction. Người của dòng `active` hoặc `completed` mới nhất được
  xem và chấm lại Attempt (ADR-0027 D1 tầng 3).
* Chấm lại một bài đã `graded` không mở lại assignment.

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| attempt_id | BIGINT UNSIGNED NOT NULL | Bài làm. |
| assigned_to_user_id | BIGINT UNSIGNED NOT NULL | Giáo viên giữ bài. |
| assigned_by_user_id | BIGINT UNSIGNED NOT NULL | Người giao (hoặc chính người nhận). |
| assignment_type | VARCHAR(20) NOT NULL | `assigned`, `claimed`. |
| status | VARCHAR(20) NOT NULL DEFAULT 'active' | `active`, `released`, `completed`. |
| ended_at | TIMESTAMP NULL | Lúc chuyển khỏi `active`. |
| ended_by_user_id | BIGINT UNSIGNED NULL | Người đổi / trả lại; NULL khi `completed`. |
| created_at | TIMESTAMP NULL | Lúc giao / nhận. |
| updated_at | TIMESTAMP NULL | Thời điểm cập nhật. |
| active_flag | TINYINT NULL (stored) | `CASE WHEN status = 'active' THEN 1 END`, cho unique một dòng active. |

## Constraints

```sql
CHECK (assignment_type IN ('assigned','claimed'));
CHECK (status IN ('active','released','completed'));
CHECK ((status = 'active') = (ended_at IS NULL));
```

## Indexes

```sql
UNIQUE (customer_id, attempt_id, active_flag);
INDEX (customer_id, attempt_id, status);
INDEX (customer_id, assigned_to_user_id, status);
```

## Foreign Keys

```sql
FOREIGN KEY (customer_id) REFERENCES saas_customers(id);
FOREIGN KEY (attempt_id, customer_id) REFERENCES core_assessment_attempts(id, customer_id);
FOREIGN KEY (assigned_to_user_id) REFERENCES users(id);
FOREIGN KEY (assigned_by_user_id) REFERENCES users(id);
FOREIGN KEY (ended_by_user_id) REFERENCES users(id);
```

## Sample Data

`id=3300, customer_id=1, attempt_id=3000, assigned_to_user_id=201, assigned_by_user_id=1, assignment_type=assigned, status=active`

## Design Notes

So với ADR-0003: bỏ `answer_id` (giao theo cả bài), `due_at`, `metadata`; trạng
thái `pending`/`in_progress`/`cancelled` thay bằng `active`/`released`; thêm
`assignment_type`, `ended_at`, `ended_by_user_id`. "Một dòng active mỗi Attempt"
dùng cột sinh + unique vì MariaDB không có unique một phần. Assignment là bản ghi công việc, không phải điểm.
