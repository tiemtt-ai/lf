# Table Name

`saas_plans`

## Purpose

Global product-plan catalog của SaaS Commercial Domain.

## Relationships

`Plan 1 → N Plan Features`; một Plan có thể được nhiều tenant-scoped
Subscriptions tham chiếu.

## Business Rules

* Plan thuộc Global Catalog Exception, dùng chung toàn platform và không có
  `customer_id`.
* Plan không chứa Customer-specific business state.
* Exception này không cho phép bất kỳ business table nào khác bỏ
  `customer_id`.
* `code` là stable lowercase `snake_case` identity và globally unique.
* Allowed `billing_type`: `free`, `recurring`, `one_time`.
* Allowed `status`: `draft`, `active`, `inactive`, `archived`.
* Plan không chứa Customer assignment, Usage, price calculation, Invoice hoặc
  Payment state.
* Active Plan không được đổi nghĩa âm thầm.
* Catalog change không retroactively thay đổi effective Entitlement.
* Metadata không được chứa price, Usage hoặc canonical lifecycle state.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính của Plan. |
| code | VARCHAR(100) NOT NULL | Stable global Plan code. |
| name | VARCHAR(255) NOT NULL | Tên hiển thị. |
| description | TEXT NULL | Mô tả Plan. |
| billing_type | VARCHAR(50) NOT NULL | Free, recurring hoặc one-time classification. |
| status | VARCHAR(50) NOT NULL DEFAULT 'draft' | Catalog lifecycle. |
| metadata | JSON NULL | Non-canonical catalog metadata. |
| created_at | TIMESTAMP NULL | Created time. |
| updated_at | TIMESTAMP NULL | Last catalog update time. |

## Indexes

```sql
PRIMARY KEY (id);
UNIQUE (code);
INDEX (status);
INDEX (billing_type, status);
```

## Sample Data

`id=3, code=professional, name=Professional, billing_type=recurring, status=active`

## Design Notes

This table and `saas_plan_features` are the only Global Catalog Exception
approved by SaaS Commercial Foundation. The exception is not precedent for
other Domains or tables. Plan visibility does not grant Customer access.
Billing owns pricing and amount-due calculation; `billing_type` is
classification only.
