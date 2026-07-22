# Table: core_assessment_quiz_sections

## Purpose

Phân phần trong Quiz như Listening, Reading hoặc Writing.

## Relationships

`Quiz 1 → N Sections`; `Section 1 → N Quiz Questions`.

## Business Rules

* Section và Quiz phải cùng tenant.
* `sort_order` duy nhất trong Quiz; duration/points không âm.
* Section của published Quiz là immutable.

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| quiz_id | BIGINT UNSIGNED NOT NULL | Quiz cha. |
| title | VARCHAR(255) NOT NULL | Tên phần. |
| description | TEXT NULL | Mô tả. |
| skill_type | VARCHAR(50) NULL | Skill đánh giá. |
| sort_order | INT UNSIGNED NOT NULL DEFAULT 1 | Thứ tự. |
| duration_minutes | INT UNSIGNED NULL | Giới hạn phần. |
| total_points | DECIMAL(10,2) NOT NULL DEFAULT 0.00 | Tổng điểm phần. |
| metadata | JSON NULL | Cấu hình mở rộng. |
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Thời điểm cập nhật. |

## Indexes

```sql
INDEX (customer_id);
INDEX (customer_id, quiz_id);
INDEX (customer_id, skill_type);
UNIQUE (customer_id, quiz_id, sort_order);
```

## Sample Data

`id=2100, customer_id=1, quiz_id=2000, title=Listening, skill_type=listening, sort_order=1, duration_minutes=20, total_points=30.00`

## Design Notes

Section total is validated against Quiz totals during publish; it is not Course Progress.
