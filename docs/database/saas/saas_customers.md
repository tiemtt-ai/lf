# Table: saas_customers

Document Path: database/saas/saas_customers.md

## Purpose

Tenant/Customer root identity and lifecycle record.

## Tenant Owner Amendment — 2026-11-17 (Approved, chưa có migration)

[ADR-0033](../../adr/ADR-0033-Teacher-Permissions-And-Tenant-Owner.md) D2 (Owner duyệt
nghiệp vụ 2026-11-17). Thêm cột `owner_user_id`. Owner duyệt schema ngày 2026-11-17; khoá ngoại ghép dưới đây
thay khoá ngoại đơn sau lượt rà soát và được Owner duyệt lại cùng ngày.

```text
owner_user_id BIGINT UNSIGNED NULL
INDEX idx_sc_owner_user (owner_user_id, id)
FOREIGN KEY fk_sc_owner_user (owner_user_id, id) REFERENCES users (id, customer_id)
    ON UPDATE RESTRICT ON DELETE RESTRICT
```

* Khoá ngoại ghép dựa trên `uk_users_id_customer` đã có: schema tự bảo đảm Chủ trung tâm
  là người dùng của chính trung tâm đó. Vai trò và trạng thái vẫn do ứng dụng kiểm tra.

* Chủ trung tâm: `customer_admin` đang hoạt động duy nhất được quản lý các quản trị khác và
  được chuyển vai trò này.
* Migration điền quản trị đang hoạt động được tạo sớm nhất của từng trung tâm. `NULL` chỉ
  còn ở trung tâm chưa có quản trị nào; ứng dụng coi quản trị đầu tiên được tạo là Chủ
  trung tâm và ghi cột này lúc đó.
* Ứng dụng bảo đảm người được ghi có vai trò `customer_admin` và đang hoạt động; không
  khoá và không hạ vai trò người này. Luồng đăng ký trung tâm ghi cột này trong cùng
  transaction tạo quản trị đầu tiên.
* Đổi Chủ trung tâm khi người này mất quyền truy cập do đội vận hành LF làm ngoài ứng
  dụng, có ghi `saas_audit_logs`.
* Mỗi lần chuyển ghi `saas_audit_logs`.

## Relationships

`Customer 1 → N Settings / Domains / Members / Invitations / Audit Logs`;
`saas_customers.id` is referenced as `customer_id` by business tables.

## Business Rules

* This is the Tenant root and does not contain `customer_id`.
* `slug` is globally unique and stable.
* `subdomain`, when present, is unique.
* Allowed `status`: `active`, `inactive`, `suspended`, `archived`.
* Do not store Billing, Subscription, Usage or learning state here.
* `subdomain`/`custom_domain` are compatibility/bootstrap fields;
  `saas_customer_domains` is canonical for routing.
* Metadata cannot replace settings requiring query/constraint.

## Fields

| Field | Type | Meaning |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK AUTO_INCREMENT | Customer/Tenant identity. |
| name | VARCHAR(255) NOT NULL | Organization display name. |
| slug | VARCHAR(100) NOT NULL | Stable global tenant slug. |
| subdomain | VARCHAR(255) NULL | Compatibility primary subdomain value. |
| custom_domain | VARCHAR(255) NULL | Compatibility primary custom domain value. |
| organization_type | VARCHAR(100) NULL | Organization classification. |
| theme_key | VARCHAR(100) NULL | Default theme key. |
| layout_key | VARCHAR(100) NULL | Default layout key. |
| email | VARCHAR(255) NULL | Tenant contact email. |
| phone | VARCHAR(50) NULL | Tenant contact phone. |
| status | VARCHAR(50) NOT NULL DEFAULT 'active' | Tenant lifecycle. |
| metadata | JSON NULL | Non-canonical extension metadata. |
| created_at | TIMESTAMP NULL | Created time. |
| updated_at | TIMESTAMP NULL | Lifecycle/profile update time. |

## Indexes

```sql
UNIQUE (slug);
UNIQUE (subdomain);
INDEX (status);
```

## Sample Data

`id=1, name=KAHA Academy, slug=kaha, subdomain=kaha.learnforge.vn, custom_domain=NULL, organization_type=academy, theme_key=korean, layout_key=academy_default, email=admin@kaha.vn, status=active`

## Design Notes

Routing must migrate toward `saas_customer_domains`. Root compatibility fields
must never drift from the primary active Domain registry record.
