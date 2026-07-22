# Table: core_assessment_categories

## Purpose

Phân loại Assessment theo tenant bằng cây category.

## Relationships

`Customer 1 → N Categories`; `Category 1 → N Child Categories`; `Category 1 → N Question Banks`.

## Business Rules

* Category và parent phải cùng `customer_id`; `parent_id` không được tạo cycle.
* `slug` duy nhất trong tenant. Allowed `status`: `active`, `archived`.

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| parent_id | BIGINT UNSIGNED NULL | Category cha. |
| name | VARCHAR(255) NOT NULL | Tên category. |
| slug | VARCHAR(255) NOT NULL | Slug tenant-scoped. |
| description | TEXT NULL | Mô tả. |
| sort_order | INT UNSIGNED NOT NULL DEFAULT 1 | Thứ tự cùng cấp. |
| status | VARCHAR(50) NOT NULL DEFAULT 'active' | Trạng thái. |
| metadata | JSON NULL | Thuộc tính mở rộng. |
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Thời điểm cập nhật. |

## Indexes

```sql
INDEX (customer_id);
INDEX (customer_id, parent_id);
INDEX (customer_id, status);
UNIQUE (customer_id, slug);
```

## Sample Data

`id=1, customer_id=1, parent_id=NULL, name=Languages, slug=languages, sort_order=1, status=active`

## Design Notes

Category chỉ tổ chức authoring assets; không ảnh hưởng grading hoặc Course completion.
