# Table: core_assessment_quiz_sections

Version: 2.0

Document Status: Approved

Implementation Status: Partial

Last Updated: 2026-11-06

Document Path: database/assessment/core_assessment_quiz_sections.md

## Purpose

Các phần của bản nháp đề, ví dụ Nghe, Đọc, Viết, theo
[ADR-0025](../../adr/ADR-0025-Assessment-Simple-Administration.md) D6.

## Relationships

`Quiz 1 → N Sections`; `Section 1 → N Quiz Questions`.

## Business Rules

* Section và Quiz phải cùng tenant.
* Mỗi đề có ít nhất một phần. Đề mới tạo kèm một phần mặc định; khi đề chỉ có
  một phần, giao diện ẩn khái niệm phần.
* `sort_order` duy nhất trong đề.
* Đây là cấu trúc soạn thảo, sửa được. Khi xuất bản, phần được chép vào
  `core_assessment_quiz_versions.sections_snapshot`.
* Xoá phần chỉ khi phần không còn câu.

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| quiz_id | BIGINT UNSIGNED NOT NULL | Đề. |
| title | VARCHAR(255) NOT NULL | Tên phần. |
| description | TEXT NULL | Hướng dẫn của phần. |
| skill_type | VARCHAR(50) NULL | Kỹ năng của phần. |
| sort_order | INT UNSIGNED NOT NULL DEFAULT 1 | Thứ tự. |
| metadata | JSON NULL | Cấu hình mở rộng. |
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Thời điểm cập nhật. |

## Constraints

```sql
CHECK (skill_type IS NULL OR skill_type IN ('reading','listening','writing','speaking','other'));
```

## Indexes

```sql
INDEX (customer_id, quiz_id);
UNIQUE (customer_id, quiz_id, sort_order);
UNIQUE (id, customer_id);
```

## Foreign Keys

```sql
FOREIGN KEY (customer_id) REFERENCES saas_customers(id);
FOREIGN KEY (quiz_id, customer_id) REFERENCES core_assessment_quizzes(id, customer_id) ON DELETE CASCADE;
```

## Sample Data

`id=2100, customer_id=1, quiz_id=2000, title=Đọc hiểu, skill_type=reading, sort_order=1`

## Design Notes

So với ADR-0003: phần không còn bất biến; bỏ `duration_minutes` (giới hạn theo
từng phần để sau) và `total_points` (tính từ câu).
