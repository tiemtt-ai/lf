# Table: core_assessment_categories

Version: 2.0

Document Status: Approved

Implementation Status: Partial

Last Updated: 2026-11-06

Document Path: database/assessment/core_assessment_categories.md

## Purpose

Cây danh mục của tenant, dùng làm "nhóm câu hỏi" duy nhất ở giai đoạn 1 theo
[ADR-0025](../../adr/ADR-0025-Assessment-Simple-Administration.md) D3. Gắn trực
tiếp vào câu hỏi, ngữ liệu và đề. Sau này đề động chọn câu theo danh mục.

## Relationships

`Customer 1 → N Categories`; `Category 1 → N Child Categories`;
`Category 1 → N Questions / Passages / Quizzes`.

## Business Rules

* Category và parent phải cùng `customer_id`; `parent_id` không được tạo cycle.
* `slug` duy nhất trong tenant. Allowed `status`: `active`, `archived`.
* Chỉ `customer_admin` tạo, sửa, lưu trữ danh mục; giáo viên chỉ chọn danh mục
  `active`.
* Danh mục đang được câu hỏi, ngữ liệu, đề hoặc danh mục con dùng chỉ được lưu
  trữ, không xoá cứng.
* Danh mục chỉ để tổ chức và lọc; không ảnh hưởng chấm điểm.

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| parent_id | BIGINT UNSIGNED NULL | Danh mục cha. |
| name | VARCHAR(255) NOT NULL | Tên danh mục. |
| slug | VARCHAR(255) NOT NULL | Slug tenant-scoped. |
| description | TEXT NULL | Mô tả. |
| sort_order | INT UNSIGNED NOT NULL DEFAULT 1 | Thứ tự cùng cấp. |
| status | VARCHAR(20) NOT NULL DEFAULT 'active' | Trạng thái. |
| metadata | JSON NULL | Thuộc tính mở rộng. |
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Thời điểm cập nhật. |

## Constraints

```sql
CHECK (status IN ('active','archived'));
```

MariaDB không cho CHECK tham chiếu cột `AUTO_INCREMENT` (lỗi 1901), nên việc chặn
danh mục làm cha của chính nó và chặn vòng lặp cây do service kiểm tra.

## Indexes

```sql
INDEX (customer_id, parent_id);
INDEX (customer_id, status);
UNIQUE (customer_id, slug);
UNIQUE (id, customer_id);
```

## Foreign Keys

```sql
FOREIGN KEY (customer_id) REFERENCES saas_customers(id);
FOREIGN KEY (parent_id, customer_id) REFERENCES core_assessment_categories(id, customer_id);
```

## Sample Data

`id=1, customer_id=1, parent_id=NULL, name=TOPIK I, slug=topik-i, sort_order=1, status=active`
`id=3, customer_id=1, parent_id=1, name=Đọc hiểu, slug=topik-i-doc-hieu, sort_order=2, status=active`

## Design Notes

ADR-0003 gắn danh mục vào Question Bank; ADR-0025 hoãn Question Bank nên danh
mục gắn thẳng vào câu hỏi, ngữ liệu và đề. Khoá ngoại kép `(id, customer_id)`
theo cùng mẫu Media để chặn tham chiếu chéo tenant ở database.
