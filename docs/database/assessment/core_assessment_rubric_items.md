# Table: core_assessment_rubric_items

Version: 2.0

Document Status: Approved

Implementation Status: Implemented

Last Updated: 2026-11-06

Document Path: database/assessment/core_assessment_rubric_items.md

## Purpose

Tiêu chí của một rubric: tên, mô tả cách cho điểm, điểm tối đa, theo
[ADR-0026](../../adr/ADR-0026-Assessment-Rubric.md) D1.

## Relationships

`Rubric 1 → N Rubric Items`.

## Business Rules

* Item và Rubric cùng tenant.
* `max_points > 0`; `sort_order` duy nhất trong rubric, bắt đầu từ 1.
* Item chỉ được sửa qua form rubric: service ghi lại toàn bộ tiêu chí và
  `total_points` trong một transaction. Không bảng nào khác tham chiếu Item, nên
  xoá / thêm tiêu chí an toàn.
* Snapshot và kết quả chấm dùng `key` = vị trí trong snapshot, không dùng `id`
  của bảng này.

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu. |
| rubric_id | BIGINT UNSIGNED NOT NULL | Rubric cha. |
| title | VARCHAR(255) NOT NULL | Tên tiêu chí. |
| description | TEXT NULL | Mô tả cách cho điểm. |
| max_points | DECIMAL(8,2) NOT NULL | Điểm tối đa. |
| sort_order | INT UNSIGNED NOT NULL | Thứ tự. |
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Thời điểm cập nhật. |

## Constraints

```sql
CHECK (max_points > 0);
CHECK (sort_order >= 1);
```

## Indexes

```sql
UNIQUE (customer_id, rubric_id, sort_order);
```

## Foreign Keys

```sql
FOREIGN KEY (customer_id) REFERENCES saas_customers(id);
FOREIGN KEY (rubric_id, customer_id) REFERENCES core_assessment_rubrics(id, customer_id) ON DELETE CASCADE;
```

`CASCADE` theo nguyên tắc miền: tiêu chí là phần con của nguồn soạn thảo.

## Sample Data

`id=4100, customer_id=1, rubric_id=4000, title=Nội dung, max_points=12.00, sort_order=1`
`id=4101, customer_id=1, rubric_id=4000, title=Bố cục, max_points=12.00, sort_order=2`
`id=4102, customer_id=1, rubric_id=4000, title=Ngôn ngữ, max_points=26.00, sort_order=3`

## Design Notes

So với ADR-0003: bỏ `metadata` (mức điểm trong tiêu chí làm sau); `max_points`
phải dương vì tiêu chí 0 điểm không có nghĩa khi quy đổi.
