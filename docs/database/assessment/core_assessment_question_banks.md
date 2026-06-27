# Table Name

`core_assessment_question_banks`

## Purpose

Kho câu hỏi nguồn do tenant và owner quản lý.

## Relationships

`Customer 1 → N Banks`; `Category 1 → N Banks`; `User 1 → N Owned Banks`; `Bank 1 → N Questions`.

## Business Rules

* Bank, Category và owner phải cùng tenant.
* Allowed `visibility`: `private`, `organization`, `shared`; visibility không thay authorization.
* Allowed `status`: `draft`, `active`, `archived`.

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| category_id | BIGINT UNSIGNED NULL | Category phân loại. |
| owner_user_id | BIGINT UNSIGNED NOT NULL | User sở hữu Bank. |
| title | VARCHAR(255) NOT NULL | Tên Bank. |
| description | TEXT NULL | Mô tả. |
| visibility | VARCHAR(50) NOT NULL DEFAULT 'private' | Phạm vi sử dụng. |
| status | VARCHAR(50) NOT NULL DEFAULT 'draft' | Trạng thái. |
| metadata | JSON NULL | Thuộc tính mở rộng. |
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Thời điểm cập nhật. |

## Indexes

```sql
INDEX (customer_id);
INDEX (customer_id, category_id);
INDEX (customer_id, owner_user_id);
INDEX (customer_id, visibility);
INDEX (customer_id, status);
```

## Sample Data

`id=10, customer_id=1, category_id=1, owner_user_id=200, title=TOPIK Bank, visibility=organization, status=active`

## Design Notes

Question Bank là authoring source; Quiz snapshot bảo vệ lịch sử khi Bank thay đổi.
