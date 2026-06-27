# Table Name

`core_assessment_topics`

## Purpose

Topic/knowledge tag dạng cây cho Question.

## Relationships

`Customer 1 → N Topics`; `Topic 1 → N Child Topics`; `Topic N ↔ N Questions`.

## Business Rules

* Topic và parent phải cùng tenant; cây không được cycle.
* `slug` duy nhất trong tenant. Allowed `status`: `active`, `archived`.

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| parent_id | BIGINT UNSIGNED NULL | Topic cha. |
| name | VARCHAR(255) NOT NULL | Tên topic. |
| slug | VARCHAR(255) NOT NULL | Slug tenant-scoped. |
| description | TEXT NULL | Mô tả. |
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

`id=20, customer_id=1, parent_id=NULL, name=Grammar, slug=grammar, status=active`

## Design Notes

Topic phục vụ authoring/search/analytics, không tự quyết định score hoặc completion.
