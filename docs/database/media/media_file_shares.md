# Table: media_file_shares

Version: 1.0

Document Status: Approved

Implementation Status: Implemented

Last Updated: 2026-10-21

Document Path: database/media/media_file_shares.md

## Purpose

Quan hệ chia sẻ Media File cho giáo viên trong cùng tenant, theo
[ADR-0021](../../adr/ADR-0021-Media-Sharing-Within-Tenant.md) (Approved bởi
Owner 2026-10-21; Architecture Review riêng được Owner miễn).

## Relationships

`Media File 1 → N Shares`; mỗi share trỏ tới một người nhận (`user`) hoặc một
nhóm vai trò (`role`).

## Business Rules

* Share và Media File phải cùng tenant; người nhận (`grantee_user_id`) phải
  thuộc tenant đó và có vai trò `teacher`.
* Chỉ chủ sở hữu (`media_files.uploaded_by`) hoặc `customer_admin` tạo và thu
  hồi share. Admin không cần share để thấy Media.
* Allowed `grantee_type`: `user`, `role`. Với `user`: `grantee_user_id` có giá
  trị, `grantee_role` là NULL. Với `role`: `grantee_role` có giá trị (chỉ
  `teacher` trong phạm vi hiện tại), `grantee_user_id` là NULL. Share theo
  `role` áp cho mọi giáo viên của tenant, kể cả giáo viên thêm sau.
* Tạo share idempotent theo (media, người nhận); tạo lại share đã thu hồi tạo
  dòng mới (dòng đã thu hồi giữ nguyên làm audit).
* Thu hồi đặt `revoked_at`, không xoá hàng. Thu hồi bị từ chối khi người nhận
  (hoặc bất kỳ giáo viên, với share `role`) còn `media_file_usages` đang
  `active` của Media đó; transaction khoá hàng share và kiểm tra usage cùng lúc.
* Một share còn hiệu lực là hàng có `revoked_at IS NULL`.
* Share không đổi quyền sửa, xoá hay quyền phát nội dung (xem ADR-0021 D4, D5).
* Không có share cho Media đã xoá (`media_files.status = deleted`).

## Fields

| Field | Type | Meaning |
|---|---|---|
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Khóa chính. |
| customer_id | BIGINT UNSIGNED NOT NULL | Tenant sở hữu share. |
| media_file_id | BIGINT UNSIGNED NOT NULL | Media File được chia sẻ. |
| grantee_type | VARCHAR(20) NOT NULL | `user` hoặc `role`. |
| grantee_user_id | BIGINT UNSIGNED NULL | Người nhận khi `grantee_type = user`. |
| grantee_role | VARCHAR(50) NULL | Vai trò nhận khi `grantee_type = role` (`teacher`). |
| grantee_key | VARCHAR(80) NOT NULL | `u:<user id>` hoặc `r:<role>`; ứng dụng ghi, làm khoá duy nhất của người nhận. |
| shared_by | BIGINT UNSIGNED NOT NULL | User tạo share. |
| revoked_by | BIGINT UNSIGNED NULL | User thu hồi. |
| revoked_at | TIMESTAMP NULL | NULL = còn hiệu lực. |
| created_at | TIMESTAMP NULL | Thời điểm tạo. |
| updated_at | TIMESTAMP NULL | Thời điểm cập nhật. |
| active_flag | TINYINT STORED GENERATED | `1` khi `revoked_at IS NULL`, ngược lại NULL; chỉ để làm khoá duy nhất. |

## Constraints

```sql
CHECK (grantee_type IN ('user','role'));
CHECK (
  (grantee_type = 'user' AND grantee_user_id IS NOT NULL AND grantee_role IS NULL)
  OR (grantee_type = 'role' AND grantee_role IS NOT NULL AND grantee_user_id IS NULL)
);
CHECK (grantee_role IS NULL OR grantee_role IN ('teacher'));
```

## Indexes

```sql
INDEX (customer_id, media_file_id);
INDEX (customer_id, grantee_user_id, revoked_at);
INDEX (customer_id, grantee_role, revoked_at);
UNIQUE (id, customer_id);
UNIQUE (customer_id, media_file_id, grantee_key, active_flag);
```

MariaDB không có unique index một phần, nên một share còn hiệu lực là duy nhất nhờ
`active_flag` (NULL khi đã thu hồi, mà NULL không bao giờ xung đột): có thể có
nhiều dòng đã thu hồi cho cùng người nhận, và nhiều nhất một dòng còn hiệu lực.
Tạo lại share đã thu hồi tạo dòng mới.

## Foreign Keys

```sql
FOREIGN KEY (customer_id) REFERENCES saas_customers(id);
FOREIGN KEY (media_file_id, customer_id) REFERENCES media_files(id, customer_id);
FOREIGN KEY (grantee_user_id) REFERENCES users(id);
FOREIGN KEY (shared_by) REFERENCES users(id);
FOREIGN KEY (revoked_by) REFERENCES users(id);
```

Khoá kép `(media_file_id, customer_id)` cùng mẫu với các bảng Media khác. Việc
người nhận cùng tenant và có vai trò `teacher` do ứng dụng kiểm tra (bảng `users`
không có khoá kép để tham chiếu), cần xác nhận ở Review.

## Sample Data

`id=1, customer_id=1, media_file_id=100, grantee_type=user, grantee_user_id=21, shared_by=20`
`id=2, customer_id=1, media_file_id=101, grantee_type=role, grantee_role=teacher, shared_by=20`

## Design Notes

Bảng chỉ biểu diễn quyền xem và dùng Media trong thư viện. Nó không thay
`media_file_usages` (nơi dùng) và không thay `media_files.visibility`. Truy vấn
thư viện của giáo viên là Media của mình ∪ Media có share còn hiệu lực cho mình
(trực tiếp hoặc theo vai trò).
