# Table: saas_teacher_permission_groups

Version: 1.0

Document Status: Approved

Implementation Status: Not Implemented

Last Updated: 2026-11-17

Document Path: database/saas/saas_teacher_permission_groups.md

## Purpose

Nhóm quyền của giáo viên trong một trung tâm: một tên và một tập quyền mà quản trị gán cho
giáo viên. Nghiệp vụ nằm ở
[ADR-0033](../../adr/ADR-0033-Teacher-Permissions-And-Tenant-Owner.md) D3–D4 (Owner duyệt
nghiệp vụ 2026-11-17).

Owner duyệt thiết kế dữ liệu ngày 2026-11-17. Chưa có migration: Owner được hỏi trước khi bắt đầu code.

## Relationships

* `Customer 1 → N Permission Groups`.
* `Permission Group 1 → N Grants` ([saas_teacher_permission_grants](saas_teacher_permission_grants.md)).
* `Permission Group 1 → N Users` qua `users.teacher_permission_group_id`.

## Business Rules

* Mỗi nhóm thuộc đúng một trung tâm (`customer_id`).
* Mỗi trung tâm có đúng một nhóm mặc định: `is_default` chỉ nhận `1` hoặc `NULL`, và
  `(customer_id, is_default)` là duy nhất (cùng cách với
  `core_liveclass_attendance_policies`).
* Nhóm mặc định mang các quyền "Bật" của ADR-0033 D3. Migration tạo nhóm mặc định cho mọi
  trung tâm đang có; trung tâm tạo sau được tạo nhóm mặc định ở lần đầu cần đọc.
* Tên là duy nhất trong một trung tâm.
* Không xoá được nhóm mặc định và nhóm đang có giáo viên, kể cả giáo viên đã khoá (khoá
  ngoại `RESTRICT`). Xoá là
  xoá thật; các dòng quyền của nhóm bị xoá theo.
* Bảng không lưu lịch sử; mỗi lần đổi ghi `saas_audit_logs`.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khoá chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Trung tâm sở hữu. |
| name | VARCHAR(150) NOT NULL | Tên nhóm, ví dụ "Trợ giảng". |
| is_default | TINYINT(1) NULL | `1` là nhóm mặc định; `NULL` là nhóm thường. |
| created_by | BIGINT UNSIGNED NULL | Người tạo. `NULL` khi hệ thống tạo nhóm mặc định. |
| updated_by | BIGINT UNSIGNED NULL | Người sửa sau cùng. |
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Thời điểm sửa sau cùng. |

## Constraints

```sql
PRIMARY KEY (id);
UNIQUE (customer_id, name);
UNIQUE (customer_id, is_default);
UNIQUE (id, customer_id);

FOREIGN KEY (customer_id) REFERENCES saas_customers (id) ON UPDATE RESTRICT ON DELETE RESTRICT;
FOREIGN KEY (created_by) REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT;
FOREIGN KEY (updated_by) REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT;

CHECK (is_default = 1);
```

## Column On `users`

`users` chưa có tài liệu bảng riêng, nên cột mới được mô tả ở đây.

```text
users.teacher_permission_group_id BIGINT UNSIGNED NULL

FOREIGN KEY (teacher_permission_group_id, customer_id)
    REFERENCES saas_teacher_permission_groups (id, customer_id)
    ON UPDATE RESTRICT ON DELETE RESTRICT;
INDEX (teacher_permission_group_id, customer_id);
```

* `NULL` nghĩa là nhóm mặc định của trung tâm, nên giáo viên đang có không cần chuyển dữ
  liệu.
* Khoá ngoại kép giữ giáo viên trong nhóm của chính trung tâm mình.
* Chỉ có nghĩa với `role = teacher`; ứng dụng không ghi cột này cho vai trò khác, xoá nó
  về `NULL` trong cùng thao tác đổi vai trò, và bỏ qua giá trị của nó khi vai trò không
  phải giáo viên.

## Sample Data

`id=1, customer_id=1, name='Mặc định', is_default=1`

`id=2, customer_id=1, name='Trợ giảng', is_default=NULL`

## Design Notes

* Hai bảng thay vì một cột JSON: mỗi quyền là một dòng, nên thêm quyền mới không phải sửa
  dữ liệu cũ và truy vấn "ai có quyền X" không cần đọc JSON.
* Khi `saas_customer_members` được triển khai, cột nhóm quyền chuyển sang Membership cùng
  lúc với vai trò (ADR-0033 § Consequences).
