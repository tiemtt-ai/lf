# Table Name

`core_assessment_rubrics`

## Purpose

Rubric template tái sử dụng cho grading.

## Relationships

`Customer 1 → N Rubrics`; `Rubric 1 → N Rubric Items / Gradings`.

## Business Rules

* Rubric thuộc tenant; `total_points >= 0`.
* Allowed `status`: `draft`, `active`, `archived`.
* Rubric có thể sửa ở authoring layer, nhưng grading phải snapshot criteria.
* Khi grading bắt đầu, Rubric và toàn bộ Rubric Items phải được snapshot.
* Thay đổi Rubric authoring không ảnh hưởng historical grading; historical
  grading luôn đọc Rubric Snapshot.

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| title | VARCHAR(255) NOT NULL | Tên Rubric. |
| description | TEXT NULL | Mô tả. |
| skill_type | VARCHAR(50) NULL | Skill áp dụng. |
| total_points | DECIMAL(10,2) NOT NULL DEFAULT 0.00 | Tổng điểm. |
| status | VARCHAR(50) NOT NULL DEFAULT 'draft' | Trạng thái. |
| metadata | JSON NULL | Cấu hình mở rộng. |
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Thời điểm cập nhật. |

## Indexes

```sql
INDEX (customer_id);
INDEX (customer_id, skill_type);
INDEX (customer_id, status);
```

## Sample Data

`id=4000, customer_id=1, title=Writing Rubric, skill_type=writing, total_points=20.00, status=active`

## Design Notes

Rubric authoring không phải historical grading source. Rubric là reusable
authoring source; grading snapshot là historical source.
