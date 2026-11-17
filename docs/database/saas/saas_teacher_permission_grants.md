# Table: saas_teacher_permission_grants

Version: 1.1

Document Status: Approved

Implementation Status: Implemented

Last Updated: 2026-11-17

Document Path: database/saas/saas_teacher_permission_grants.md

## Purpose

Các quyền mà một nhóm quyền của giáo viên được cấp: mỗi dòng là một quyền của một nhóm.
Nghiệp vụ nằm ở
[ADR-0033](../../adr/ADR-0033-Teacher-Permissions-And-Tenant-Owner.md) D3–D4 (Owner duyệt
nghiệp vụ 2026-11-17).

Owner duyệt thiết kế dữ liệu ngày 2026-11-17. Migration:
`2026_11_17_000400_create_teacher_permissions`.

## Relationships

`Permission Group 1 → N Grants`
([saas_teacher_permission_groups](saas_teacher_permission_groups.md)).

## Business Rules

* Có dòng nghĩa là nhóm có quyền đó; không có dòng nghĩa là không có.
* `permission_key` phải thuộc danh mục quyền của ADR-0033 D3. Danh mục nằm trong code và
  do ứng dụng kiểm tra; database không giữ danh sách này, để thêm quyền mới không cần
  migration. Một khoá không còn trong danh mục bị bỏ qua khi đọc.
* Mỗi quyền xuất hiện tối đa một lần trong một nhóm.
* Lưu tập quyền của một nhóm là thay toàn bộ các dòng của nhóm đó trong một transaction.
* `customer_id` phải trùng với nhóm; khoá ngoại kép bảo đảm điều này.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khoá chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Trung tâm sở hữu. |
| permission_group_id | BIGINT UNSIGNED NOT NULL | Nhóm được cấp quyền. |
| permission_key | VARCHAR(100) NOT NULL | Khoá quyền, ví dụ `class.manage`. |
| created_at | TIMESTAMP NULL | Thời điểm cấp. |

## Constraints

```sql
PRIMARY KEY (id);
UNIQUE uk_stpgr_group_key (permission_group_id, permission_key);
INDEX idx_stpgr_customer_key (customer_id, permission_key);

FOREIGN KEY fk_stpgr_customer (customer_id) REFERENCES saas_customers (id) ON UPDATE RESTRICT ON DELETE RESTRICT;
FOREIGN KEY fk_stpgr_group (permission_group_id, customer_id)
    REFERENCES saas_teacher_permission_groups (id, customer_id)
    ON UPDATE RESTRICT ON DELETE CASCADE;
```

## Sample Data

`id=1, customer_id=1, permission_group_id=1, permission_key='content.template.create'`

`id=2, customer_id=1, permission_group_id=1, permission_key='class.operate'`

## Design Notes

Nhóm mặc định được tạo kèm bốn dòng cho bốn quyền "Bật" của ADR-0033 D3.
