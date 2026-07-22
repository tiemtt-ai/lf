# Table: media_categories

## Purpose

Phân loại nghiệp vụ cho Digital Assets. Category không phải storage folder.

## Relationships

`Customer 1 → N Media Categories`; `Category 1 → N Child Categories`; `Category 1 → N Media Files`.

## Business Rules

* Category và parent phải cùng `customer_id`; hierarchy không được cycle.
* Category không quản lý bucket, prefix hoặc `storage_key`.
* `slug` duy nhất trong tenant. Allowed `status`: `active`, `archived`.

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| parent_id | BIGINT UNSIGNED NULL | Category cha. |
| name | VARCHAR(255) NOT NULL | Tên category. |
| slug | VARCHAR(255) NOT NULL | Slug tenant-scoped. |
| description | TEXT NULL | Mô tả nghiệp vụ. |
| icon | VARCHAR(100) NULL | Icon key hiển thị. |
| color | VARCHAR(32) NULL | Màu hiển thị. |
| sort_order | INT UNSIGNED NOT NULL DEFAULT 1 | Thứ tự cùng cấp. |
| status | VARCHAR(50) NOT NULL DEFAULT 'active' | Lifecycle. |
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

`id=1, customer_id=1, parent_id=NULL, name=Learning Videos, slug=learning-videos, icon=video, color=#2563EB, sort_order=1, status=active`

## Design Notes

S3 hierarchy chỉ do `storage_key` quyết định; không tạo `media_folders`.
