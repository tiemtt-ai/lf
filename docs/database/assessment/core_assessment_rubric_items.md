# Table: core_assessment_rubric_items

## Purpose

Tiêu chí chấm điểm thuộc Rubric template.

## Relationships

`Rubric 1 → N Rubric Items`; mỗi Item thuộc Customer.

## Business Rules

* Item và Rubric phải cùng tenant; `max_points >= 0`.
* `sort_order` duy nhất trong Rubric.
* Tổng `max_points` của Items phải khớp Rubric `total_points` khi activate.
* Khi grading bắt đầu, phải snapshot Rubric và toàn bộ Rubric Items.
* Item thay đổi sau đó không ảnh hưởng bài đã chấm; historical grading luôn đọc
  Rubric Snapshot.

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| rubric_id | BIGINT UNSIGNED NOT NULL | Rubric cha. |
| title | VARCHAR(255) NOT NULL | Tên criterion. |
| description | TEXT NULL | Mô tả criterion. |
| max_points | DECIMAL(8,2) NOT NULL DEFAULT 0.00 | Điểm tối đa. |
| sort_order | INT UNSIGNED NOT NULL DEFAULT 1 | Thứ tự. |
| metadata | JSON NULL | Levels/weights mở rộng. |
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Thời điểm cập nhật. |

## Indexes

```sql
INDEX (customer_id);
INDEX (customer_id, rubric_id);
UNIQUE (customer_id, rubric_id, sort_order);
```

## Sample Data

`id=4100, customer_id=1, rubric_id=4000, title=Grammar, max_points=5.00, sort_order=1`

## Design Notes

Rubric Item authoring không phải historical grading source. Rubric levels có
thể ở metadata trong Foundation; nếu cần analytics sâu, tách table ở phase sau.
